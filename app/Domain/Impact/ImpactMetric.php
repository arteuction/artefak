<?php

declare(strict_types=1);

namespace App\Domain\Impact;

enum ImpactMetric: string
{
    case SaleAmountEur     = 'sale_amount_eur';
    case DonationAmountEur = 'donation_amount_eur';
    case AudienceReach     = 'audience_reach';
    case ArtworksExhibited = 'artworks_exhibited';
    case ArtworksSold      = 'artworks_sold';
    case BooksSold         = 'books_sold';
    case DonorsCount       = 'donors_count';

    public function isMonetary(): bool
    {
        return in_array($this, [self::SaleAmountEur, self::DonationAmountEur], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::SaleAmountEur     => 'Sale amount (EUR)',
            self::DonationAmountEur => 'Donation amount (EUR)',
            self::AudienceReach     => 'Audience reach',
            self::ArtworksExhibited => 'Artworks exhibited',
            self::ArtworksSold      => 'Artworks sold',
            self::BooksSold         => 'Books sold',
            self::DonorsCount       => 'Donors count',
        };
    }
}
