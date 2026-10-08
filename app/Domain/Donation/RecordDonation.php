<?php

declare(strict_types=1);

namespace App\Domain\Donation;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\Donation;
use App\Models\DonationRecipient;
use App\Models\User;
use InvalidArgumentException;

final class RecordDonation
{
    public function __construct(
        private readonly DonationCalculator $calculator = new DonationCalculator(),
    ) {}

    /**
     * @param  array{art_lot_id?: int, auction_item_id?: int, sell_now_offer_id?: int}  $source
     */
    public function execute(
        DonationRecipient $recipient,
        User $donor,
        int $donatedCents,
        string $idempotencyKey,
        array $source = [],
        ?int $impactProjectId = null,
    ): Donation {
        if ($recipient->status !== 'active') {
            throw new InvalidArgumentException("Recipient is not active (status: {$recipient->status}).");
        }

        $this->validateSource($source);

        $basis  = EligibilityBasis::from($recipient->eligibility_basis);
        $result = $this->calculator->calculate($donatedCents, $basis);

        $donation = Donation::create([
            'donation_recipient_id' => $recipient->id,
            'donor_id'              => $donor->id,
            'art_lot_id'            => $source['art_lot_id'] ?? null,
            'auction_item_id'       => $source['auction_item_id'] ?? null,
            'sell_now_offer_id'     => $source['sell_now_offer_id'] ?? null,
            'impact_project_id'     => $impactProjectId,
            'donated_cents'         => $result->donatedCents,
            'currency'              => 'EUR',
            'eligibility_basis'     => $result->eligibilityBasis->value,
            'deduction_bps'         => $result->deductionBps,
            'max_deductible_cents'  => $result->maxDeductibleCents,
            'type'                  => 'donation',
            'status'                => 'pending',
            'idempotency_key'       => $idempotencyKey,
        ]);

        (new AppendDomainEvent())->execute(
            aggregate: $donation,
            eventType: 'donation.recorded',
            payload: [
                'recipient_id'         => $recipient->id,
                'donor_id'             => $donor->id,
                'donated_cents'        => $result->donatedCents,
                'eligibility_basis'    => $result->eligibilityBasis->value,
                'max_deductible_cents' => $result->maxDeductibleCents,
                'currency'             => 'EUR',
            ],
            idempotencyKey: "donation.recorded:{$idempotencyKey}",
        );

        return $donation;
    }

    private function validateSource(array $source): void
    {
        $set = array_filter([
            $source['art_lot_id']       ?? null,
            $source['auction_item_id']  ?? null,
            $source['sell_now_offer_id'] ?? null,
        ]);

        if (count($set) > 1) {
            throw new InvalidArgumentException('Donation source must be exactly one of: art_lot_id, auction_item_id, sell_now_offer_id.');
        }
    }
}
