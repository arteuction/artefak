<?php

declare(strict_types=1);

namespace App\Domain\Artmetro;

use App\Models\Exhibition;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the SDG tags on an exhibition atomically.
 *
 * SDG numbers must be 1–17 (UN Sustainable Development Goals).
 * Passing an empty array clears all tags.
 */
final class TagExhibitionSdgs
{
    public function execute(Exhibition $exhibition, array $sdgNumbers): void
    {
        $valid = array_values(array_unique(
            array_filter($sdgNumbers, fn (mixed $n) => is_int($n) && $n >= 1 && $n <= 17)
        ));

        DB::transaction(function () use ($exhibition, $valid): void {
            DB::table('artmetro_exhibition_sdg')
                ->where('exhibition_id', $exhibition->id)
                ->delete();

            if (empty($valid)) {
                return;
            }

            DB::table('artmetro_exhibition_sdg')->insert(
                array_map(
                    fn (int $n) => ['exhibition_id' => $exhibition->id, 'sdg_number' => $n],
                    $valid
                )
            );
        });
    }
}
