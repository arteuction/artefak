<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TransferOutboxResource\Pages;
use App\Models\TransferOutbox;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;

final class TransferOutboxResource extends Resource
{
    protected static ?string $model = TransferOutbox::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('stripe_account_id')->label('Account')->limit(20),
                Tables\Columns\TextColumn::make('amount_cents')->label('Amount (cents)')->sortable(),
                Tables\Columns\TextColumn::make('currency'),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'dispatched',
                        'danger'  => 'failed',
                        'gray'    => 'canceled',
                    ]),
                Tables\Columns\TextColumn::make('attempt')->sortable(),
                Tables\Columns\TextColumn::make('last_error')->limit(50)->label('Last Error'),
                Tables\Columns\TextColumn::make('next_attempt_at')->dateTime()->label('Next Retry'),
                Tables\Columns\TextColumn::make('dispatched_at')->dateTime(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending'    => 'Pending',
                        'dispatched' => 'Dispatched',
                        'failed'     => 'Failed',
                        'canceled'   => 'Canceled',
                    ]),
            ])
            ->actions([
                Action::make('retry')
                    ->label('Reset for Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (TransferOutbox $record) => $record->status === 'failed')
                    ->requiresConfirmation()
                    ->action(function (TransferOutbox $record): void {
                        $record->update([
                            'status'          => 'pending',
                            'next_attempt_at' => now(),
                            'last_error'      => null,
                        ]);
                        Notification::make()->title('Transfer queued for retry')->warning()->send();
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
            'index' => Pages\ListTransferOutbox::route('/'),
        ];
    }
}
