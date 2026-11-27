<?php

declare(strict_types=1);

namespace Modules\Transaction\Filament\Resources;

use App\Models\Company;
use App\Models\User;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
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
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Models\Account;
use Modules\Transaction\Enums\OrderChannel;
use Modules\Transaction\Enums\OrderStatus;
use Modules\Transaction\Enums\PaymentMethod;
use Modules\Transaction\Enums\PaymentStatus;
use Modules\Transaction\Enums\PaymentTransactionStatus;
use Modules\Transaction\Filament\Resources\OrderResource\Pages\ListOrders;
use Modules\Transaction\Filament\Resources\OrderResource\Pages\ViewOrder;
use Modules\Transaction\Filament\Resources\OrderResource\RelationManagers\OrderItemsRelationManager;
use Modules\Transaction\Filament\Resources\OrderResource\RelationManagers\OrderLogsRelationManager;
use Modules\Transaction\Filament\Resources\OrderResource\RelationManagers\OrderPaymentsRelationManager;
use Modules\Transaction\Filament\Resources\OrderResource\RelationManagers\OrderStagesRelationManager;
use Modules\Transaction\Models\Order;
use Modules\Transaction\Services\OrderCreationService;
use Modules\Transaction\Services\OrderPaymentService;
use Modules\Transaction\Services\OrderStageService;
use UnitEnum;

/**
 * Tenant-scoped unified Order & Invoice management resource with payment, stage, and cancel actions.
 */
class OrderResource extends Resource
{
    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = Order::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    /**
     * The navigation label displayed in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Pesanan & Transaksi';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Transaksi & POS';

    /**
     * Orders are created via PosCashierPage or transaction services rather than raw CRUD forms.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Prevent direct raw record editing outside controlled service actions.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Prevent deleting transaction records to preserve financial audit integrity.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Define the read-only detail infolist schema for viewing an order.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Pesanan')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('order_number')
                            ->label('Nomor Order')
                            ->badge(),
                        TextEntry::make('channel')
                            ->label('Kanal')
                            ->badge()
                            ->formatStateUsing(static fn (OrderChannel $state): string => static::formatChannelLabel($state))
                            ->color(static fn (OrderChannel $state): string => static::channelColor($state)),
                        TextEntry::make('branch.name')
                            ->label('Cabang'),
                        TextEntry::make('order_status')
                            ->label('Status Pesanan')
                            ->badge()
                            ->formatStateUsing(static fn (OrderStatus $state): string => ucfirst($state->value))
                            ->color(static fn (OrderStatus $state): string => static::orderStatusColor($state)),
                        TextEntry::make('payment_status')
                            ->label('Status Pembayaran')
                            ->badge()
                            ->formatStateUsing(static fn (PaymentStatus $state): string => ucfirst($state->value))
                            ->color(static fn (PaymentStatus $state): string => static::paymentStatusColor($state)),
                        TextEntry::make('contact.name')
                            ->label('Pelanggan')
                            ->placeholder('Walk-in'),
                        TextEntry::make('posTable.table_number')
                            ->label('Meja')
                            ->placeholder('-'),
                        TextEntry::make('rack_location')
                            ->label('Lokasi Rak')
                            ->placeholder('-'),
                        TextEntry::make('estimated_ready_at')
                            ->label('Estimasi Selesai')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('notes')
                            ->label('Catatan')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
                Section::make('Ringkasan Finansial')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('subtotal')
                            ->label('Subtotal')
                            ->money('IDR'),
                        TextEntry::make('discount_total')
                            ->label('Total Diskon')
                            ->money('IDR'),
                        TextEntry::make('tax_total')
                            ->label('Pajak')
                            ->money('IDR'),
                        TextEntry::make('grand_total')
                            ->label('Grand Total')
                            ->money('IDR')
                            ->weight('bold'),
                    ]),
            ]);
    }

    /**
     * Define the listing table schema, filters, and workflow actions for orders.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('order_number')
                    ->label('Nomor Order')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('channel')
                    ->label('Kanal')
                    ->badge()
                    ->formatStateUsing(static fn (OrderChannel $state): string => static::formatChannelLabel($state))
                    ->color(static fn (OrderChannel $state): string => static::channelColor($state)),
                TextColumn::make('branch.name')
                    ->label('Cabang')
                    ->toggleable(),
                TextColumn::make('contact.name')
                    ->label('Pelanggan')
                    ->placeholder('Walk-in')
                    ->searchable(),
                TextColumn::make('order_status')
                    ->label('Status Pesanan')
                    ->badge()
                    ->formatStateUsing(static fn (OrderStatus $state): string => ucfirst($state->value))
                    ->color(static fn (OrderStatus $state): string => static::orderStatusColor($state)),
                TextColumn::make('payment_status')
                    ->label('Pembayaran')
                    ->badge()
                    ->formatStateUsing(static fn (PaymentStatus $state): string => ucfirst($state->value))
                    ->color(static fn (PaymentStatus $state): string => static::paymentStatusColor($state)),
                TextColumn::make('grand_total')
                    ->label('Grand Total')
                    ->money('IDR')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Tanggal')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('channel')
                    ->label('Kanal')
                    ->options(collect(OrderChannel::cases())->mapWithKeys(
                        static fn (OrderChannel $channel): array => [$channel->value => static::formatChannelLabel($channel)],
                    )->all()),
                SelectFilter::make('order_status')
                    ->label('Status Pesanan')
                    ->options(collect(OrderStatus::cases())->mapWithKeys(
                        static fn (OrderStatus $status): array => [$status->value => ucfirst($status->value)],
                    )->all()),
                SelectFilter::make('payment_status')
                    ->label('Status Pembayaran')
                    ->options(collect(PaymentStatus::cases())->mapWithKeys(
                        static fn (PaymentStatus $status): array => [$status->value => ucfirst($status->value)],
                    )->all()),
                Filter::make('created_at')
                    ->label('Rentang Tanggal')
                    ->schema([
                        DatePicker::make('from')->label('Dari Tanggal'),
                        DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                static fn (Builder $builder, string $date): Builder => $builder->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                static fn (Builder $builder, string $date): Builder => $builder->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                ...static::getWorkflowActions(),
            ]);
    }

    /**
     * Build shared workflow actions for payment recording, stage updates, and cancellation.
     *
     * @return array<int, Action>
     */
    public static function getWorkflowActions(): array
    {
        return [
            Action::make('recordPayment')
                ->label('Catat Pembayaran')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->visible(static fn (Order $record): bool => $record->order_status !== OrderStatus::Cancelled
                    && ! in_array($record->payment_status, [PaymentStatus::Paid, PaymentStatus::Refunded], true))
                ->schema([
                    Select::make('payment_method')
                        ->label('Metode Pembayaran')
                        ->required()
                        ->default(PaymentMethod::Cash->value)
                        ->options([
                            PaymentMethod::Cash->value => 'Tunai (Cash)',
                            PaymentMethod::Qris->value => 'QRIS',
                            PaymentMethod::Va->value => 'Virtual Account',
                            PaymentMethod::Transfer->value => 'Transfer Bank',
                            PaymentMethod::Quota->value => 'Kuota Membership',
                        ]),
                    Select::make('account_id')
                        ->label('Akun Kas / Bank')
                        ->nullable()
                        ->visible(static function (): bool {
                            $tenant = Filament::getTenant();

                            return $tenant instanceof Company && $tenant->hasActiveModule('accounting');
                        })
                        ->options(static fn (): array => Account::query()
                            ->where('type', AccountType::Asset->value)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(static fn (Account $account): array => [
                                $account->getKey() => "{$account->code} - {$account->name}",
                            ])
                            ->all()),
                    TextInput::make('amount')
                        ->label('Nominal Pembayaran')
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->prefix('IDR')
                        ->default(static fn (Order $record): string => static::resolveRemainingBalance($record)),
                    Textarea::make('notes')
                        ->label('Catatan')
                        ->rows(2)
                        ->nullable(),
                ])
                ->action(function (Order $record, array $data, Action $action): void {
                    /** @var User|null $user */
                    $user = Auth::user();
                    $account = isset($data['account_id']) && $data['account_id'] !== null
                        ? Account::query()->where('type', AccountType::Asset->value)->find((int) $data['account_id'])
                        : null;

                    try {
                        app(OrderPaymentService::class)->recordManualPayment(
                            order: $record,
                            method: PaymentMethod::from((string) $data['payment_method']),
                            amount: (string) $data['amount'],
                            account: $account,
                            recordedBy: $user,
                            notes: $data['notes'] ?? null,
                        );

                        Notification::make()
                            ->title('Pembayaran berhasil dicatat.')
                            ->success()
                            ->send();
                    } catch (DomainException|InvalidArgumentException $exception) {
                        Notification::make()
                            ->title($exception->getMessage())
                            ->danger()
                            ->send();

                        $action->halt();
                    }
                }),
            Action::make('updateStage')
                ->label('Update Tahapan Pengerjaan')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('info')
                ->visible(static fn (Order $record): bool => $record->order_status !== OrderStatus::Cancelled)
                ->schema([
                    TextInput::make('stage_name')
                        ->label('Nama Tahapan')
                        ->required()
                        ->maxLength(64)
                        ->placeholder('Misal: Dicuci / Disetrika / Siap Diambil'),
                    TextInput::make('rack_location')
                        ->label('Lokasi Rak')
                        ->nullable()
                        ->maxLength(64)
                        ->default(static fn (Order $record): ?string => $record->rack_location),
                    Textarea::make('notes')
                        ->label('Catatan Tahapan')
                        ->rows(2)
                        ->nullable(),
                ])
                ->action(function (Order $record, array $data, Action $action): void {
                    /** @var User|null $user */
                    $user = Auth::user();

                    try {
                        app(OrderStageService::class)->startStage(
                            order: $record,
                            stageName: (string) $data['stage_name'],
                            notes: $data['notes'] ?? null,
                            actor: $user,
                            rackLocation: $data['rack_location'] ?? null,
                        );

                        Notification::make()
                            ->title('Tahapan pengerjaan berhasil diperbarui.')
                            ->success()
                            ->send();
                    } catch (DomainException|InvalidArgumentException $exception) {
                        Notification::make()
                            ->title($exception->getMessage())
                            ->danger()
                            ->send();

                        $action->halt();
                    }
                }),
            Action::make('cancelOrder')
                ->label('Batalkan Pesanan')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Batalkan Pesanan')
                ->modalDescription('Pesanan yang dibatalkan tidak dapat diproses pembayarannya lagi.')
                ->visible(static fn (Order $record): bool => $record->order_status !== OrderStatus::Cancelled
                    && $record->order_status !== OrderStatus::Completed
                    && ! in_array($record->payment_status, [PaymentStatus::Paid, PaymentStatus::Refunded], true))
                ->schema([
                    Textarea::make('reason')
                        ->label('Alasan Pembatalan')
                        ->rows(2)
                        ->nullable(),
                ])
                ->action(function (Order $record, array $data, Action $action): void {
                    /** @var User|null $user */
                    $user = Auth::user();

                    try {
                        app(OrderCreationService::class)->cancelOrder(
                            order: $record,
                            actor: $user,
                            reason: $data['reason'] ?? null,
                        );

                        Notification::make()
                            ->title('Pesanan berhasil dibatalkan.')
                            ->warning()
                            ->send();
                    } catch (DomainException|InvalidArgumentException $exception) {
                        Notification::make()
                            ->title($exception->getMessage())
                            ->danger()
                            ->send();

                        $action->halt();
                    }
                }),
        ];
    }

    /**
     * Register read-only relation managers displayed on ViewOrder.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            OrderItemsRelationManager::class,
            OrderPaymentsRelationManager::class,
            OrderStagesRelationManager::class,
            OrderLogsRelationManager::class,
        ];
    }

    /**
     * Register pages belonging to the Order resource.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }

    /**
     * Compute the remaining unpaid balance for prefilling the manual payment modal.
     */
    private static function resolveRemainingBalance(Order $record): string
    {
        $paidTotal = '0.0000';
        $paidAmounts = $record->payments()
            ->withoutGlobalScope('company')
            ->where('status', PaymentTransactionStatus::Paid->value)
            ->pluck('amount');

        foreach ($paidAmounts as $amount) {
            $paidTotal = bcadd($paidTotal, (string) $amount, 4);
        }

        $remaining = bcsub((string) $record->grand_total, $paidTotal, 4);

        return bccomp($remaining, '0.0000', 4) > 0 ? $remaining : '0.0000';
    }

    /**
     * Format OrderChannel enum values into concise display labels.
     */
    private static function formatChannelLabel(OrderChannel $channel): string
    {
        return match ($channel) {
            OrderChannel::Pos => 'POS',
            OrderChannel::QrOrder => 'QR Order',
            OrderChannel::Web => 'Web',
            OrderChannel::Invoice => 'Invoice',
            OrderChannel::Membership => 'Membership',
            OrderChannel::Laundry => 'Laundry',
        };
    }

    /**
     * Map OrderChannel enum values to Filament semantic badge colors.
     */
    private static function channelColor(OrderChannel $channel): string
    {
        return match ($channel) {
            OrderChannel::Pos => 'primary',
            OrderChannel::QrOrder => 'info',
            OrderChannel::Web => 'success',
            OrderChannel::Invoice => 'warning',
            OrderChannel::Membership, OrderChannel::Laundry => 'gray',
        };
    }

    /**
     * Map OrderStatus enum values to Filament semantic badge colors.
     */
    private static function orderStatusColor(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Draft => 'gray',
            OrderStatus::Pending => 'warning',
            OrderStatus::Cooking, OrderStatus::Served => 'info',
            OrderStatus::Completed => 'success',
            OrderStatus::Cancelled => 'danger',
        };
    }

    /**
     * Map PaymentStatus enum values to Filament semantic badge colors.
     */
    private static function paymentStatusColor(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Unpaid => 'danger',
            PaymentStatus::Partial => 'warning',
            PaymentStatus::Paid => 'success',
            PaymentStatus::Refunded => 'gray',
        };
    }
}
