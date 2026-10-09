<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ArtworkResource\Pages;
use App\Models\Artwork;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

final class ArtworkResource extends Resource
{
    protected static ?string $model = Artwork::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('slug')->required()->maxLength(255),
            Select::make('status')
                ->options([
                    'draft'      => 'Draft',
                    'listed'     => 'Listed',
                    'in_auction' => 'In Auction',
                    'sold'       => 'Sold',
                    'archived'   => 'Archived',
                ])
                ->required(),
            Select::make('medium')
                ->options([
                    'painting'    => 'Painting',
                    'sculpture'   => 'Sculpture',
                    'photography' => 'Photography',
                    'digital'     => 'Digital',
                    'nft'         => 'NFT',
                    'mixed'       => 'Mixed',
                    'other'       => 'Other',
                ]),
            TextInput::make('dimensions')->maxLength(200),
            TextInput::make('year_created')->numeric()->minValue(1000)->maxValue(2100),
            Textarea::make('description')->rows(4),
            Toggle::make('is_original')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('title')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('artist.name')->label('Artist')->searchable(),
                Tables\Columns\TextColumn::make('status'),
                Tables\Columns\TextColumn::make('medium'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft'      => 'Draft',
                        'listed'     => 'Listed',
                        'in_auction' => 'In Auction',
                        'sold'       => 'Sold',
                        'archived'   => 'Archived',
                    ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListArtworks::route('/'),
            'view'  => Pages\ViewArtwork::route('/{record}'),
        ];
    }
}
