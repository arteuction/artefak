<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DisputeResource\Pages;
use App\Models\Dispute;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;

final class DisputeResource extends Resource
{
    protected static ?string $model = Dispute::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->options([
                    'payment'  => 'Payment',
                    'delivery' => 'Delivery',
                    'quality'  => 'Quality',
                    'fraud'    => 'Fraud',
                    'other'    => 'Other',
                ])
                ->disabled(),
            Select::make('status')
                ->options([
                    'open'     => 'Open',
                    'assigned' => 'Assigned',
                    'resolved' => 'Resolved',
                    'closed'   => 'Closed',
                ])
                ->disabled(),
            Textarea::make('description')->rows(4)->disabled(),
            Textarea::make('resolution')->rows(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('type'),
                Tables\Columns\TextColumn::make('status'),
                Tables\Columns\TextColumn::make('openedBy.name')->label('Opened By')->searchable(),
                Tables\Columns\TextColumn::make('assignedTo.name')->label('Assigned To'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'open'     => 'Open',
                        'assigned' => 'Assigned',
                        'resolved' => 'Resolved',
                        'closed'   => 'Closed',
                    ]),
            ])
            ->actions([
                Action::make('assign')
                    ->label('Assign to me')
                    ->icon('heroicon-o-user')
                    ->color('warning')
                    ->visible(fn (Dispute $record) => $record->status === 'open')
                    ->action(function (Dispute $record): void {
                        $record->update([
                            'assigned_to' => auth()->id(),
                            'status'      => 'assigned',
                        ]);
                        Notification::make()->title('Dispute assigned to you')->success()->send();
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
            'index' => Pages\ListDisputes::route('/'),
            'view'  => Pages\ViewDispute::route('/{record}'),
        ];
    }
}
