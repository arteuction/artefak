<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Donation\EligibilityBasis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DonationRecipient extends Model
{
    protected $fillable = [
        'name',
        'eik',
        'legal_type',
        'eligibility_basis',
        'deduction_bps',
        'stripe_account_id',
        'status',
    ];

    protected $casts = [
        'deduction_bps' => 'integer',
    ];

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    public function eligibilityBasis(): EligibilityBasis
    {
        return EligibilityBasis::from($this->eligibility_basis);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
