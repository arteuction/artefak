<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Artwork;
use App\Models\ArtLot;
use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Pilot demo seeder — populates the database with realistic demo data.
 *
 * Usage:
 *   php artisan db:seed --class=PilotDemoSeeder
 *
 * Creates:
 *   - 1 admin user        (admin@arteuction.bg / secret)
 *   - 3 artist users
 *   - 3 buyer users
 *   - 1 gallery
 *   - 9 artworks (3 per artist, mix of draft/listed/in_auction)
 *   - 1 scheduled auction with 2 lots
 */
final class PilotDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('PilotDemoSeeder must NOT run in production.');
            return;
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@arteuction.bg'],
            ['name' => 'Admin', 'password' => Hash::make('secret'), 'role' => 'admin'],
        );

        $artists = collect([
            ['name' => 'Elena Petrova',  'email' => 'elena@arteuction.bg'],
            ['name' => 'Ivan Stoyanov',  'email' => 'ivan@arteuction.bg'],
            ['name' => 'Maria Georgieva', 'email' => 'maria@arteuction.bg'],
        ])->map(fn ($data) => User::firstOrCreate(
            ['email' => $data['email']],
            ['name' => $data['name'], 'password' => Hash::make('secret'), 'role' => 'artist'],
        ));

        collect([
            ['name' => 'Sofia Buyer',   'email' => 'buyer1@arteuction.bg'],
            ['name' => 'Plovdiv Buyer', 'email' => 'buyer2@arteuction.bg'],
            ['name' => 'Varna Buyer',   'email' => 'buyer3@arteuction.bg'],
        ])->each(fn ($data) => User::firstOrCreate(
            ['email' => $data['email']],
            ['name' => $data['name'], 'password' => Hash::make('secret'), 'role' => 'buyer'],
        ));

        $gallery = Gallery::firstOrCreate(
            ['slug' => 'sofia-contemporary'],
            ['name' => 'Sofia Contemporary', 'status' => 'active', 'type' => 'private'],
        );

        $artworkDefs = [
            // Elena
            ['user' => $artists[0], 'title' => 'Morning Light on Vitosha', 'slug' => 'morning-light-vitosha', 'medium' => 'painting', 'year' => 2023, 'status' => 'listed'],
            ['user' => $artists[0], 'title' => 'Urban Fragment #1',        'slug' => 'urban-fragment-1',       'medium' => 'photography', 'year' => 2024, 'status' => 'in_auction'],
            ['user' => $artists[0], 'title' => 'Still Life Study',         'slug' => 'still-life-study',       'medium' => 'painting', 'year' => 2022, 'status' => 'draft'],
            // Ivan
            ['user' => $artists[1], 'title' => 'Plovdiv Rooftops',         'slug' => 'plovdiv-rooftops',       'medium' => 'painting', 'year' => 2021, 'status' => 'listed'],
            ['user' => $artists[1], 'title' => 'Digital Threshold',        'slug' => 'digital-threshold',      'medium' => 'digital', 'year' => 2024, 'status' => 'listed'],
            ['user' => $artists[1], 'title' => 'Antiquity III',            'slug' => 'antiquity-iii',          'medium' => 'sculpture', 'year' => 2020, 'status' => 'in_auction'],
            // Maria
            ['user' => $artists[2], 'title' => 'Black Sea Series No. 7',   'slug' => 'black-sea-series-7',     'medium' => 'photography', 'year' => 2023, 'status' => 'listed'],
            ['user' => $artists[2], 'title' => 'Roses in January',         'slug' => 'roses-in-january',       'medium' => 'painting', 'year' => 2024, 'status' => 'listed'],
            ['user' => $artists[2], 'title' => 'Mixed Reality',            'slug' => 'mixed-reality',          'medium' => 'nft', 'year' => 2024, 'status' => 'listed'],
        ];

        $artworks = [];
        foreach ($artworkDefs as $def) {
            $artworks[] = Artwork::firstOrCreate(
                ['slug' => $def['slug']],
                [
                    'user_id'     => $def['user']->id,
                    'title'       => $def['title'],
                    'medium'      => $def['medium'],
                    'year_created' => $def['year'],
                    'status'      => $def['status'],
                    'is_original' => true,
                ],
            );
        }

        // Listed artworks get an active ArtLot for Sell Now
        foreach ($artworks as $artwork) {
            if ($artwork->status === 'listed') {
                ArtLot::firstOrCreate(
                    ['artwork_id' => $artwork->id, 'gallery_id' => $gallery->id],
                    ['status' => 'active', 'currency' => 'EUR'],
                );
            }
        }

        // Scheduled auction with in_auction artworks as lots
        $auction = Auction::firstOrCreate(
            ['slug' => 'autumn-sale-2025'],
            [
                'title'      => 'Autumn Sale 2025',
                'status'     => 'published',
                'currency'   => 'EUR',
                'starts_at'  => now()->addDays(7),
                'ends_at'    => now()->addDays(10),
            ],
        );

        $lotNumber = 1;
        foreach ($artworks as $artwork) {
            if ($artwork->status === 'in_auction') {
                $lot = ArtLot::firstOrCreate(
                    ['artwork_id' => $artwork->id],
                    ['status' => 'active', 'currency' => 'EUR'],
                );
                AuctionItem::firstOrCreate(
                    ['auction_id' => $auction->id, 'art_lot_id' => $lot->id],
                    ['lot_number' => $lotNumber++, 'status' => 'pending', 'bid_increment_cents' => 5_000],
                );
            }
        }

        $this->command->info('Pilot demo data seeded.');
        $this->command->table(
            ['Role', 'Email', 'Password'],
            [
                ['admin',  'admin@arteuction.bg',  'secret'],
                ['artist', 'elena@arteuction.bg',  'secret'],
                ['artist', 'ivan@arteuction.bg',   'secret'],
                ['artist', 'maria@arteuction.bg',  'secret'],
                ['buyer',  'buyer1@arteuction.bg', 'secret'],
                ['buyer',  'buyer2@arteuction.bg', 'secret'],
                ['buyer',  'buyer3@arteuction.bg', 'secret'],
            ],
        );
    }
}
