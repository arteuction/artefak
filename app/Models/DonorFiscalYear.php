<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DonorFiscalYear extends Model
{
    protected $table = 'donor_fiscal_years';

    protected $fillable = [
        'donor_id',
        'fiscal_year',
        'eligibility_basis',
        'aggregate_donated_cents',
        'aggregate_max_deductible_cents',
        'donation_count',
        'documentation_status',
        'documentation_submitted_at',
    ];

    protected $casts = [
        'donor_id'                      => 'integer',
        'fiscal_year'                   => 'integer',
        'aggregate_donated_cents'       => 'integer',
        'aggregate_max_deductible_cents'=> 'integer',
        'donation_count'                => 'integer',
        'documentation_submitted_at'    => 'datetime',
    ];

    public function donor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'donor_id');
    }

    public function isDocumentationReady(): bool
    {
        return $this->documentation_status === 'ready';
    }

    public function isSubmitted(): bool
    {
        return $this->documentation_status === 'submitted';
    }
}
