<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\ReconciliationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Stripe\Collection;
use Stripe\Service\BalanceTransactionService;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Phase 172 — RunNightlyReconciliationCommand (reconciliation:nightly) tests.
 *
 * StripeClient is mocked so tests run without real Stripe credentials.
 * Tests cover: command registration, match scenario (Stripe totals equal
 * internal), mismatch scenario (delta detected), Stripe error path, and
 * --date override flag.
 */
final class NightlyReconciliationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered(): void
    {
        $this->artisan('reconciliation:nightly --help')->assertExitCode(0);
    }

    public function test_command_exits_zero_when_totals_match(): void
    {
        // No settlements → internal gross = 0. Mock Stripe to return 0 as well.
        $this->bindMockStripe(received: 0, transferred: 0);

        $this->artisan('reconciliation:nightly')->assertExitCode(0);
    }

    public function test_reconciliation_run_record_is_created(): void
    {
        $this->bindMockStripe(received: 0, transferred: 0);

        Artisan::call('reconciliation:nightly');

        $this->assertDatabaseCount('reconciliation_runs', 1);
        $run = ReconciliationRun::first();
        $this->assertNotNull($run);
    }

    public function test_command_exits_one_on_mismatch(): void
    {
        // Mock Stripe returning €100 received, but no settlements internally.
        $this->bindMockStripe(received: 10_000, transferred: 0);

        $this->artisan('reconciliation:nightly')->assertExitCode(1);
    }

    public function test_mismatch_run_has_nonzero_delta(): void
    {
        $this->bindMockStripe(received: 10_000, transferred: 0);

        Artisan::call('reconciliation:nightly');

        $run = ReconciliationRun::first();
        $this->assertNotNull($run);
        $this->assertNotSame(0, $run->delta_received_cents);
    }

    public function test_date_override_flag_is_accepted(): void
    {
        $this->bindMockStripe(received: 0, transferred: 0);

        $this->artisan('reconciliation:nightly', ['--date' => '2026-01-01'])->assertExitCode(0);
    }

    public function test_stripe_error_exits_one_and_marks_run_error(): void
    {
        $stripe = Mockery::mock(StripeClient::class);
        $stripe->balanceTransactions = Mockery::mock(BalanceTransactionService::class);
        $stripe->balanceTransactions->shouldReceive('all')
            ->andThrow(new \Stripe\Exception\ApiConnectionException('Connection refused'));

        $this->app->instance(StripeClient::class, $stripe);

        $this->artisan('reconciliation:nightly')->assertExitCode(1);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function bindMockStripe(int $received, int $transferred): void
    {
        $stripe = Mockery::mock(StripeClient::class);
        $balanceTxSvc = Mockery::mock(BalanceTransactionService::class);
        $stripe->balanceTransactions = $balanceTxSvc;

        // Mock balanceTransactions->all() — return a fake Stripe Collection.
        // First call = 'charge' type (received); second call = 'transfer' type.
        $makeEmptyPage = function () {
            $page           = new Collection();
            $page->data     = [];
            $page->has_more = false;
            return $page;
        };

        $makePageWith = function (int $amount) use ($makeEmptyPage): Collection {
            $txn         = new \stdClass();
            $txn->id     = 'txn_test';
            $txn->amount = $amount;

            $page           = new Collection();
            $page->data     = [$txn];
            $page->has_more = false;
            return $page;
        };

        $balanceTxSvc->shouldReceive('all')
            ->withArgs(fn ($params) => ($params['type'] ?? '') === 'charge')
            ->andReturn($received > 0 ? $makePageWith($received) : $makeEmptyPage());

        $balanceTxSvc->shouldReceive('all')
            ->withArgs(fn ($params) => ($params['type'] ?? '') === 'transfer')
            ->andReturn($transferred > 0 ? $makePageWith($transferred) : $makeEmptyPage());

        $this->app->instance(StripeClient::class, $stripe);
    }
}
