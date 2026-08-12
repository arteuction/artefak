<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/**
 * Canonical revenue-split profile in basis points (1/100 of a percent).
 * artistBps + fundBps + operationsBps MUST equal 10000.
 *
 * P0 supports EXACTLY ONE profile:
 *   social_pilot_45_45_10 → artist 4500, fund 4500, operations 1000
 *
 * 'standard_90_10' is intentionally DROPPED for P0 — any other key MUST throw.
 *
 * NGO sub-splits live INSIDE fundBps; co-author sub-splits INSIDE artistBps.
 *
 * MUST NOT depend on Laravel, Eloquent or Stripe.
 */
final class SplitProfile
{
    /** Bump when the canonical basis points change (frozen onto each settlement). */
    public const VERSION = 1;

    private const PROFILES = [
        // ArteUction phygital auction — equal artist/fund split
        'social_pilot_45_45_10' => ['artist' => 4500, 'fund' => 4500, 'operations' => 1000],
        // Artefak digital library — author-first marketplace with SDG cause component
        'library_80_10_10'      => ['artist' => 8000, 'fund' => 1000, 'operations' => 1000],
    ];

    private function __construct(
        private readonly string $key,
        private readonly int $artistBps,
        private readonly int $fundBps,
        private readonly int $operationsBps,
    ) {}

    public static function fromKey(string $key): self
    {
        if (! array_key_exists($key, self::PROFILES)) {
            $known = implode("', '", array_keys(self::PROFILES));
            throw new \InvalidArgumentException(
                "Unknown split profile '{$key}'. Known profiles: '{$known}'."
            );
        }

        $p = self::PROFILES[$key];
        return new self($key, $p['artist'], $p['fund'], $p['operations']);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function artistBps(): int
    {
        return $this->artistBps;
    }

    public function fundBps(): int
    {
        return $this->fundBps;
    }

    public function operationsBps(): int
    {
        return $this->operationsBps;
    }
}
