<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Auction\ExpireBidAuthorization;
use App\Models\Bid;
use App\Services\OperationalAlertService;
use Illuminate\Console\Command;

/**
 * Proactively marks bids whose Stripe PaymentIntent authorization has expired.
 *
 * Stripe cancels uncaptured PIs after 7 days. This command runs hourly and
 * marks affected bids as 'authorization_expired' so the capture path in
 * SettleAuction knows not to attempt capture.
 *
 * Only targets bids still in status 'accepted' or 'won' with payment_status
 * 'authorized' whose authorization_expires_at has passed.
 *
 * Fires an operational alert for each expired winning bid (status='won') since
 * those require operator attention: the winner must re-authorize payment.
 */
final class ExpireBidAuthorizations extends Command
{
    protected $signature   = 'auction:expire-bid-authorizations';
    protected $description = 'Mark bids whose Stripe authorization has expired and cancel the PaymentIntent';

    public function handle(
        ExpireBidAuthorization  $expireAction,
        OperationalAlertService $alerts,
    ): int {
        $expired = Bid::whereIn('status', ['accepted', 'won'])
            ->where('payment_status', 'authorized')
            ->whereNotNull('authorization_expires_at')
            ->where('authorization_expires_at', '<', now())
            ->get();

        foreach ($expired as $bid) {
            $expireAction->execute($bid);

            if ($bid->status === 'won') {
                $alerts->alert(
                    type:    'winning_bid_authorization_expired',
                    message: "Winning bid #{$bid->id} (auction_item #{$bid->auction_item_id}) authorization expired. Winner must re-authorize payment.",
                    context: [
                        'bid_id'          => $bid->id,
                        'auction_item_id' => $bid->auction_item_id,
                        'amount_cents'    => $bid->amount_cents,
                        'user_id'         => $bid->user_id,
                        'expired_at'      => $bid->authorization_expires_at?->toIso8601String(),
                    ],
                );
            }
        }

        $this->info("Processed {$expired->count()} expired bid authorization(s).");

        return self::SUCCESS;
    }
}
