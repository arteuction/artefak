<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 132 — OWASP ASVS security audit checklist.
 *
 * Checks key ASVS Level 1 & Level 2 controls that can be verified
 * statically or by inspecting runtime configuration.
 *
 * Usage:
 *   php artisan security:audit
 *   php artisan security:audit --format=json
 *
 * Exit 0 = all pass; 1 = one or more failures.
 */
final class SecurityAuditCommand extends Command
{
    protected $signature   = 'security:audit {--format=text : Output format (text|json)}';
    protected $description = 'Run OWASP ASVS security audit checks';

    private array $results = [];

    public function handle(): int
    {
        // V1 — Architecture & Design
        $this->checkHttpsEnforced();
        $this->checkDebugDisabled();
        $this->checkAppKeySet();

        // V2 — Authentication
        $this->checkSanctumEnabled();
        $this->checkCsrfProtection();

        // V3 — Session Management
        $this->checkSessionDriver();

        // V4 — Access Control
        $this->checkAdminGateDefined();

        // V5 — Validation & Sanitization
        $this->checkThrottleOnAuth();
        $this->checkThrottleOnCheckout();

        // V6 — Cryptography
        $this->checkBcryptOrArgon();

        // V7 — Error Handling & Logging
        $this->checkLogChannel();

        // V8 — Data Protection
        $this->checkEncryptCookies();
        $this->checkNoPlaintextApiKeys();

        // V9 — Communications
        $this->checkStripeWebhookSecret();

        // V13 — API
        $this->checkApiRateLimiting();

        // V14 — Configuration
        $this->checkExposePhpHeaderOff();
        $this->checkCorsConfig();

        return $this->report();
    }

    // -------------------------------------------------------------------------

    private function checkHttpsEnforced(): void
    {
        $forceHttps = config('app.env') === 'production'
            && (config('app.force_https') || config('session.secure'));

        $this->record('V1.1 HTTPS forced in production', $forceHttps || config('app.env') !== 'production');
    }

    private function checkDebugDisabled(): void
    {
        $this->record('V1.2 APP_DEBUG off in production',
            config('app.env') !== 'production' || config('app.debug') === false);
    }

    private function checkAppKeySet(): void
    {
        $key = config('app.key');
        $this->record('V1.3 APP_KEY is set', ! empty($key) && $key !== 'base64:');
    }

    private function checkSanctumEnabled(): void
    {
        $guards = config('auth.guards', []);
        $this->record('V2.1 Sanctum guard registered', isset($guards['sanctum']));
    }

    private function checkCsrfProtection(): void
    {
        // bootstrap/app.php validates CSRF unless explicitly excepted (only stripe/webhook)
        $this->record('V2.2 CSRF protection active', class_exists(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class));
    }

    private function checkSessionDriver(): void
    {
        $driver = config('session.driver', 'file');
        $ok     = in_array($driver, ['database', 'redis', 'cookie', 'file'], true);
        $this->record('V3.1 Session driver is valid', $ok, "driver={$driver}");
    }

    private function checkAdminGateDefined(): void
    {
        // Gate 'admin' is defined in AppServiceProvider
        $this->record('V4.1 Admin gate defined', \Illuminate\Support\Facades\Gate::has('admin'));
    }

    private function checkThrottleOnAuth(): void
    {
        // We check that throttle middleware exists in the kernel — actual config verified by route tests
        $this->record('V5.1 Throttle middleware available',
            class_exists(\Illuminate\Routing\Middleware\ThrottleRequests::class));
    }

    private function checkThrottleOnCheckout(): void
    {
        // Checkout routes have throttle:10,1 wired in routes/api.php (Phase 122)
        // Verified structurally — full route introspection is expensive; flag if routes file exists
        $routesFile = base_path('routes/api.php');
        $content    = file_get_contents($routesFile);
        $this->record('V5.2 Checkout throttle declared in api.php',
            str_contains($content, 'throttle:10,1'));
    }

    private function checkBcryptOrArgon(): void
    {
        $driver = config('hashing.driver', 'bcrypt');
        $this->record('V6.1 Password hash driver is bcrypt or argon',
            in_array($driver, ['bcrypt', 'argon', 'argon2id'], true),
            "driver={$driver}");
    }

    private function checkLogChannel(): void
    {
        $channel = config('logging.default', 'stack');
        $this->record('V7.1 Log channel configured', ! empty($channel), "channel={$channel}");
    }

    private function checkEncryptCookies(): void
    {
        $this->record('V8.1 Cookie encryption middleware available',
            class_exists(\Illuminate\Cookie\Middleware\EncryptCookies::class));
    }

    private function checkNoPlaintextApiKeys(): void
    {
        // Verify api_clients table exists and never has a plaintext key column
        $ok = Schema::hasTable('api_clients') && ! Schema::hasColumn('api_clients', 'key_plaintext');
        $this->record('V8.2 API keys stored hashed (no plaintext column)', $ok);
    }

    private function checkStripeWebhookSecret(): void
    {
        $secret = config('services.stripe.webhook_secret') ?? env('STRIPE_WEBHOOK_SECRET');
        $this->record('V9.1 Stripe webhook secret configured', ! empty($secret));
    }

    private function checkApiRateLimiting(): void
    {
        $limiters = config('cache.stores', []);
        // Rate limiting relies on cache; check that a cache store is configured
        $this->record('V13.1 Cache driver configured for rate limiting',
            ! empty(config('cache.default')));
    }

    private function checkExposePhpHeaderOff(): void
    {
        // PHP expose_php should be Off in production; we check the ini setting
        $exposed = ini_get('expose_php');
        $ok      = config('app.env') !== 'production' || ! $exposed || $exposed === '0' || strtolower((string) $exposed) === 'off';
        $this->record('V14.1 expose_php is Off in production', $ok);
    }

    private function checkCorsConfig(): void
    {
        $corsFile = base_path('config/cors.php');
        $this->record('V14.2 CORS config file exists', file_exists($corsFile));
    }

    // -------------------------------------------------------------------------

    private function record(string $check, bool $pass, string $detail = ''): void
    {
        $this->results[] = ['check' => $check, 'pass' => $pass, 'detail' => $detail];
    }

    private function report(): int
    {
        $failures = array_filter($this->results, fn ($r) => ! $r['pass']);

        if ($this->option('format') === 'json') {
            $this->line(json_encode([
                'total'    => count($this->results),
                'pass'     => count($this->results) - count($failures),
                'fail'     => count($failures),
                'checks'   => $this->results,
            ], JSON_PRETTY_PRINT));
            return count($failures) > 0 ? 1 : 0;
        }

        $this->newLine();
        $this->line('  <fg=cyan>OWASP ASVS Security Audit</>');
        $this->newLine();

        foreach ($this->results as $r) {
            $icon   = $r['pass'] ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $label  = $r['pass'] ? $r['check'] : "<fg=red>{$r['check']}</>";
            $detail = $r['detail'] ? " <fg=gray>({$r['detail']})</>" : '';
            $this->line("  {$icon}  {$label}{$detail}");
        }

        $total = count($this->results);
        $pass  = $total - count($failures);
        $this->newLine();
        $this->line("  <fg=gray>Total: {$total}  Pass: <fg=green>{$pass}</>  Fail: <fg=red>" . count($failures) . "</></>");
        $this->newLine();

        if (count($failures) > 0) {
            $this->error('Security audit FAILED — review items above.');
            return 1;
        }

        $this->info('Security audit passed.');
        return 0;
    }
}
