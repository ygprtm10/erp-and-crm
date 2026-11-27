<?php

declare(strict_types=1);

namespace Modules\Accounting\Services;

use App\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Accounting\Data\ProfitAndLossReport;
use Modules\Accounting\Data\TrialBalanceReport;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Enums\NormalBalance;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalItem;

/**
 * Generates Trial Balance and Profit & Loss reports strictly from posted journals using bcmath.
 */
class FinancialReportService
{
    private const MONEY_SCALE = 4;

    /**
     * Compute the Trial Balance across all posted journal entries within the given date range.
     */
    public function getTrialBalance(
        Company $company,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
    ): TrialBalanceReport {
        $start = $this->normalizeDate($startDate);
        $end = $this->normalizeDate($endDate);

        $accounts = Account::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->orderBy('code')
            ->get();

        $postedSumsByAccount = $this->aggregatePostedItemsByAccount((int) $company->getKey(), $start, $end);

        $rows = [];
        $grandDebit = '0.0000';
        $grandCredit = '0.0000';

        foreach ($accounts as $account) {
            $totals = $postedSumsByAccount[$account->getKey()] ?? ['debit' => '0.0000', 'credit' => '0.0000'];

            if (bccomp($totals['debit'], '0.0000', self::MONEY_SCALE) === 0
                && bccomp($totals['credit'], '0.0000', self::MONEY_SCALE) === 0) {
                continue;
            }

            $endingBalance = $account->normal_balance === NormalBalance::Debit
                ? bcsub($totals['debit'], $totals['credit'], self::MONEY_SCALE)
                : bcsub($totals['credit'], $totals['debit'], self::MONEY_SCALE);

            $grandDebit = bcadd($grandDebit, $totals['debit'], self::MONEY_SCALE);
            $grandCredit = bcadd($grandCredit, $totals['credit'], self::MONEY_SCALE);

            $rows[] = [
                'account_id' => $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'normal_balance' => $account->normal_balance,
                'total_debit' => $totals['debit'],
                'total_credit' => $totals['credit'],
                'ending_balance' => $endingBalance,
            ];
        }

        return new TrialBalanceReport(
            startDate: $start,
            endDate: $end,
            rows: $rows,
            totalDebit: $grandDebit,
            totalCredit: $grandCredit,
            isBalanced: bccomp($grandDebit, $grandCredit, self::MONEY_SCALE) === 0,
        );
    }

    /**
     * Compute the Profit & Loss statement from posted revenue and expense entries in the date range.
     */
    public function getProfitAndLoss(
        Company $company,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
    ): ProfitAndLossReport {
        $start = $this->normalizeDate($startDate);
        $end = $this->normalizeDate($endDate);

        $accounts = Account::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->whereIn('type', [AccountType::Revenue->value, AccountType::Expense->value])
            ->orderBy('code')
            ->get();

        $postedSumsByAccount = $this->aggregatePostedItemsByAccount((int) $company->getKey(), $start, $end);

        $revenues = [];
        $expenses = [];
        $totalRevenue = '0.0000';
        $totalExpense = '0.0000';

        foreach ($accounts as $account) {
            $totals = $postedSumsByAccount[$account->getKey()] ?? ['debit' => '0.0000', 'credit' => '0.0000'];

            if (bccomp($totals['debit'], '0.0000', self::MONEY_SCALE) === 0
                && bccomp($totals['credit'], '0.0000', self::MONEY_SCALE) === 0) {
                continue;
            }

            if ($account->type === AccountType::Revenue) {
                $amount = bcsub($totals['credit'], $totals['debit'], self::MONEY_SCALE);
                $totalRevenue = bcadd($totalRevenue, $amount, self::MONEY_SCALE);
                $revenues[] = [
                    'account_id' => $account->getKey(),
                    'code' => $account->code,
                    'name' => $account->name,
                    'amount' => $amount,
                ];

                continue;
            }

            $amount = bcsub($totals['debit'], $totals['credit'], self::MONEY_SCALE);
            $totalExpense = bcadd($totalExpense, $amount, self::MONEY_SCALE);
            $expenses[] = [
                'account_id' => $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'amount' => $amount,
            ];
        }

        $netProfit = bcsub($totalRevenue, $totalExpense, self::MONEY_SCALE);

        return new ProfitAndLossReport(
            startDate: $start,
            endDate: $end,
            revenues: $revenues,
            expenses: $expenses,
            totalRevenue: $totalRevenue,
            totalExpense: $totalExpense,
            netProfit: $netProfit,
        );
    }

    /**
     * Sum posted journal item debits and credits per account using bcmath precision.
     *
     * @return array<int, array{debit: string, credit: string}>
     */
    private function aggregatePostedItemsByAccount(int $companyId, string $startDate, string $endDate): array
    {
        /** @var Collection<int, JournalItem> $items */
        $items = JournalItem::query()
            ->withoutGlobalScope('company')
            ->select(['acc_journal_items.account_id', 'acc_journal_items.debit', 'acc_journal_items.credit'])
            ->join('acc_journals', 'acc_journals.id', '=', 'acc_journal_items.journal_id')
            ->where('acc_journals.company_id', $companyId)
            ->where('acc_journals.status', JournalStatus::Posted->value)
            ->whereDate('acc_journals.journal_date', '>=', $startDate)
            ->whereDate('acc_journals.journal_date', '<=', $endDate)
            ->get();

        $aggregated = [];

        foreach ($items as $item) {
            $accountId = (int) $item->account_id;

            if (! isset($aggregated[$accountId])) {
                $aggregated[$accountId] = [
                    'debit' => '0.0000',
                    'credit' => '0.0000',
                ];
            }

            $aggregated[$accountId]['debit'] = bcadd($aggregated[$accountId]['debit'], (string) $item->debit, self::MONEY_SCALE);
            $aggregated[$accountId]['credit'] = bcadd($aggregated[$accountId]['credit'], (string) $item->credit, self::MONEY_SCALE);
        }

        return $aggregated;
    }

    /**
     * Normalize date inputs to Y-m-d format.
     */
    private function normalizeDate(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface
            ? $date->toDateString()
            : Carbon::parse($date)->toDateString();
    }
}
