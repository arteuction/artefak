<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BookCategory;
use Illuminate\Database\Seeder;

final class BookCategorySeeder extends Seeder
{
    public function run(): void
    {
        $roots = [
            ['slug' => 'fine-art',        'name' => 'Fine Art',               'sort_order' => 1],
            ['slug' => 'photography',     'name' => 'Photography',            'sort_order' => 2],
            ['slug' => 'art-history',     'name' => 'Art History',            'sort_order' => 3],
            ['slug' => 'architecture',    'name' => 'Architecture & Design',  'sort_order' => 4],
            ['slug' => 'cultural-heritage','name' => 'Cultural Heritage',     'sort_order' => 5],
            ['slug' => 'poetry',          'name' => 'Poetry',                 'sort_order' => 6],
            ['slug' => 'fiction',         'name' => 'Fiction',                'sort_order' => 7],
            ['slug' => 'essays',          'name' => 'Essays & Criticism',     'sort_order' => 8],
        ];

        $children = [
            'fine-art' => [
                ['slug' => 'painting',    'name' => 'Painting'],
                ['slug' => 'sculpture',   'name' => 'Sculpture'],
                ['slug' => 'printmaking', 'name' => 'Printmaking & Graphics'],
            ],
            'art-history' => [
                ['slug' => 'bulgarian-art',  'name' => 'Bulgarian Art'],
                ['slug' => 'contemporary',   'name' => 'Contemporary Art'],
                ['slug' => 'renaissance',    'name' => 'Renaissance & Baroque'],
            ],
            'cultural-heritage' => [
                ['slug' => 'thracian',    'name' => 'Thracian Civilisation'],
                ['slug' => 'byzantine',   'name' => 'Byzantine Art'],
                ['slug' => 'folk-art',    'name' => 'Folk Art & Crafts'],
            ],
        ];

        foreach ($roots as $i => $data) {
            $category = BookCategory::firstOrCreate(
                ['slug' => $data['slug']],
                array_merge($data, ['sort_order' => $i + 1]),
            );

            foreach ($children[$data['slug']] ?? [] as $j => $childData) {
                BookCategory::firstOrCreate(
                    ['slug' => $childData['slug']],
                    array_merge($childData, [
                        'parent_id'  => $category->id,
                        'sort_order' => $j + 1,
                    ]),
                );
            }
        }
    }
}
