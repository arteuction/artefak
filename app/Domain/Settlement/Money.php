<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/**
 * Money value object — whole integer cents, no float, currency-tagged.
 *
 * MUST NOT depend on Laravel, Eloquent or Stripe.
 */
final class Money
{
    private function __construct(
        public readonly int $cents,
        public readonly string $currency,
    ) {}

    public static function fromCents(int $cents, string $currency = 'EUR'): self
    {
        return new self($cents, $currency);
    }

    public function plus(Money $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->cents + $other->cents, $this->currency);
    }

    public function minus(Money $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->cents - $other->cents, $this->currency);
    }

    public function equals(Money $other): bool
    {
        return $this->cents === $other->cents && $this->currency === $other->currency;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    /**
     * Split this amount into parts proportional to $weights, LOSING NO CENTS.
     * Uses the largest-remainder method; ties break toward the earliest index.
     * The returned parts MUST sum exactly to $this.
     *
     * @param  int[]  $weights  non-negative integer weights (e.g. basis points)
     * @return Money[]          same length/order as $weights
     */
    public function allocate(array $weights): array
    {
        $sumWeights = array_sum($weights);

        if ($sumWeights === 0) {
            return array_map(fn () => new self(0, $this->currency), $weights);
        }

        $total    = $this->cents;
        $floors   = [];
        $remainders = [];

        foreach ($weights as $i => $w) {
            $exact        = $total * $w / $sumWeights;
            $floors[$i]   = (int) floor($exact);
            $remainders[$i] = $exact - $floors[$i];
        }

        $leftover = $total - array_sum($floors);

        // Sort indices by remainder desc, tie-break by index asc (earliest wins).
        $indices = array_keys($weights);
        usort($indices, function (int $a, int $b) use ($remainders): int {
            $diff = $remainders[$b] - $remainders[$a];
            if (abs($diff) > 1e-12) {
                return $diff > 0 ? 1 : -1;
            }
            return $a <=> $b; // tie-break: earlier index first
        });

        $result = $floors;
        for ($i = 0; $i < $leftover; $i++) {
            $result[$indices[$i]]++;
        }

        return array_map(fn (int $cents) => new self($cents, $this->currency), $result);
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException(
                "Currency mismatch: {$this->currency} vs {$other->currency}"
            );
        }
    }
}
