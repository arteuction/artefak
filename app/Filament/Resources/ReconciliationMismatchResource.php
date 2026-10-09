<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ReconciliationMismatchResource\Pages;
use App\Models\ReconciliationMismatch;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;

final class ReconciliationMismatchResource extends Resource
{
    protected static ?string $model = ReconciliationMismatch::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('check_type')->disabled(),
            TextInput::make('stripe_transaction_id')->label('Stripe ID')->disabled(),
            TextInput::make('internal_reference')->disabled(),
            TextInput::make('delta_cents')->label('Delta (cents)')->disabled(),
            Select::make('resolution_status')
                ->options([
                    'open'          => 'Open',
                    'investigating' => 'Investigating',
                    'resolved'      => 'Resolved',
                    'suppressed'    => 'Suppressed',
                ]),
            Textarea::make('resolution_notes')->rows(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('check_type')->label('Type'),
                Tables\Columns\TextColumn::make('stripe_transaction_id')->label('Stripe ID')->limit(25),
                Tables\Columns\TextColumn::make('delta_cents')->label('Δ cents')->sortable(),
                Tables\Columns\BadgeColumn::make('resolution_status')
                    ->colors([
                        'danger'  => 'open',
                        'warning' => 'investigating',
                        'success' => 'resolved',
                        'gray'    => 'suppressed',
                    ]),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('resolution_status')
                    ->options([
                        'open'          => 'Open',
                        'investigating' => 'Investigating',
                        'resolved'      => 'Resolved',
                        'suppressed'    => 'Suppressed',
                    ]),
                Tables\Filters\SelectFilter::make('check_type')
                    ->options([
                        'payment'  => 'Payment',
                        'refund'   => 'Refund',
                        'transfer' => 'Transfer',
                        'payout'   => 'Payout',
                    ]),
            ])
            ->actions([
                Action::make('investigate')
                    ->label('Investigate')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('warning')
                    ->visible(fn (ReconciliationMismatch $record) => $record->resolution_status === 'open')
                    ->action(function (ReconciliationMismatch $record): void {
                        $record->update(['resolution_status' => 'investigating']);
                        Notification::make()->title('Mismatch marked as investigating')->warning()->send();
                    }),
                Action::make('resolve')
                    ->label('Resolve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ReconciliationMismatch $record) => in_array($record->resolution_status, ['open', 'investigating'], true))
                    ->form([
                        Textarea::make('resolution_notes')->label('Resolution notes')->rows(3)->required(),
                    ])
                    ->action(function (ReconciliationMismatch $record, array $data): void {
                        $record->update([
                            'resolution_status' => 'resolved',
                            'resolution_notes'  => $data['resolution_notes'],
                            'resolved_at'       => now(),
                        ]);
                        Notification::make()->title('Mismatch resolved')->success()->send();
                    }),
                Action::make('suppress')
                    ->label('Suppress')
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->visible(fn (ReconciliationMismatch $record) => $record->resolution_status === 'open')
                    ->requiresConfirmation()
                    ->action(function (ReconciliationMismatch $record): void {
                        $record->update(['resolution_status' => 'suppressed']);
                        Notification::make()->title('Mismatch suppressed')->send();
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
            'index' => Pages\ListReconciliationMismatches::route('/'),
            'view'  => Pages\ViewReconciliationMismatch::route('/{record}'),
        ];
    }
}
