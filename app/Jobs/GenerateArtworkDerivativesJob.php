<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Asset\GenerateArtworkDerivatives;
use App\Models\Artwork;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Generates thumb/medium/large WebP derivatives from a confirmed primary image.
 *
 * Dispatched to the 'low' queue — image processing is CPU and memory intensive
 * and must not block an Octane HTTP worker (can use 200–400 MB for large originals).
 */
final class GenerateArtworkDerivativesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 3;
    public int $backoff = 30;

    public function __construct(public readonly int $artworkId) {}

    public function handle(): void
    {
        $artwork = Artwork::findOrFail($this->artworkId);
        (new GenerateArtworkDerivatives())->execute($artwork);
    }
}
