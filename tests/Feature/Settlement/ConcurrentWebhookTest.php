<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 1f — Concurrent webhook idempotency.
 *
 * 10 OS-level PHP processes all call `settlement:create-test` with the same
 * stripe_payment_intent_id simultaneously.  Exactly ONE settlement row must
 * exist when the dust settles.
 *
 * This catches the TOCTOU race that a sequential PHPUnit test cannot detect:
 * two processes both pass the SELECT guard before either commits, then one
 * wins the INSERT while the other hits the UNIQUE constraint.
 * CreateSettlement catches UniqueConstraintViolationException and returns
 * the winner's ID, so every process gets the same integer back.
 */
class ConcurrentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const PROCESSES   = 10;
    private const TIMEOUT_SEC = 30;

    public function test_ten_concurrent_webhooks_create_exactly_one_settlement(): void
    {
        $pi  = 'pi_concurrent_' . uniqid();
        $ids = $this->runParallel($pi);

        // Every process must have returned a valid integer ID
        $this->assertCount(self::PROCESSES, $ids, 'Some processes failed to return an ID');
        $this->assertNotEmpty(array_filter($ids), 'No processes returned a non-empty ID');

        // All IDs must be identical
        $unique = array_unique($ids);
        $this->assertCount(1, $unique, sprintf(
            'Expected 1 unique settlement ID, got %d: %s',
            count($unique),
            implode(', ', $unique),
        ));

        // Exactly ONE row in the DB
        $count = DB::table('settlements')
            ->where('stripe_payment_intent_id', $pi)
            ->count();

        $this->assertSame(1, $count, "Expected 1 settlement row, found {$count}");
    }

    public function test_ten_concurrent_webhooks_create_exactly_three_lines(): void
    {
        $pi  = 'pi_lines_concurrent_' . uniqid();
        $ids = $this->runParallel($pi);

        $settlementId = (int) $ids[0];

        $lineCount = DB::table('settlement_lines')
            ->where('settlement_id', $settlementId)
            ->count();

        $this->assertSame(3, $lineCount, "Expected 3 settlement_lines, found {$lineCount}");
    }

    public function test_ten_concurrent_webhooks_create_exactly_one_ledger_credit(): void
    {
        $pi  = 'pi_ledger_concurrent_' . uniqid();
        $ids = $this->runParallel($pi);

        $settlementId = (int) $ids[0];

        $ledgerCount = DB::table('ledger_entries')
            ->where('settlement_id', $settlementId)
            ->count();

        $this->assertSame(1, $ledgerCount, "Expected 1 ledger credit, found {$ledgerCount}");
    }

    // ──────────────────────────────────────────────────────────────
    // Helper
    // ──────────────────────────────────────────────────────────────

    /**
     * Spawns PROCESSES artisan processes in parallel, all with the same PI.
     * Returns array of trimmed stdout lines (one per process).
     */
    private function runParallel(string $paymentIntent): array
    {
        $artisan = base_path('artisan');
        $env     = base_path('.env.testing');

        $handles     = [];
        $pipes_list  = [];

        for ($i = 0; $i < self::PROCESSES; $i++) {
            $evt = 'evt_concurrent_' . $i . '_' . uniqid();
            $cmd = sprintf(
                'php %s settlement:create-test %s %s --env=testing',
                escapeshellarg($artisan),
                escapeshellarg($paymentIntent),
                escapeshellarg($evt),
            );

            $handles[$i] = proc_open(
                $cmd,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes_list[$i],
                base_path(),
                // Pass APP_ENV so Laravel loads .env.testing
                array_merge($_ENV, ['APP_ENV' => 'testing']),
            );
        }

        $ids    = [];
        $errors = [];

        foreach ($handles as $i => $handle) {
            $stdout = trim(stream_get_contents($pipes_list[$i][1]));
            $stderr = trim(stream_get_contents($pipes_list[$i][2]));
            fclose($pipes_list[$i][1]);
            fclose($pipes_list[$i][2]);
            proc_close($handle);

            if ($stdout !== '') {
                $ids[] = $stdout;
            }
            if ($stderr !== '') {
                $errors[] = "Process {$i}: {$stderr}";
            }
        }

        if (!empty($errors)) {
            // Surface stderr only when we fail, for easier diagnosis
            $this->addWarning(implode("\n", array_slice($errors, 0, 3)));
        }

        return $ids;
    }
}
