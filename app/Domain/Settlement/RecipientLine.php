<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/**
 * Immutable value object describing one payout recipient.
 * Passed to CreateSettlement::execute() — one per settlement line.
 */
final class RecipientLine
{
    public function __construct(
        /** 'artist' | 'fund' | 'ops' */
        public readonly string  $type,
        public readonly Money   $amount,
        public readonly ?int    $legalEntityId  = null,
        public readonly ?string $entityName     = null,
        public readonly ?string $entityEik      = null,
        public readonly ?string $entityRole     = null,
        public readonly ?string $stripeAccountId = null,
        public readonly int     $weight         = 1,
    ) {}
}
