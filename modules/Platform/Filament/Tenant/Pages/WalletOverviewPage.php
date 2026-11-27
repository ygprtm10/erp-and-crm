<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Tenant\Pages;

use App\Models\Company;
use App\Models\User;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Modules\Platform\Enums\PaymentGatewayMode;
use Modules\Platform\Enums\WalletTransactionType;
use Modules\Platform\Models\CompanySetting;
use Modules\Platform\Models\SaasTenantBankAccount;
use Modules\Platform\Models\SaasTenantWallet;
use Modules\Platform\Models\SaasWalletTransaction;
use Modules\Platform\Services\WalletLedgerService;
use UnitEnum;

/**
 * Displays the active tenant's escrow wallet balances, transaction ledger, and withdrawal modal.
 */
class WalletOverviewPage extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * The Blade view rendered by this Filament page.
     */
    protected string $view = 'platform::filament.tenant.pages.wallet-overview';

    /**
     * The sidebar navigation icon for this page.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    /**
     * The navigation label shown in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Wallet & Withdraw';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Billing & Payouts';

    /**
     * The page title displayed in the header.
     */
    protected static ?string $title = 'Platform Escrow Wallet';

    /**
     * Allow access only when the tenant uses platform_midtrans or holds a positive wallet balance.
     */
    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return false;
        }

        $settings = CompanySetting::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tenant->getKey())
            ->first();

        if ($settings === null || $settings->payment_gateway_mode === PaymentGatewayMode::PlatformMidtrans) {
            return true;
        }

        $wallet = SaasTenantWallet::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tenant->getKey())
            ->first();

        if ($wallet === null) {
            return false;
        }

        return bccomp((string) $wallet->available_balance, '0.0000', 4) > 0
            || bccomp((string) $wallet->pending_balance, '0.0000', 4) > 0;
    }

    /**
     * Return the formatted available balance string for Blade display.
     */
    #[Computed]
    public function formattedAvailableBalance(): string
    {
        $wallet = $this->resolveWallet();

        return $this->formatRupiah($wallet !== null ? (string) $wallet->available_balance : '0.0000');
    }

    /**
     * Return the formatted pending withdrawal balance string for Blade display.
     */
    #[Computed]
    public function formattedPendingBalance(): string
    {
        $wallet = $this->resolveWallet();

        return $this->formatRupiah($wallet !== null ? (string) $wallet->pending_balance : '0.0000');
    }

    /**
     * Return the active payment gateway mode label for Blade badge display.
     */
    #[Computed]
    public function gatewayStatusLabel(): string
    {
        return $this->resolveGatewayMode()->label();
    }

    /**
     * Return the semantic Filament badge color for the current payment gateway mode.
     */
    #[Computed]
    public function gatewayStatusColor(): string
    {
        return $this->resolveGatewayMode() === PaymentGatewayMode::PlatformMidtrans
            ? 'success'
            : 'info';
    }

    /**
     * Configure the Filament table displaying append-only wallet transactions for the active tenant.
     */
    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();
        $companyId = $tenant instanceof Company ? $tenant->getKey() : 0;

        return $table
            ->query(
                SaasWalletTransaction::query()
                    ->where('company_id', $companyId)
                    ->latest('id'),
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(static fn (WalletTransactionType $state): string => $state->value)
                    ->color(static fn (WalletTransactionType $state): string => match ($state) {
                        WalletTransactionType::CreditSale => 'success',
                        WalletTransactionType::DebitWithdraw, WalletTransactionType::DebitFee => 'warning',
                        WalletTransactionType::RefundReversal => 'danger',
                        WalletTransactionType::Adjustment => 'info',
                    }),
                TextColumn::make('amount')
                    ->money('IDR'),
                TextColumn::make('balance_before')
                    ->label('Before')
                    ->money('IDR'),
                TextColumn::make('balance_after')
                    ->label('After')
                    ->money('IDR'),
                TextColumn::make('description')
                    ->searchable()
                    ->wrap(),
            ]);
    }

    /**
     * Define the modal action allowing the tenant to request an escrow balance withdrawal.
     */
    public function requestWithdrawalAction(): Action
    {
        return Action::make('requestWithdrawal')
            ->label('Request Withdraw')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('primary')
            ->modalHeading('Request Escrow Withdrawal')
            ->modalDescription('Funds will be moved to pending balance until Super Admin completes the bank transfer.')
            ->schema([
                Select::make('bank_account_id')
                    ->label('Destination Bank Account')
                    ->required()
                    ->options(function (): array {
                        $tenant = Filament::getTenant();

                        if (! $tenant instanceof Company) {
                            return [];
                        }

                        return SaasTenantBankAccount::query()
                            ->where('company_id', $tenant->getKey())
                            ->get()
                            ->mapWithKeys(static fn (SaasTenantBankAccount $account): array => [
                                $account->getKey() => "{$account->bank_name} - {$account->account_number} ({$account->account_holder_name})",
                            ])
                            ->all();
                    }),
                TextInput::make('amount')
                    ->label('Withdrawal Amount (IDR)')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->prefix('IDR'),
                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->nullable(),
            ])
            ->action(function (array $data, Action $action): void {
                $tenant = Filament::getTenant();
                $user = Auth::user();

                if (! $tenant instanceof Company || ! $user instanceof User) {
                    $action->halt();

                    return;
                }

                /** Enforce strict tenant ownership on the selected bank account before calling the service. */
                $bankAccount = SaasTenantBankAccount::query()
                    ->where('company_id', $tenant->getKey())
                    ->find((int) ($data['bank_account_id'] ?? 0));

                if (! $bankAccount instanceof SaasTenantBankAccount) {
                    Notification::make()
                        ->title('Selected bank account does not belong to this company.')
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                try {
                    app(WalletLedgerService::class)->requestWithdrawal(
                        company: $tenant,
                        bankAccount: $bankAccount,
                        requestedBy: $user,
                        amount: (string) $data['amount'],
                        adminFee: '0.0000',
                        notes: $data['notes'] ?? null,
                    );

                    Notification::make()
                        ->title('Withdrawal request submitted successfully.')
                        ->success()
                        ->send();
                } catch (DomainException|InvalidArgumentException $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();

                    $action->halt();
                }
            });
    }

    /**
     * Resolve the active tenant's wallet record if present.
     */
    private function resolveWallet(): ?SaasTenantWallet
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return null;
        }

        return SaasTenantWallet::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tenant->getKey())
            ->first();
    }

    /**
     * Resolve the active tenant's configured payment gateway mode.
     */
    private function resolveGatewayMode(): PaymentGatewayMode
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return PaymentGatewayMode::PlatformMidtrans;
        }

        $settings = CompanySetting::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tenant->getKey())
            ->first();

        return $settings?->payment_gateway_mode ?? PaymentGatewayMode::PlatformMidtrans;
    }

    /**
     * Format a decimal string as an IDR currency display string.
     */
    private function formatRupiah(string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }
}
