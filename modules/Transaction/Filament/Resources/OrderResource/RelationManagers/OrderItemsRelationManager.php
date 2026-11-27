<?php

declare(strict_types=1);

namespace Modules\Transaction\Filament\Resources\OrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Transaction\Enums\KitchenStatus;

/**
 * Read-only relation manager displaying order line item snapshots on ViewOrder.
 */
class OrderItemsRelationManager extends RelationManager
{
    /**
     * The Eloquent relationship name on the parent Order model.
     */
    protected static string $relationship = 'items';

    /**
     * The title displayed above the relation table.
     */
    protected static ?string $title = 'Item Pesanan';

    /**
     * Prevent creating order items directly outside OrderCreationService.
     */
    public function canCreate(): bool
    {
        return false;
    }

    /**
     * Prevent editing immutable order item snapshots.
     */
    public function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Prevent deleting order item snapshots.
     */
    public function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Define the read-only order items table schema.
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('item_name_snapshot')
                    ->label('Produk / Varian')
                    ->searchable(),
                TextColumn::make('qty')
                    ->label('Qty')
                    ->numeric(2),
                TextColumn::make('unit_snapshot')
                    ->label('Satuan')
                    ->badge(),
                TextColumn::make('unit_price')
                    ->label('Harga Satuan')
                    ->money('IDR'),
                TextColumn::make('discount_amount')
                    ->label('Diskon')
                    ->money('IDR'),
                TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money('IDR'),
                TextColumn::make('kitchen_status')
                    ->label('Status Dapur')
                    ->badge()
                    ->formatStateUsing(static fn (?KitchenStatus $state): string => $state !== null ? ucfirst($state->value) : '-')
                    ->placeholder('-'),
            ]);
    }
}
