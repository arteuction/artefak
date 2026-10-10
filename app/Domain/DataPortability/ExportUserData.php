<?php

declare(strict_types=1);

namespace App\Domain\DataPortability;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * GDPR Article 20 — data portability export.
 *
 * Collects all personal data we hold for a user and returns it as a
 * structured array ready for JSON serialisation.
 *
 * Privacy rules:
 *   - Only the subject's own data is included.
 *   - Counterparty identities (other buyers/sellers) are withheld.
 *   - Hashed tokens and internal system fields are excluded.
 */
final class ExportUserData
{
    public function execute(User $user): array
    {
        return [
            'exported_at'     => now()->toIso8601String(),
            'schema_version'  => '1.0',
            'subject'         => $this->profileData($user),
            'artworks'        => $this->artworks($user),
            'sell_now_offers' => $this->sellNowOffers($user),
            'bids'            => $this->bids($user),
            'donations'       => $this->donations($user),
            'ledger_entries'  => $this->ledgerEntries($user),
            'impact_events'   => $this->impactEvents($user),
            'watchlist'       => $this->watchlist($user),
        ];
    }

    private function profileData(User $user): array
    {
        return [
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'role'       => $user->role,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    private function artworks(User $user): array
    {
        return DB::table('artworks')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->get(['id', 'title', 'medium', 'year_created', 'status', 'created_at'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function sellNowOffers(User $user): array
    {
        return DB::table('sell_now_offers')
            ->where('buyer_id', $user->id)
            ->get([
                'id', 'art_lot_id', 'status',
                'offered_price_cents', 'agreed_price_cents', 'currency',
                'created_at', 'updated_at',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function bids(User $user): array
    {
        return DB::table('bids')
            ->where('user_id', $user->id)
            ->get(['id', 'auction_item_id', 'amount_cents', 'currency', 'status', 'created_at'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function donations(User $user): array
    {
        return DB::table('donations')
            ->where('donor_id', $user->id)
            ->get(['id', 'amount_cents', 'currency', 'status', 'created_at'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function ledgerEntries(User $user): array
    {
        // Ledger entries are linked via settlements, not directly to users.
        // We expose entries for settlements derived from this user's artworks.
        $artworkIds = DB::table('artworks')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->pluck('id');

        $artLotIds = DB::table('art_lots')
            ->whereIn('artwork_id', $artworkIds)
            ->pluck('id');

        $sellNowOfferIds = DB::table('sell_now_offers')
            ->whereIn('art_lot_id', $artLotIds)
            ->pluck('id');

        $settlementIds = DB::table('settlements')
            ->whereIn('id', function ($q) use ($sellNowOfferIds): void {
                $q->select('id')
                    ->from('settlements')
                    ->whereIn('sell_now_offer_id', $sellNowOfferIds);
            })
            ->pluck('id');

        if ($settlementIds->isEmpty()) {
            return [];
        }

        return DB::table('ledger_entries')
            ->whereIn('settlement_id', $settlementIds)
            ->get(['id', 'settlement_id', 'type', 'amount_cents', 'currency', 'note', 'created_at'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function impactEvents(User $user): array
    {
        $artworkIds = DB::table('artworks')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->pluck('id');

        $artLotIds = DB::table('art_lots')
            ->whereIn('artwork_id', $artworkIds)
            ->pluck('id');

        return DB::table('impact_events')
            ->whereIn('art_lot_id', $artLotIds)
            ->get(['id', 'sdg_number', 'metric', 'magnitude', 'currency', 'type', 'created_at'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function watchlist(User $user): array
    {
        return DB::table('watchlist_items')
            ->where('user_id', $user->id)
            ->get(['id', 'art_lot_id', 'created_at'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}
