<?php

declare(strict_types=1);

namespace Modules\Transaction\Filament\Resources\OrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only relation manager displaying operational execution stages on ViewOrder.
 */
class OrderStagesRelationManager extends RelationManager
{
    /**
     * The Eloquent relationship name on the parent Order model.
     */
    protected static string $relationship = 'stages';

    /**
     * The title displayed above the relation table.
     */
    protected static ?string $title = 'Tahapan Pengerjaan';

    /**
     * Prevent creating stages outside OrderStageService.
     */
    public function canCreate(): bool
    {
        return false;
    }

    /**
     * Prevent editing stage audit entries directly.
     */
    public function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Prevent deleting stage audit entries.
     */
    public function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Define the read-only order stages table schema.
     */
    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('stage_name')
                    ->label('Tahapan')
                    ->badge(),
                TextColumn::make('started_at')
                    ->label('Mulai')
                    ->dateTime(),
                TextColumn::make('completed_at')
                    ->label('Selesai')
                    ->dateTime()
                    ->placeholder('Berlangsung'),
                TextColumn::make('notes')
                    ->label('Catatan')
                    ->wrap()
                    ->placeholder('-'),
            ]);
    }
}
