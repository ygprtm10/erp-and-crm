<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Core\Enums\DiscountType;
use Modules\Core\Filament\Resources\VoucherResource\Pages\CreateVoucher;
use Modules\Core\Filament\Resources\VoucherResource\Pages\EditVoucher;
use Modules\Core\Filament\Resources\VoucherResource\Pages\ListVouchers;
use Modules\Core\Models\Voucher;
use UnitEnum;

/**
 * Tenant-scoped resource for managing promotional coupon vouchers.
 */
class VoucherResource extends Resource
{
    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = Voucher::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    /**
     * Define the create/edit form schema for Voucher records.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Voucher Configuration')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->scopedUnique(ignoreRecord: true)
                            ->maxLength(64),
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
                        TextInput::make('min_order_amount')
                            ->numeric()
                            ->required()
                            ->default('0.0000')
                            ->minValue(0)
                            ->prefix('IDR'),
                        TextInput::make('quota')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(0),
                        TextInput::make('used_count')
                            ->numeric()
                            ->integer()
                            ->default(0)
                            ->disabledOn('edit'),
                        DateTimePicker::make('starts_at')
                            ->nullable(),
                        DateTimePicker::make('ends_at')
                            ->nullable(),
                    ]),
            ]);
    }

    /**
     * Define the listing table schema for Voucher records with dynamic status badges.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('discount_type')
                    ->badge()
                    ->formatStateUsing(static fn (DiscountType $state): string => ucfirst($state->value)),
                TextColumn::make('discount_value')
                    ->numeric(2),
                TextColumn::make('min_order_amount')
                    ->money('IDR'),
                TextColumn::make('used_count')
                    ->label('Usage')
                    ->formatStateUsing(static fn (Voucher $record): string => "{$record->used_count} / {$record->quota}"),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(static fn (Voucher $record): string => static::resolveStatusLabel($record))
                    ->color(static fn (Voucher $record): string => match (static::resolveStatusLabel($record)) {
                        'Active' => 'success',
                        'Scheduled' => 'info',
                        'Quota Exhausted' => 'warning',
                        default => 'danger',
                    }),
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
     * Register pages belonging to the Voucher resource.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListVouchers::route('/'),
            'create' => CreateVoucher::route('/create'),
            'edit' => EditVoucher::route('/{record}/edit'),
        ];
    }

    /**
     * Compute the effective runtime status label for a voucher record.
     */
    private static function resolveStatusLabel(Voucher $record): string
    {
        $now = now();

        if ($record->ends_at !== null && $record->ends_at->lt($now)) {
            return 'Expired';
        }

        if ($record->used_count >= $record->quota) {
            return 'Quota Exhausted';
        }

        if ($record->starts_at !== null && $record->starts_at->gt($now)) {
            return 'Scheduled';
        }

        return 'Active';
    }
}
