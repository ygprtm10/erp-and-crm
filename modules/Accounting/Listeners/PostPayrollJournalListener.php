<?php

declare(strict_types=1);

namespace Modules\Accounting\Listeners;

use App\Models\Company;
use Modules\Accounting\Actions\SeedDefaultAccountsAction;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Models\Branch;
use Modules\Hris\Events\PayrollPaid;
use Modules\Hris\Models\PayrollRun;

/**
 * Automatically posts balanced salary expense, bank payout, PPh 21, and BPJS liability journals when payroll is paid.
 */
class PostPayrollJournalListener
{
    private const MONEY_SCALE = 4;

    /**
     * Inject Accounting services required for automated payroll journal posting.
     */
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly SeedDefaultAccountsAction $seedDefaultAccountsAction,
    ) {}

    /**
     * Post Debit 6101 and Credit Bank/1102, 2102, and 2103 idempotently when Accounting module is active.
     */
    public function handle(PayrollPaid $event): void
    {
        $payrollRun = $event->payrollRun;
        $company = Company::query()->find($payrollRun->company_id);

        if (! $company instanceof Company || ! $company->hasActiveModule('accounting')) {
            return;
        }

        /** Enforce payroll-level idempotency so duplicate PayrollPaid dispatches never double-post journals. */
        $alreadyPosted = Journal::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('reference_type', PayrollRun::class)
            ->where('reference_id', $payrollRun->getKey())
            ->exists();

        if ($alreadyPosted) {
            return;
        }

        $accounts = $this->resolveSystemAccounts($company);
        $salaryExpenseAccountId = (int) $accounts['6101']->getKey();
        $bankFallbackAccountId = (int) $accounts['1102']->getKey();
        $pph21PayableAccountId = (int) $accounts['2102']->getKey();
        $bpjsPayableAccountId = (int) $accounts['2103']->getKey();

        $paymentAccountId = $this->resolveValidCompanyAccountId(
            $company,
            $payrollRun->payment_account_id,
            $bankFallbackAccountId,
        );

        $totalNet = bcadd((string) $payrollRun->total_net, '0', self::MONEY_SCALE);
        $totalPph21 = bcadd((string) $payrollRun->total_pph21, '0', self::MONEY_SCALE);
        $totalBpjsEmployee = bcadd((string) $payrollRun->total_bpjs_employee, '0', self::MONEY_SCALE);
        $totalBpjsCompany = bcadd((string) $payrollRun->total_bpjs_company, '0', self::MONEY_SCALE);
        $totalBpjsPayable = bcadd($totalBpjsEmployee, $totalBpjsCompany, self::MONEY_SCALE);

        /** Sum total debit strictly from net + tax + employee/employer BPJS to match total gross - other deductions + company BPJS. */
        $totalSalaryExpense = bcadd(
            bcadd($totalNet, $totalPph21, self::MONEY_SCALE),
            $totalBpjsPayable,
            self::MONEY_SCALE,
        );

        if (bccomp($totalSalaryExpense, '0.0000', self::MONEY_SCALE) <= 0) {
            return;
        }

        $items = [
            [
                'account_id' => $salaryExpenseAccountId,
                'debit' => $totalSalaryExpense,
                'credit' => '0.0000',
                'memo' => 'Salary & BPJS expense for '.$payrollRun->payroll_number,
            ],
        ];

        if (bccomp($totalNet, '0.0000', self::MONEY_SCALE) > 0) {
            $items[] = [
                'account_id' => $paymentAccountId,
                'debit' => '0.0000',
                'credit' => $totalNet,
                'memo' => 'Net payroll disbursement '.$payrollRun->payroll_number,
            ];
        }

        if (bccomp($totalPph21, '0.0000', self::MONEY_SCALE) > 0) {
            $items[] = [
                'account_id' => $pph21PayableAccountId,
                'debit' => '0.0000',
                'credit' => $totalPph21,
                'memo' => 'PPh 21 payable for '.$payrollRun->payroll_number,
            ];
        }

        if (bccomp($totalBpjsPayable, '0.0000', self::MONEY_SCALE) > 0) {
            $items[] = [
                'account_id' => $bpjsPayableAccountId,
                'debit' => '0.0000',
                'credit' => $totalBpjsPayable,
                'memo' => 'BPJS payable for '.$payrollRun->payroll_number,
            ];
        }

        $branch = $payrollRun->branch_id !== null
            ? Branch::query()->withoutGlobalScope('company')->find($payrollRun->branch_id)
            : null;

        $this->journalPostingService->createAndPostJournal(
            company: $company,
            journalDate: ($payrollRun->paid_at ?? now())->toDateString(),
            description: 'Payroll disbursement '.$payrollRun->payroll_number,
            items: $items,
            branch: $branch,
            referenceType: PayrollRun::class,
            referenceId: (int) $payrollRun->getKey(),
        );
    }

    /**
     * Verify an optional payment account belongs to the target company before using it, otherwise fallback to 1102.
     */
    private function resolveValidCompanyAccountId(Company $company, ?int $candidateAccountId, int $fallbackId): int
    {
        if ($candidateAccountId === null) {
            return $fallbackId;
        }

        $belongsToCompany = Account::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->whereKey($candidateAccountId)
            ->exists();

        return $belongsToCompany ? $candidateAccountId : $fallbackId;
    }

    /**
     * Resolve required payroll COA accounts, provisioning defaults first if absent.
     *
     * @return array<string, Account>
     */
    private function resolveSystemAccounts(Company $company): array
    {
        $requiredCodes = ['1102', '2102', '2103', '6101'];

        $accounts = Account::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->whereIn('code', $requiredCodes)
            ->get()
            ->keyBy('code');

        if ($accounts->count() < count($requiredCodes)) {
            $this->seedDefaultAccountsAction->execute($company);

            $accounts = Account::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $company->getKey())
                ->whereIn('code', $requiredCodes)
                ->get()
                ->keyBy('code');
        }

        $resolved = [];
        foreach ($requiredCodes as $code) {
            $resolved[$code] = $accounts->get($code);
        }

        return $resolved;
    }
}
