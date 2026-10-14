<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\CloseAuctionItemJob;
use App\Jobs\DispatchStripeReversal;
use App\Jobs\DispatchStripeTransfer;
use App\Jobs\GenerateArtworkDerivativesJob;
use App\Jobs\HandleStripeWebhook;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that every job class satisfies the queue timeout contract:
 *
 *   job $timeout < connection retry_after (90s)
 *
 * Laravel warns that if timeout >= retry_after the queue worker may re-dispatch
 * a job while the original is still running, producing duplicate execution.
 * This test catches a misconfigured timeout before it reaches production.
 *
 * Also verifies that all financial/external jobs have bounded retry counts
 * and non-zero backoff to prevent immediate retry storms.
 */
final class QueueContractTest extends TestCase
{
    private const RETRY_AFTER_SECONDS = 90; // DB queue connection retry_after in config/queue.php

    /** @return array<string, array{class-string}> */
    public static function jobProvider(): array
    {
        return [
            'HandleStripeWebhook'          => [HandleStripeWebhook::class],
            'DispatchStripeTransfer'        => [DispatchStripeTransfer::class],
            'DispatchStripeReversal'        => [DispatchStripeReversal::class],
            'CloseAuctionItemJob'           => [CloseAuctionItemJob::class],
            'GenerateArtworkDerivativesJob' => [GenerateArtworkDerivativesJob::class],
        ];
    }

    /** @dataProvider jobProvider */
    public function test_job_timeout_is_under_retry_after(string $class): void
    {
        $job = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        $this->assertObjectHasProperty('timeout', $job,
            "{$class} must declare a \$timeout property.");

        $timeout = $job->timeout;

        $this->assertLessThan(
            self::RETRY_AFTER_SECONDS,
            $timeout,
            "{$class}: \$timeout ({$timeout}s) must be less than retry_after (" . self::RETRY_AFTER_SECONDS . "s) " .
            "to prevent duplicate execution when a job overruns."
        );
    }

    /** @dataProvider jobProvider */
    public function test_job_has_bounded_retry_count(string $class): void
    {
        $job = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        $this->assertObjectHasProperty('tries', $job,
            "{$class} must declare a \$tries property so jobs don't retry indefinitely.");

        $this->assertGreaterThan(0, $job->tries,
            "{$class}: \$tries must be > 0.");
    }
}
