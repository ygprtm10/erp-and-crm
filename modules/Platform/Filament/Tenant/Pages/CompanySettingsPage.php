<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Tenant\Pages;

use App\Models\Company;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use Modules\Platform\Actions\UpdateCompanySettingsAction;
use Modules\Platform\Enums\PaymentGatewayMode;
use Modules\Platform\Models\CompanySetting;
use UnitEnum;

/**
 * Allows the active tenant to configure general preferences, Fonnte token, and Midtrans gateway mode.
 *
 * @property-read Schema $form
 */
class CompanySettingsPage extends Page
{
    /**
     * Livewire form state container for company settings.
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * The Blade view rendered by this Filament page.
     */
    protected string $view = 'platform::filament.tenant.pages.company-settings';

    /**
     * The sidebar navigation icon for this page.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    /**
     * The navigation label shown in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Company Settings';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    /**
     * The page title displayed in the header.
     */
    protected static ?string $title = 'Company Settings';

    /**
     * Populate the settings form with the active tenant's current configuration.
     */
    public function mount(): void
    {
        $settings = $this->resolveCurrentTenantSettings();

        $this->form->fill([
            'currency' => $settings->currency,
            'tax_rate' => $settings->tax_rate,
            'timezone' => $settings->timezone,
            'invoice_prefix' => $settings->invoice_prefix,
            'fonnte_token' => $settings->fonnte_token,
            'payment_gateway_mode' => $settings->payment_gateway_mode->value,
            'midtrans_is_production' => $settings->midtrans_is_production,
            'midtrans_merchant_id' => $settings->midtrans_merchant_id,
            'midtrans_server_key' => $settings->midtrans_server_key,
            'midtrans_client_key' => $settings->midtrans_client_key,
            'enable_pph21' => $settings->enable_pph21,
            'enable_bpjs_kesehatan' => $settings->enable_bpjs_kesehatan,
            'enable_bpjs_ketenagakerjaan' => $settings->enable_bpjs_ketenagakerjaan,
            'bpjs_jkk_rate' => $settings->bpjs_jkk_rate ?? '0.0024',
        ]);
    }

    /**
     * Bind the default schema state path and model context for the settings form.
     */
    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->operation('edit')
            ->model($this->resolveCurrentTenantSettings())
            ->statePath('data');
    }

    /**
     * Define the company settings form schema.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General Preferences')
                    ->columns(2)
                    ->schema([
                        Select::make('currency')
                            ->label('Default Currency')
                            ->required()
                            ->options([
                                'IDR' => 'IDR - Indonesian Rupiah',
                                'USD' => 'USD - US Dollar',
                                'SGD' => 'SGD - Singapore Dollar',
                            ]),
                        TextInput::make('tax_rate')
                            ->label('Default Tax Rate (%)')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step('0.01'),
                        Select::make('timezone')
                            ->label('Timezone')
                            ->required()
                            ->options([
                                'Asia/Jakarta' => 'Asia/Jakarta (WIB)',
                                'Asia/Makassar' => 'Asia/Makassar (WITA)',
                                'Asia/Jayapura' => 'Asia/Jayapura (WIT)',
                                'UTC' => 'UTC',
                            ]),
                        TextInput::make('invoice_prefix')
                            ->label('Invoice Prefix')
                            ->required()
                            ->maxLength(20),
                    ]),

                Section::make('WhatsApp Integration (Fonnte)')
                    ->schema([
                        TextInput::make('fonnte_token')
                            ->label('Fonnte API Token')
                            ->password()
                            ->revealable()
                            ->nullable()
                            ->helperText('Stored encrypted at rest for automated WhatsApp notifications.'),
                    ]),

                Section::make('Payment Gateway Mode')
                    ->description('Choose between Platform Escrow Midtrans or your own Midtrans merchant credentials.')
                    ->columns(2)
                    ->schema([
                        Select::make('payment_gateway_mode')
                            ->label('Gateway Mode')
                            ->required()
                            ->live()
                            ->columnSpanFull()
                            ->options([
                                PaymentGatewayMode::PlatformMidtrans->value => PaymentGatewayMode::PlatformMidtrans->label(),
                                PaymentGatewayMode::OwnMidtrans->value => PaymentGatewayMode::OwnMidtrans->label(),
                            ]),
                        Toggle::make('midtrans_is_production')
                            ->label('Production Environment')
                            ->visible(static fn (Get $get): bool => static::isOwnMidtransSelected($get))
                            ->columnSpanFull(),
                        TextInput::make('midtrans_merchant_id')
                            ->label('Midtrans Merchant ID')
                            ->visible(static fn (Get $get): bool => static::isOwnMidtransSelected($get))
                            ->maxLength(255),
                        TextInput::make('midtrans_client_key')
                            ->label('Midtrans Client Key')
                            ->visible(static fn (Get $get): bool => static::isOwnMidtransSelected($get))
                            ->required(static fn (Get $get): bool => static::isOwnMidtransSelected($get))
                            ->maxLength(255),
                        TextInput::make('midtrans_server_key')
                            ->label('Midtrans Server Key')
                            ->password()
                            ->revealable()
                            ->visible(static fn (Get $get): bool => static::isOwnMidtransSelected($get))
                            ->required(static fn (Get $get): bool => static::isOwnMidtransSelected($get))
                            ->columnSpanFull()
                            ->helperText('Stored encrypted at rest and used to verify tenant webhook signatures.'),
                    ]),

                Section::make('Pengaturan Default Payroll (PPh 21 & BPJS)')
                    ->description('Atur sakelar default pajak dan BPJS untuk proses Generate Payroll.')
                    ->columns(2)
                    ->visible(static fn (): bool => static::hasActiveHrisModule())
                    ->schema([
                        Toggle::make('enable_pph21')
                            ->label('Aktifkan PPh 21 Default (TER)'),
                        Toggle::make('enable_bpjs_kesehatan')
                            ->label('Aktifkan BPJS Kesehatan Default'),
                        Toggle::make('enable_bpjs_ketenagakerjaan')
                            ->label('Aktifkan BPJS Ketenagakerjaan Default'),
                        TextInput::make('bpjs_jkk_rate')
                            ->label('Tarif Risiko JKK BPJS TK')
                            ->numeric()
                            ->required(static fn (): bool => static::hasActiveHrisModule())
                            ->minValue(0)
                            ->maxValue(1)
                            ->step('0.0001')
                            ->helperText('Contoh: 0.0024 untuk tingkat risiko sangat rendah (0.24%).'),
                    ]),
            ]);
    }

    /**
     * Validate form input and persist tenant settings via UpdateCompanySettingsAction.
     */
    public function save(): void
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return;
        }

        $state = $this->form->getState();

        try {
            app(UpdateCompanySettingsAction::class)->execute($tenant, $state);

            Notification::make()
                ->title('Company settings saved successfully.')
                ->success()
                ->send();
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Determine whether the active tenant has an active subscription for the HRIS module.
     */
    private static function hasActiveHrisModule(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company && $tenant->hasActiveModule('hris');
    }

    /**
     * Determine whether the tenant selected own_midtrans mode in the live form state.
     */
    private static function isOwnMidtransSelected(Get $get): bool
    {
        $mode = $get('payment_gateway_mode');

        return $mode === PaymentGatewayMode::OwnMidtrans
            || $mode === PaymentGatewayMode::OwnMidtrans->value;
    }

    /**
     * Load or provision the active tenant's CompanySetting record.
     */
    private function resolveCurrentTenantSettings(): CompanySetting
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return CompanySetting::query()
            ->withoutGlobalScope('company')
            ->firstOrCreate(
                ['company_id' => $tenant->getKey()],
                [
                    'currency' => 'IDR',
                    'tax_rate' => '0.00',
                    'timezone' => 'Asia/Jakarta',
                    'payment_gateway_mode' => PaymentGatewayMode::PlatformMidtrans,
                    'midtrans_is_production' => false,
                    'invoice_prefix' => 'INV-',
                ],
            );
    }
}
