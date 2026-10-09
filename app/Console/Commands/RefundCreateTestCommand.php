<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Settlement\ProcessRefund;
use Illuminate\Console\Command;

/**
 * Used only by ConcurrentRefundTest to spawn parallel worker processes.
 */
class RefundCreateTestCommand extends Command
{
    protected $signature   = 'refund:create-test {stripe_refund_id} {payment_intent_id} {amount=10000}';
    protected $description = 'Test-only helper for ConcurrentRefundTest — blocked in production';
    protected $hidden      = true;

    public function handle(ProcessRefund $action): int
    {
        if (app()->environment('production')) {
            $this->error('refund:create-test is a test-only command and cannot run in production.');
            return self::FAILURE;
        }

        $id = $action->execute(
            stripeRefundId:  $this->argument('stripe_refund_id'),
            paymentIntentId: $this->argument('payment_intent_id'),
            amountCents:     (int) $this->argument('amount'),
            currency:        'EUR',
        );

        $this->line((string) $id);

        return self::SUCCESS;
    }
}
