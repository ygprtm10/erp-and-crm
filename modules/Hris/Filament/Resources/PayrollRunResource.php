<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources;

use App\Concerns\RequiresModule;
use App\Models\Company;
use App\Models\User;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Branch;
use Modules\Hris\Enums\PayrollRunStatus;
use Modules\Hris\Filament\Resources\PayrollRunResource\Pages\ListPayrollRuns;
use Modules\Hris\Filament\Resources\PayrollRunResource\Pages\ViewPayrollRun;
use Modules\Hris\Filament\Resources\PayrollRunResource\RelationManagers\PayslipsRelationManager;
use Modules\Hris\Models\PayrollRun;
use Modules\Hris\Services\PayrollCalculationService;
use Modules\Platform\Models\CompanySetting;
use UnitEnum;

/**
 * Tenant-scoped Filament resource for monthly payroll generation, approval, and disbursement.
 */
class PayrollRunResource extends Resource
{
    use RequiresModule;

    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = PayrollRun::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    /**
     * The navigation label displayed in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Payroll & Slip Gaji';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'HRIS & Payroll';

    /**
     * Sort order inside the HRIS & Payroll navigation group.
     */
    protected static ?int $navigationSort = 7;

    /**
     * Return the required module slug controlling access to this resource.
     */
    public static function requiredModuleSlug(): string
    {
        return 'hris';
    }

    /**
     * Enforce payroll creation strictly via PayrollCalculationService header action.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Prevent direct form editing of computed payroll runs.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Prevent deletion of payroll runs to preserve auditability and paid immutability.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Define the read-only detail infolist schema for viewing a payroll run.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Periode Payroll')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('payroll_number')
                            ->label('Nomor Payroll')
                            ->badge(),
                        TextEntry::make('period_month')
                            ->label('Periode')
                            ->formatStateUsing(static fn (PayrollRun $record): string => sprintf('%02d / %04d', $record->period_month, $record->period_year)),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(static fn (PayrollRunStatus $state): string => ucfirst($state->value))
                            ->color(static fn (PayrollRunStatus $state): string => static::statusColor($state)),
                        TextEntry::make('branch.name')
                            ->label('Cabang')
                            ->placeholder('Semua Cabang'),
                        IconEntry::make('apply_pph21')
                            ->label('Sakelar PPh 21')
                            ->boolean(),
                        IconEntry::make('apply_bpjs')
                            ->label('Sakelar BPJS')
                            ->boolean(),
                        TextEntry::make('paymentAccount.name')
                            ->label('Akun Pembayaran')
                            ->placeholder('-'),
                        TextEntry::make('paid_at')
                            ->label('Tanggal Dibayar')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('notes')
                            ->label('Catatan')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
                Section::make('Ringkasan Total Payroll')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('total_gross')
                            ->label('Total Bruto')
                            ->money('IDR')
                            ->extraAttributes(['class' => 'tabular-nums']),
                        TextEntry::make('total_pph21')
                            ->label('Total PPh 21')
                            ->money('IDR')
                            ->extraAttributes(['class' => 'tabular-nums']),
                        TextEntry::make('total_bpjs_combined')
                            ->label('Total BPJS (Emp + Comp)')
                            ->state(static fn (PayrollRun $record): string => bcadd((string) $record->total_bpjs_employee, (string) $record->total_bpjs_company, 4))
                            ->money('IDR')
                            ->extraAttributes(['class' => 'tabular-nums']),
                        TextEntry::make('total_net')
                            ->label('Total Net (Take Home)')
                            ->money('IDR')
                            ->weight('bold')
                            ->extraAttributes(['class' => 'tabular-nums']),
                    ]),
            ]);
    }

    /**
     * Define the payroll runs listing table schema, filters, and workflow actions.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('payroll_number')
                    ->label('Nomor Payroll')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('period_month')
                    ->label('Periode')
                    ->formatStateUsing(static fn (PayrollRun $record): string => sprintf('%02d/%04d', $record->period_month, $record->period_year))
                    ->extraAttributes(['class' => 'tabular-nums'])
                    ->sortable(),
                TextColumn::make('branch.name')
                    ->label('Cabang')
                    ->placeholder('Semua Cabang')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn (PayrollRunStatus $state): string => ucfirst($state->value))
                    ->color(static fn (PayrollRunStatus $state): string => static::statusColor($state)),
                IconColumn::make('apply_pph21')
                    ->label('PPh 21')
                    ->boolean(),
                IconColumn::make('apply_bpjs')
                    ->label('BPJS')
                    ->boolean(),
                TextColumn::make('total_gross')
                    ->label('Total Bruto')
                    ->money('IDR')
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('total_pph21')
                    ->label('Total PPh 21')
                    ->money('IDR')
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('total_bpjs_combined')
                    ->label('Total BPJS')
                    ->state(static fn (PayrollRun $record): string => bcadd((string) $record->total_bpjs_employee, (string) $record->total_bpjs_company, 4))
                    ->money('IDR')
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('total_net')
                    ->label('Total Net')
                    ->money('IDR')
                    ->alignEnd()
                    ->weight('bold')
                    ->extraAttributes(['class' => 'tabular-nums']),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(PayrollRunStatus::cases())->mapWithKeys(
                        static fn (PayrollRunStatus $status): array => [$status->value => ucfirst($status->value)],
                    )->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                ...static::getWorkflowActions(),
            ]);
    }

    /**
     * Build the Header Action to generate a new monthly payroll run via PayrollCalculationService.
     */
    public static function makeGeneratePayrollAction(): Action
    {
        return Action::make('generatePayroll')
            ->label('Generate Payroll')
            ->icon(Heroicon::OutlinedCalculator)
            ->color('primary')
            ->schema([
                Select::make('period_month')
                    ->label('Bulan')
                    ->required()
                    ->default((int) now()->month)
                    ->options([
                        1 => '01 - Januari',
                        2 => '02 - Februari',
                        3 => '03 - Maret',
                        4 => '04 - April',
                        5 => '05 - Mei',
                        6 => '06 - Juni',
                        7 => '07 - Juli',
                        8 => '08 - Agustus',
                        9 => '09 - September',
                        10 => '10 - Oktober',
                        11 => '11 - November',
                        12 => '12 - Desember',
                    ]),
                TextInput::make('period_year')
                    ->label('Tahun')
                    ->numeric()
                    ->required()
                    ->minValue(2000)
                    ->maxValue(2100)
                    ->default((int) now()->year),
                Select::make('branch_id')
                    ->label('Cabang (Opsional)')
                    ->searchable()
                    ->nullable()
                    ->options(static function (): array {
                        $tenant = Filament::getTenant();

                        if (! $tenant instanceof Company) {
                            return [];
                        }

                        return Branch::query()
                            ->where('company_id', $tenant->getKey())
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    }),
                Toggle::make('apply_pph21')
                    ->label('Terapkan PPh 21 (TER)')
                    ->default(static fn (): bool => (bool) (static::resolveTenantSettings()?->enable_pph21 ?? false)),
                Toggle::make('apply_bpjs')
                    ->label('Terapkan BPJS Kesehatan & TK')
                    ->default(static function (): bool {
                        $settings = static::resolveTenantSettings();

                        return (bool) (($settings?->enable_bpjs_kesehatan ?? false) || ($settings?->enable_bpjs_ketenagakerjaan ?? false));
                    }),
                Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(2),
            ])
            ->action(static function (array $data): void {
                $tenant = Filament::getTenant();

                if (! $tenant instanceof Company) {
                    return;
                }

                $branch = isset($data['branch_id']) && $data['branch_id'] !== null
                    ? Branch::query()->where('company_id', $tenant->getKey())->find((int) $data['branch_id'])
                    : null;

                try {
                    app(PayrollCalculationService::class)->generatePayrollRun(
                        company: $tenant,
                        periodMonth: (int) $data['period_month'],
                        periodYear: (int) $data['period_year'],
                        branch: $branch,
                        applyPph21: (bool) ($data['apply_pph21'] ?? false),
                        applyBpjs: (bool) ($data['apply_bpjs'] ?? false),
                        notes: $data['notes'] ?? null,
                    );

                    Notification::make()
                        ->title('Payroll periode berhasil di-generate.')
                        ->success()
                        ->send();
                } catch (DomainException|InvalidArgumentException $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Build shared approval and disbursement workflow actions for table rows and ViewPayrollRun header.
     *
     * @return array<int, Action>
     */
    public static function getWorkflowActions(): array
    {
        return [
            Action::make('approvePayroll')
                ->label('Setujui Payroll')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('info')
                ->requiresConfirmation()
                ->visible(static fn (PayrollRun $record): bool => $record->status === PayrollRunStatus::Draft)
                ->action(static function (PayrollRun $record): void {
                    /** @var User|null $approver */
                    $approver = Auth::user();

                    if (! $approver instanceof User) {
                        return;
                    }

                    try {
                        app(PayrollCalculationService::class)->approvePayrollRun($record, $approver);

                        Notification::make()
                            ->title('Payroll berhasil disetujui.')
                            ->success()
                            ->send();
                    } catch (DomainException|InvalidArgumentException $exception) {
                        Notification::make()
                            ->title($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('payPayroll')
                ->label('Bayar Gaji')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->visible(static fn (PayrollRun $record): bool => $record->status === PayrollRunStatus::Approved)
                ->schema([
                    Select::make('payment_account_id')
                        ->label('Akun Kas / Bank Pembayaran')
                        ->visible(static function (): bool {
                            $tenant = Filament::getTenant();

                            return $tenant instanceof Company && $tenant->hasActiveModule('accounting');
                        })
                        ->required(static function (): bool {
                            $tenant = Filament::getTenant();

                            return $tenant instanceof Company && $tenant->hasActiveModule('accounting');
                        })
                        ->default(static function (): ?int {
                            $tenant = Filament::getTenant();

                            if (! $tenant instanceof Company || ! $tenant->hasActiveModule('accounting')) {
                                return null;
                            }

                            $id = Account::query()
                                ->where('company_id', $tenant->getKey())
                                ->where('code', '1102')
                                ->value('id');

                            return $id !== null ? (int) $id : null;
                        })
                        ->options(static function (): array {
                            $tenant = Filament::getTenant();

                            if (! $tenant instanceof Company) {
                                return [];
                            }

                            return Account::query()
                                ->where('company_id', $tenant->getKey())
                                ->where('type', AccountType::Asset->value)
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(static fn (Account $account): array => [
                                    $account->getKey() => "{$account->code} - {$account->name}",
                                ])
                                ->all();
                        }),
                ])
                ->action(static function (PayrollRun $record, array $data): void {
                    $tenant = Filament::getTenant();

                    $account = isset($data['payment_account_id']) && $data['payment_account_id'] !== null && $tenant instanceof Company
                        ? Account::query()->where('company_id', $tenant->getKey())->find((int) $data['payment_account_id'])
                        : null;

                    try {
                        app(PayrollCalculationService::class)->payPayrollRun($record, $account);

                        Notification::make()
                            ->title('Pembayaran gaji selesai diproses.')
                            ->success()
                            ->send();
                    } catch (DomainException|InvalidArgumentException $exception) {
                        Notification::make()
                            ->title($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    /**
     * Register the payslips relation manager displayed on ViewPayrollRun.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            PayslipsRelationManager::class,
        ];
    }

    /**
     * Register the pages belonging to this resource.
     *
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPayrollRuns::route('/'),
            'view' => ViewPayrollRun::route('/{record}'),
        ];
    }

    /**
     * Map payroll run status to a semantic Filament badge color.
     */
    private static function statusColor(PayrollRunStatus $status): string
    {
        return match ($status) {
            PayrollRunStatus::Draft => 'warning',
            PayrollRunStatus::Approved => 'info',
            PayrollRunStatus::Paid => 'success',
        };
    }

    /**
     * Load the active tenant's CompanySetting row if present.
     */
    private static function resolveTenantSettings(): ?CompanySetting
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return null;
        }

        return CompanySetting::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tenant->getKey())
            ->first();
    }
}
