<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\ProductResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Core\Enums\DiscountType;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductDiscount;

/**
 * Manages scheduled variant-level promotional discounts attached to a parent product.
 */
class DiscountsRelationManager extends RelationManager
{
    /**
     * The Eloquent HasManyThrough relationship name on the parent Product model.
     */
    protected static string $relationship = 'discounts';

    /**
     * Define the create/edit form schema for product variant discounts.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_variant_id')
                    ->label('Variant')
                    ->required()
                    ->options(function (): array {
                        /** @var Product $product */
                        $product = $this->getOwnerRecord();

                        return $product->variants()->pluck('name', 'id')->all();
                    }),
                Select::make('discount_type')
                    ->required()
                    ->options([
                        DiscountType::Percent->value => 'Percentage (%)',
                        DiscountType::Fixed->value => 'Fixed Amount (IDR)',
                    ]),
                TextInput::make('discount_value')
                    ->numeric()
                    ->required()
                    ->minValue(0),
                DateTimePicker::make('starts_at')
                    ->nullable(),
                DateTimePicker::make('ends_at')
                    ->nullable(),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    /**
     * Define the listing table schema for product discounts.
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('variant.name')
                    ->label('Variant'),
                TextColumn::make('discount_type')
                    ->badge()
                    ->formatStateUsing(static fn (DiscountType $state): string => ucfirst($state->value)),
                TextColumn::make('discount_value')
                    ->numeric(2),
                TextColumn::make('starts_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextColumn::make('ends_at')
                    ->dateTime()
                    ->placeholder('-'),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(static fn (array $data): ProductDiscount => ProductDiscount::query()->create($data)),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
