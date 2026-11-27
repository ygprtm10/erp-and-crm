<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources;

use App\Models\Company;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Core\Filament\Resources\InventoryStockResource\Pages\ListInventoryStocks;
use Modules\Core\Filament\Resources\InventoryStockResource\Pages\ViewInventoryStock;
use Modules\Core\Filament\Resources\InventoryStockResource\RelationManagers\StockMovementsRelationManager;
use Modules\Core\Models\Branch;
use Modules\Core\Models\InventoryStock;
use Modules\Core\Models\ProductVariant;
use Modules\Core\Services\StockMovementService;
use UnitEnum;

/**
 * Tenant-scoped inventory resource exposing read-only balances and service-backed stock mutations.
 */
class InventoryStockResource extends Resource
{
    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = InventoryStock::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    /**
     * The navigation label shown in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Inventory Stocks';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Inventori';

    /**
     * Disable direct form creation so stock balances only change via StockMovementService.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Disable direct form editing of stock quantities.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Disable deleting inventory balance records directly.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Define the read-only detail infolist schema for a branch-variant stock balance.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Stock Balance Summary')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('branch.name')
                            ->label('Branch'),
                        TextEntry::make('variant.product.name')
                            ->label('Product'),
                        TextEntry::make('variant.name')
                            ->label('Variant'),
                        TextEntry::make('variant.sku')
                            ->label('SKU')
                            ->badge(),
                        TextEntry::make('quantity')
                            ->label('Current Quantity')
                            ->numeric(2),
                        TextEntry::make('min_stock_alert')
                            ->label('Min Stock Alert')
                            ->numeric(2),
                    ]),
            ]);
    }

    /**
     * Define the listing table schema and StockMovementService-backed row actions.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('branch.name')
                    ->label('Branch')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('variant.sku')
                    ->label('SKU')
                    ->badge()
                    ->searchable(),
                TextColumn::make('variant.product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('variant.name')
                    ->label('Variant'),
                TextColumn::make('quantity')
                    ->numeric(2)
                    ->sortable(),
                TextColumn::make('min_stock_alert')
                    ->label('Min Alert')
                    ->numeric(2),
                TextColumn::make('stock_status')
                    ->label('Status')
                    ->badge()
                    ->state(static fn (InventoryStock $record): string => static::isLowStock($record) ? 'Low Stock' : 'In Stock')
                    ->color(static fn (InventoryStock $record): string => static::isLowStock($record) ? 'danger' : 'success'),
            ])
            ->filters([
                SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->relationship('branch', 'name'),
                Filter::make('low_stock')
                    ->label('Low Stock Only')
                    ->query(static fn (Builder $query): Builder => $query->whereColumn('quantity', '<=', 'min_stock_alert')),
            ])
            ->recordActions([
                ViewAction::make(),
                ...static::getRowStockActions(),
            ]);
    }

    /**
     * Build the header action allowing initial or inbound stock entry for any branch and tracked variant.
     */
    public static function makeHeaderStockInAction(): Action
    {
        return Action::make('stockIn')
            ->label('Stock In')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('success')
            ->schema([
                Select::make('branch_id')
                    ->label('Branch')
                    ->required()
                    ->options(static fn (): array => Branch::query()->pluck('name', 'id')->all()),
                Select::make('product_variant_id')
                    ->label('Product Variant')
                    ->required()
                    ->searchable()
                    ->options(static fn (): array => ProductVariant::query()
                        ->with('product')
                        ->whereHas('product', static fn (Builder $query): Builder => $query->where('track_stock', true))
                        ->get()
                        ->mapWithKeys(static fn (ProductVariant $variant): array => [
                            $variant->getKey() => "{$variant->sku} - {$variant->product->name} ({$variant->name})",
                        ])
                        ->all()),
                TextInput::make('quantity')
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->step('0.01'),
                TextInput::make('unit_cost')
                    ->label('Unit Cost')
                    ->numeric()
                    ->required()
                    ->default('0.0000')
                    ->minValue(0)
                    ->prefix('IDR'),
                Textarea::make('notes')
                    ->rows(2)
                    ->nullable(),
            ])
            ->action(function (array $data, Action $action): void {
                $tenant = Filament::getTenant();

                if (! $tenant instanceof Company) {
                    $action->halt();

                    return;
                }

                $branch = Branch::query()->find((int) ($data['branch_id'] ?? 0));
                $variant = ProductVariant::query()->find((int) ($data['product_variant_id'] ?? 0));

                if (! $branch instanceof Branch || ! $variant instanceof ProductVariant) {
                    static::notifyError('Invalid branch or product variant for this tenant.', $action);

                    return;
                }

                try {
                    app(StockMovementService::class)->recordStockIn(
                        company: $tenant,
                        branch: $branch,
                        variant: $variant,
                        quantity: (string) $data['quantity'],
                        unitCost: (string) ($data['unit_cost'] ?? '0.0000'),
                        notes: $data['notes'] ?? null,
                    );

                    Notification::make()->title('Inbound stock recorded successfully.')->success()->send();
                } catch (DomainException|InvalidArgumentException $exception) {
                    static::notifyError($exception->getMessage(), $action);
                }
            });
    }

    /**
     * Build shared row and view-page actions for Stock In, Stock Out, Opname Adjustment, and Transfer.
     *
     * @return array<int, Action>
     */
    public static function getRowStockActions(): array
    {
        return [
            Action::make('recordStockIn')
                ->label('Stock In')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->color('success')
                ->schema([
                    TextInput::make('quantity')->numeric()->required()->minValue(0.01)->step('0.01'),
                    TextInput::make('unit_cost')->numeric()->required()->default('0.0000')->minValue(0)->prefix('IDR'),
                    Textarea::make('notes')->rows(2)->nullable(),
                ])
                ->action(function (InventoryStock $record, array $data, Action $action): void {
                    $tenant = Filament::getTenant();

                    if (! $tenant instanceof Company) {
                        $action->halt();

                        return;
                    }

                    try {
                        app(StockMovementService::class)->recordStockIn(
                            company: $tenant,
                            branch: $record->branch,
                            variant: $record->variant,
                            quantity: (string) $data['quantity'],
                            unitCost: (string) ($data['unit_cost'] ?? '0.0000'),
                            notes: $data['notes'] ?? null,
                        );

                        Notification::make()->title('Inbound stock recorded.')->success()->send();
                    } catch (DomainException|InvalidArgumentException $exception) {
                        static::notifyError($exception->getMessage(), $action);
                    }
                }),

            Action::make('recordStockOut')
                ->label('Stock Out')
                ->icon(Heroicon::OutlinedMinusCircle)
                ->color('warning')
                ->schema([
                    TextInput::make('quantity')->numeric()->required()->minValue(0.01)->step('0.01'),
                    Textarea::make('notes')->rows(2)->nullable(),
                ])
                ->action(function (InventoryStock $record, array $data, Action $action): void {
                    $tenant = Filament::getTenant();

                    if (! $tenant instanceof Company) {
                        $action->halt();

                        return;
                    }

                    try {
                        app(StockMovementService::class)->recordStockOut(
                            company: $tenant,
                            branch: $record->branch,
                            variant: $record->variant,
                            quantity: (string) $data['quantity'],
                            notes: $data['notes'] ?? null,
                        );

                        Notification::make()->title('Outbound stock deducted.')->success()->send();
                    } catch (DomainException|InvalidArgumentException $exception) {
                        static::notifyError($exception->getMessage(), $action);
                    }
                }),

            Action::make('adjustStock')
                ->label('Adjust / Opname')
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->color('info')
                ->schema([
                    TextInput::make('target_quantity')
                        ->label('Actual Physical Quantity')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->step('0.01'),
                    Textarea::make('notes')->rows(2)->nullable(),
                ])
                ->action(function (InventoryStock $record, array $data, Action $action): void {
                    $tenant = Filament::getTenant();

                    if (! $tenant instanceof Company) {
                        $action->halt();

                        return;
                    }

                    try {
                        app(StockMovementService::class)->adjustStock(
                            company: $tenant,
                            branch: $record->branch,
                            variant: $record->variant,
                            targetQuantity: (string) $data['target_quantity'],
                            notes: $data['notes'] ?? null,
                        );

                        Notification::make()->title('Stock quantity reconciled.')->success()->send();
                    } catch (DomainException|InvalidArgumentException $exception) {
                        static::notifyError($exception->getMessage(), $action);
                    }
                }),

            Action::make('transferStock')
                ->label('Transfer Branch')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->color('gray')
                ->schema([
                    Select::make('destination_branch_id')
                        ->label('Destination Branch')
                        ->required()
                        ->options(static fn (InventoryStock $record): array => Branch::query()
                            ->whereKeyNot($record->branch_id)
                            ->pluck('name', 'id')
                            ->all()),
                    TextInput::make('quantity')->numeric()->required()->minValue(0.01)->step('0.01'),
                    Textarea::make('notes')->rows(2)->nullable(),
                ])
                ->action(function (InventoryStock $record, array $data, Action $action): void {
                    $tenant = Filament::getTenant();

                    if (! $tenant instanceof Company) {
                        $action->halt();

                        return;
                    }

                    $destination = Branch::query()->find((int) ($data['destination_branch_id'] ?? 0));

                    if (! $destination instanceof Branch) {
                        static::notifyError('Destination branch does not belong to this company.', $action);

                        return;
                    }

                    try {
                        app(StockMovementService::class)->transferStock(
                            company: $tenant,
                            sourceBranch: $record->branch,
                            destinationBranch: $destination,
                            variant: $record->variant,
                            quantity: (string) $data['quantity'],
                            notes: $data['notes'] ?? null,
                        );

                        Notification::make()->title('Stock transferred between branches.')->success()->send();
                    } catch (DomainException|InvalidArgumentException $exception) {
                        static::notifyError($exception->getMessage(), $action);
                    }
                }),
        ];
    }

    /**
     * Register the read-only stock movement audit trail relation manager.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            StockMovementsRelationManager::class,
        ];
    }

    /**
     * Register pages belonging to the InventoryStock resource.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListInventoryStocks::route('/'),
            'view' => ViewInventoryStock::route('/{record}'),
        ];
    }

    /**
     * Determine whether the current stock balance is at or below its alert threshold.
     */
    private static function isLowStock(InventoryStock $record): bool
    {
        return bccomp((string) $record->quantity, (string) $record->min_stock_alert, 2) <= 0;
    }

    /**
     * Display a danger notification and halt the Filament action cleanly.
     */
    private static function notifyError(string $message, Action $action): void
    {
        Notification::make()
            ->title($message)
            ->danger()
            ->send();

        $action->halt();
    }
}
