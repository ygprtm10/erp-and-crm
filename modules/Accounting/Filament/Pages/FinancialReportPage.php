<?php

declare(strict_types=1);

namespace Modules\Accounting\Filament\Pages;

use App\Concerns\RequiresModule;
use App\Models\Company;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use Modules\Accounting\Services\FinancialReportService;
use UnitEnum;

/**
 * Displays Trial Balance and Profit & Loss financial reports for accounting-enabled tenants.
 *
 * @property-read Schema $form
 */
class FinancialReportPage extends Page
{
    use RequiresModule;

    /**
     * Livewire filter state storing start_date and end_date.
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Currently active report tab identifier.
     */
    public string $activeTab = 'trial_balance';

    /**
     * The Blade view rendered by this Filament page.
     */
    protected string $view = 'accounting::filament.pages.financial-report';

    /**
     * The sidebar navigation icon for this page.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    /**
     * The navigation label shown in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Financial Reports';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    /**
     * The page title displayed in the header.
     */
    protected static ?string $title = 'Financial Reports';

    /**
     * Return the required module slug controlling access to this page.
     */
    public static function requiredModuleSlug(): string
    {
        return 'accounting';
    }

    /**
     * Initialize default current-month date range filters.
     */
    public function mount(): void
    {
        $this->form->fill([
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
        ]);
    }

    /**
     * Bind the default schema state path for date range filters.
     */
    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    /**
     * Define the date range filter schema using native Filament components.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Report Period')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('start_date')
                            ->label('Start Date')
                            ->required(),
                        DatePicker::make('end_date')
                            ->label('End Date')
                            ->required(),
                    ])
                    ->footerActions([
                        Action::make('applyPeriod')
                            ->label('Apply Period')
                            ->icon(Heroicon::OutlinedFunnel)
                            ->color('primary')
                            ->submit('report-period-form'),
                    ])
                    ->footerActionsAlignment(Alignment::End),
            ]);
    }

    /**
     * Validate filter form state and refresh computed financial reports.
     */
    public function applyFilters(): void
    {
        $this->form->getState();

        unset($this->trialBalanceData, $this->profitAndLossData);
    }

    /**
     * Switch the visible report tab between Trial Balance and Profit & Loss.
     */
    public function setActiveTab(string $tab): void
    {
        if (! in_array($tab, ['trial_balance', 'profit_and_loss'], true)) {
            return;
        }

        $this->activeTab = $tab;
    }

    /**
     * Return pre-formatted Trial Balance rows and balance indicator metadata for Blade rendering.
     *
     * @return array{
     *     rows: list<array{code: string, name: string, debit: string, credit: string, ending: string}>,
     *     total_debit: string,
     *     total_credit: string,
     *     badge_label: string,
     *     badge_color: string
     * }
     */
    #[Computed]
    public function trialBalanceData(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return [
                'rows' => [],
                'total_debit' => $this->formatRupiah('0.0000'),
                'total_credit' => $this->formatRupiah('0.0000'),
                'badge_label' => 'Balanced',
                'badge_color' => 'success',
            ];
        }

        [$startDate, $endDate] = $this->resolveDateRange();
        $report = app(FinancialReportService::class)->getTrialBalance($tenant, $startDate, $endDate);

        $rows = array_map(fn (array $row): array => [
            'code' => $row['code'],
            'name' => $row['name'],
            'debit' => $this->formatRupiah($row['total_debit']),
            'credit' => $this->formatRupiah($row['total_credit']),
            'ending' => $this->formatRupiah($row['ending_balance']),
        ], $report->rows);

        return [
            'rows' => $rows,
            'total_debit' => $this->formatRupiah($report->totalDebit),
            'total_credit' => $this->formatRupiah($report->totalCredit),
            'badge_label' => $report->isBalanced ? 'Seimbang / Balanced' : 'Tidak Seimbang',
            'badge_color' => $report->isBalanced ? 'success' : 'danger',
        ];
    }

    /**
     * Return pre-formatted Profit & Loss sections and net profit summary for Blade rendering.
     *
     * @return array{
     *     revenues: list<array{code: string, name: string, amount: string}>,
     *     expenses: list<array{code: string, name: string, amount: string}>,
     *     total_revenue: string,
     *     total_expense: string,
     *     net_profit: string,
     *     net_color: string
     * }
     */
    #[Computed]
    public function profitAndLossData(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return [
                'revenues' => [],
                'expenses' => [],
                'total_revenue' => $this->formatRupiah('0.0000'),
                'total_expense' => $this->formatRupiah('0.0000'),
                'net_profit' => $this->formatRupiah('0.0000'),
                'net_color' => 'success',
            ];
        }

        [$startDate, $endDate] = $this->resolveDateRange();
        $report = app(FinancialReportService::class)->getProfitAndLoss($tenant, $startDate, $endDate);

        $revenues = array_map(fn (array $row): array => [
            'code' => $row['code'],
            'name' => $row['name'],
            'amount' => $this->formatRupiah($row['amount']),
        ], $report->revenues);

        $expenses = array_map(fn (array $row): array => [
            'code' => $row['code'],
            'name' => $row['name'],
            'amount' => $this->formatRupiah($row['amount']),
        ], $report->expenses);

        $isProfit = bccomp($report->netProfit, '0.0000', 4) >= 0;

        return [
            'revenues' => $revenues,
            'expenses' => $expenses,
            'total_revenue' => $this->formatRupiah($report->totalRevenue),
            'total_expense' => $this->formatRupiah($report->totalExpense),
            'net_profit' => $this->formatRupiah($report->netProfit),
            'net_color' => $isProfit ? 'success' : 'danger',
        ];
    }

    /**
     * Resolve active filter dates with current-month fallbacks.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(): array
    {
        $start = (string) ($this->data['start_date'] ?? now()->startOfMonth()->toDateString());
        $end = (string) ($this->data['end_date'] ?? now()->endOfMonth()->toDateString());

        return [$start, $end];
    }

    /**
     * Format a bcmath decimal string as an Indonesian Rupiah display string.
     */
    private function formatRupiah(string $amount): string
    {
        $isNegative = str_starts_with($amount, '-');
        $absolute = $isNegative ? substr($amount, 1) : $amount;
        $formatted = 'Rp '.number_format((float) $absolute, 2, ',', '.');

        return $isNegative ? '-'.$formatted : $formatted;
    }
}
