<?php

declare(strict_types=1);

namespace Modules\Accounting\Tests\Unit;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Actions\SeedDefaultAccountsAction;
use Modules\Accounting\Services\FinancialReportService;
use Modules\Accounting\Services\JournalPostingService;
use Tests\TestCase;

/**
 * Unit tests verifying Trial Balance and Profit & Loss accuracy, date filtering, and bcmath precision.
 */
class FinancialReportServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure Trial Balance and Profit & Loss aggregate strictly posted in-range journals using bcmath.
     */
    public function test_computes_accurate_trial_balance_and_profit_and_loss_ignoring_draft_and_other_tenants(): void
    {
        /** Arrange two isolated companies with seeded COA accounts. */
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $coaA = app(SeedDefaultAccountsAction::class)->execute($companyA)->keyBy('code');
        $coaB = app(SeedDefaultAccountsAction::class)->execute($companyB)->keyBy('code');

        $journalService = app(JournalPostingService::class);
        $reportService = app(FinancialReportService::class);

        /** Posted sales revenue in range: 1,250,000.7500 */
        $journalService->createAndPostJournal(
            company: $companyA,
            journalDate: '2026-11-10',
            description: 'Sales revenue',
            items: [
                ['account_id' => $coaA->get('1101')->getKey(), 'debit' => '1250000.7500'],
                ['account_id' => $coaA->get('4101')->getKey(), 'credit' => '1250000.7500'],
            ],
        );

        /** Posted COGS + Operational expense in range: 400,000.2500 + 150,000.0000 = 550,000.2500 */
        $journalService->createAndPostJournal(
            company: $companyA,
            journalDate: '2026-11-15',
            description: 'COGS & Expense',
            items: [
                ['account_id' => $coaA->get('5101')->getKey(), 'debit' => '400000.2500'],
                ['account_id' => $coaA->get('6102')->getKey(), 'debit' => '150000.0000'],
                ['account_id' => $coaA->get('1101')->getKey(), 'credit' => '550000.2500'],
            ],
        );

        /** Draft journal (MUST be ignored by reports). */
        $journalService->createDraftJournal(
            company: $companyA,
            journalDate: '2026-11-18',
            description: 'Draft revenue',
            items: [
                ['account_id' => $coaA->get('1101')->getKey(), 'debit' => '999999.0000'],
                ['account_id' => $coaA->get('4101')->getKey(), 'credit' => '999999.0000'],
            ],
        );

        /** Posted journal outside date range (MUST be ignored). */
        $journalService->createAndPostJournal(
            company: $companyA,
            journalDate: '2026-10-31',
            description: 'Prior month revenue',
            items: [
                ['account_id' => $coaA->get('1101')->getKey(), 'debit' => '500000.0000'],
                ['account_id' => $coaA->get('4101')->getKey(), 'credit' => '500000.0000'],
            ],
        );

        /** Posted journal for Company B (MUST NOT leak into Company A). */
        $journalService->createAndPostJournal(
            company: $companyB,
            journalDate: '2026-11-12',
            description: 'Company B revenue',
            items: [
                ['account_id' => $coaB->get('1101')->getKey(), 'debit' => '800000.0000'],
                ['account_id' => $coaB->get('4101')->getKey(), 'credit' => '800000.0000'],
            ],
        );

        /** Act by generating both financial statements for November 2026. */
        $trialBalance = $reportService->getTrialBalance($companyA, '2026-11-01', '2026-11-30');
        $profitAndLoss = $reportService->getProfitAndLoss($companyA, '2026-11-01', '2026-11-30');

        /** Assert exact balanced Trial Balance and Profit & Loss calculations. */
        $this->assertTrue($trialBalance->isBalanced);
        $this->assertSame('1800001.0000', $trialBalance->totalDebit);
        $this->assertSame('1800001.0000', $trialBalance->totalCredit);

        $cashRow = collect($trialBalance->rows)->firstWhere('code', '1101');
        $this->assertSame('700000.5000', $cashRow['ending_balance']);

        $this->assertSame('1250000.7500', $profitAndLoss->totalRevenue);
        $this->assertSame('550000.2500', $profitAndLoss->totalExpense);
        $this->assertSame('700000.5000', $profitAndLoss->netProfit);
    }
}
