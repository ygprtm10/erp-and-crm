<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\InventoryStockResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Enums\StockMovementType;

/**
 * Read-only audit trail stock card displaying append-only inv_stock_movements entries.
 */
class StockMovementsRelationManager extends RelationManager
{
    /**
     * The Eloquent relationship name on the parent InventoryStock model.
     */
    protected static string $relationship = 'movements';

    /**
     * Prevent creating movement rows directly outside StockMovementService.
     */
    public function canCreate(): bool
    {
        return false;
    }

    /**
     * Prevent editing immutable stock movement audit entries.
     */
    public function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Prevent deleting immutable stock movement audit entries.
     */
    public function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Define the read-only stock card table schema.
     */
    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(static fn (StockMovementType $state): string => strtoupper($state->value))
                    ->color(static fn (StockMovementType $state): string => match ($state) {
                        StockMovementType::In => 'success',
                        StockMovementType::Out => 'warning',
                        StockMovementType::Adjustment => 'info',
                        StockMovementType::Transfer => 'gray',
                    }),
                TextColumn::make('qty_change')
                    ->label('Qty Change')
                    ->numeric(2),
                TextColumn::make('unit_cost')
                    ->label('Unit Cost')
                    ->money('IDR'),
                TextColumn::make('notes')
                    ->wrap()
                    ->placeholder('-'),
            ]);
    }
}
