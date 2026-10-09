<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ArtworkEvidenceResource\Pages;
use App\Models\ArtworkEvidence;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;

final class ArtworkEvidenceResource extends Resource
{
    protected static ?string $model = ArtworkEvidence::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('type')->disabled(),
            TextInput::make('issuer')->disabled(),
            TextInput::make('document_path')->label('Document')->disabled(),
            Select::make('verification_status')
                ->options([
                    'pending'  => 'Pending',
                    'verified' => 'Verified',
                    'rejected' => 'Rejected',
                ]),
            Select::make('visibility')
                ->options([
                    'public'   => 'Public',
                    'private'  => 'Private',
                    'admin'    => 'Admin Only',
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('artwork.title')->label('Artwork')->limit(40)->searchable(),
                Tables\Columns\TextColumn::make('type'),
                Tables\Columns\TextColumn::make('issuer')->limit(30),
                Tables\Columns\BadgeColumn::make('verification_status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'verified',
                        'danger'  => 'rejected',
                    ]),
                Tables\Columns\TextColumn::make('visibility'),
                Tables\Columns\TextColumn::make('issued_at')->date()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('verification_status')
                    ->options([
                        'pending'  => 'Pending',
                        'verified' => 'Verified',
                        'rejected' => 'Rejected',
                    ]),
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'authenticity'       => 'Authenticity',
                        'provenance'         => 'Provenance',
                        'condition'          => 'Condition',
                        'ownership'          => 'Ownership',
                        'certificate'        => 'Certificate',
                        'exhibition_history' => 'Exhibition History',
                        'restoration'        => 'Restoration',
                    ]),
            ])
            ->actions([
                Action::make('verify')
                    ->label('Verify')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ArtworkEvidence $record) => $record->verification_status === 'pending')
                    ->action(function (ArtworkEvidence $record): void {
                        $record->update(['verification_status' => 'verified']);
                        Notification::make()->title('Evidence verified')->success()->send();
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ArtworkEvidence $record) => $record->verification_status === 'pending')
                    ->requiresConfirmation()
                    ->action(function (ArtworkEvidence $record): void {
                        $record->update(['verification_status' => 'rejected']);
                        Notification::make()->title('Evidence rejected')->warning()->send();
                    }),
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
            'index' => Pages\ListArtworkEvidence::route('/'),
            'view'  => Pages\ViewArtworkEvidence::route('/{record}'),
        ];
    }
}
