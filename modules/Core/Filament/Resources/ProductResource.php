<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources;

use App\Models\Company;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Models\Account;
use Modules\Core\Enums\ProductType;
use Modules\Core\Filament\Resources\ProductResource\Pages\CreateProduct;
use Modules\Core\Filament\Resources\ProductResource\Pages\EditProduct;
use Modules\Core\Filament\Resources\ProductResource\Pages\ListProducts;
use Modules\Core\Filament\Resources\ProductResource\RelationManagers\DiscountsRelationManager;
use Modules\Core\Filament\Resources\ProductResource\RelationManagers\VariantsRelationManager;
use Modules\Core\Models\Product;
use UnitEnum;

/**
 * Tenant-scoped catalog resource for managing products, variants, and promotional discounts.
 */
class ProductResource extends Resource
{
    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = Product::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    /**
     * Define the create/edit form schema for Product records.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Product Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->required()
                            ->alphaDash()
                            ->scopedUnique(ignoreRecord: true)
                            ->maxLength(255),
                        Select::make('category_id')
                            ->label('Category')
                            ->nullable()
                            ->searchable()
                            ->preload()
                            ->relationship('category', 'name'),
                        Select::make('type')
                            ->required()
                            ->default(ProductType::Goods->value)
                            ->options([
                                ProductType::Goods->value => 'Goods (Tracked Inventory)',
                                ProductType::Menu->value => 'F&B Menu',
                                ProductType::Service->value => 'Service',
                                ProductType::Membership->value => 'Membership',
                            ]),
                        Toggle::make('track_stock')
                            ->label('Track Stock')
                            ->default(true),
                        Toggle::make('show_on_web')
                            ->label('Show on Public Web')
                            ->default(false),
                        FileUpload::make('image_path')
                            ->image()
                            ->directory('products')
                            ->nullable()
                            ->columnSpanFull(),
                    ]),
                Section::make('Pemetaan Akun Akuntansi')
                    ->description('Tautkan akun pendapatan, HPP, dan persediaan untuk penjurnalan otomatis.')
                    ->columns(3)
                    ->visible(static fn (): bool => Filament::getTenant() instanceof Company && Filament::getTenant()->hasActiveModule('accounting'))
                    ->schema([
                        Select::make('income_account_id')
                            ->label('Income Account')
                            ->nullable()
                            ->searchable()
                            ->options(static fn (): array => static::getTenantAccountOptions(AccountType::Revenue)),
                        Select::make('cogs_account_id')
                            ->label('COGS Account (HPP)')
                            ->nullable()
                            ->searchable()
                            ->options(static fn (): array => static::getTenantAccountOptions(AccountType::Expense)),
                        Select::make('inventory_account_id')
                            ->label('Inventory Account')
                            ->nullable()
                            ->searchable()
                            ->options(static fn (): array => static::getTenantAccountOptions(AccountType::Asset)),
                    ]),
            ]);
    }

    /**
     * Resolve tenant-scoped Chart of Accounts select options for a given account type.
     *
     * @return array<int, string>
     */
    private static function getTenantAccountOptions(AccountType $type): array
    {
        return Account::query()
            ->where('type', $type->value)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(static fn (Account $account): array => [
                $account->getKey() => "{$account->code} - {$account->name}",
            ])
            ->all();
    }

    /**
     * Define the listing table schema for Product records.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->placeholder('-'),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(static fn (ProductType $state): string => ucfirst($state->value)),
                IconColumn::make('track_stock')
                    ->label('Stock Tracked')
                    ->boolean(),
                IconColumn::make('show_on_web')
                    ->label('Web')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        ProductType::Goods->value => 'Goods',
                        ProductType::Menu->value => 'Menu',
                        ProductType::Service->value => 'Service',
                        ProductType::Membership->value => 'Membership',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Register variant and discount relation managers for catalog products.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            VariantsRelationManager::class,
            DiscountsRelationManager::class,
        ];
    }

    /**
     * Register pages belonging to the Product resource.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
