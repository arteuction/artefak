<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Asset\ApproveConsignment;
use App\Filament\Resources\ConsignmentResource\Pages;
use App\Models\Consignment;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;

final class ConsignmentResource extends Resource
{
    protected static ?string $model = Consignment::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('status')
                ->options([
                    'draft'      => 'Draft',
                    'active'     => 'Active',
                    'completed'  => 'Completed',
                    'terminated' => 'Terminated',
                ])
                ->disabled(),
            TextInput::make('commission_bps')
                ->label('Commission (bps)')
                ->numeric()
                ->disabled(),
            Textarea::make('notes')->rows(3)->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('artwork.title')->label('Artwork')->limit(40)->searchable(),
                Tables\Columns\TextColumn::make('gallery.name')->label('Gallery')->searchable(),
                Tables\Columns\TextColumn::make('status'),
                Tables\Columns\TextColumn::make('commission_bps')->label('Commission (bps)'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft'      => 'Draft',
                        'active'     => 'Active',
                        'completed'  => 'Completed',
                        'terminated' => 'Terminated',
                    ]),
            ])
            ->actions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Consignment $record) => $record->status === 'draft')
                    ->action(function (Consignment $record): void {
                        try {
                            (new ApproveConsignment())->execute(
                                consignment: $record,
                                approvedBy: auth()->user(),
                            );
                            Notification::make()->title('Consignment approved')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
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
            'index' => Pages\ListConsignments::route('/'),
            'view'  => Pages\ViewConsignment::route('/{record}'),
        ];
    }
}
