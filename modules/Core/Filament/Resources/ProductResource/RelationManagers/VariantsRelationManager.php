<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\ProductResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Core\Enums\ProductUnit;

/**
 * Manages SKU variants, barcode, measurement unit, cost price, and selling price for a product.
 */
class VariantsRelationManager extends RelationManager
{
    /**
     * The Eloquent relationship name on the parent Product model.
     */
    protected static string $relationship = 'variants';

    /**
     * Define the create/edit form schema for product variants.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('sku')
                    ->label('SKU')
                    ->required()
                    ->scopedUnique(ignoreRecord: true)
                    ->maxLength(64),
                TextInput::make('barcode')
                    ->nullable()
                    ->maxLength(64),
                Select::make('unit')
                    ->required()
                    ->default(ProductUnit::Pcs->value)
                    ->options([
                        ProductUnit::Pcs->value => 'pcs',
                        ProductUnit::Kg->value => 'kg',
                        ProductUnit::M2->value => 'm2',
                        ProductUnit::Visit->value => 'visit',
                        ProductUnit::Paket->value => 'paket',
                    ]),
                TextInput::make('cost_price')
                    ->label('Cost Price (HPP)')
                    ->numeric()
                    ->required()
                    ->default('0.0000')
                    ->minValue(0)
                    ->prefix('IDR'),
                TextInput::make('selling_price')
                    ->label('Selling Price')
                    ->numeric()
                    ->required()
                    ->default('0.0000')
                    ->minValue(0)
                    ->prefix('IDR'),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    /**
     * Define the listing table schema for product variants.
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->badge()
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('barcode')
                    ->placeholder('-'),
                TextColumn::make('unit')
                    ->badge()
                    ->formatStateUsing(static fn (ProductUnit $state): string => $state->value),
                TextColumn::make('cost_price')
                    ->label('Cost (HPP)')
                    ->money('IDR'),
                TextColumn::make('selling_price')
                    ->label('Price')
                    ->money('IDR'),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
