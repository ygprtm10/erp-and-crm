<?php

declare(strict_types=1);

namespace Modules\Hris\Tests\Unit;

use App\Models\Company;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Database\Seeders\AccountingDemoSeeder;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;
use Modules\Core\Database\Seeders\CoreDemoSeeder;
use Modules\Core\Models\Branch;
use Modules\Hris\Database\Seeders\HrisDemoSeeder;
use Modules\Hris\Database\Seeders\TaxMasterSeeder;
use Modules\Hris\Enums\EmploymentStatus;
use Modules\Hris\Enums\PayrollRunStatus;
use Modules\Hris\Enums\PtkpStatus;
use Modules\Hris\Events\PayrollPaid;
use Modules\Hris\Models\Employee;
use Modules\Hris\Models\PayrollRun;
use Modules\Hris\Services\PayrollCalculationService;
use Modules\Platform\Database\Seeders\SaasModuleSeeder;
use Modules\Platform\Database\Seeders\TenantDemoSeeder;
use Tests\TestCase;

/**
 * Integration tests verifying PayrollCalculationService, PayrollPaid accounting journals, and immutability.
 */
class PayrollCalculationAndJournalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure paying a payroll run on Toko Maju posts a balanced Debit 6101 vs Credit Bank/2102/2103 journal idempotently.
     */
    public function test_pay_payroll_run_on_toko_maju_posts_balanced_accounting_journal_idempotently(): void
    {
        /** Arrange Toko Maju with seeded HRIS, Accounting COA, and draft payroll run. */
        $this->seed([
            SaasModuleSeeder::class,
            TaxMasterSeeder::class,
            TenantDemoSeeder::class,
            CoreDemoSeeder::class,
            AccountingDemoSeeder::class,
            HrisDemoSeeder::class,
        ]);

        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $owner = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $payrollRun = PayrollRun::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $bankAccount = Account::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->where('code', '1102')->firstOrFail();

        $payrollService = app(PayrollCalculationService::class);

        /** Act by approving and paying the seeded payroll run. */
        $approvedRun = $payrollService->approvePayrollRun($payrollRun, $owner);
        $this->assertSame(PayrollRunStatus::Approved, $approvedRun->status);

        $paidRun = $payrollService->payPayrollRun($approvedRun, $bankAccount);
        $this->assertSame(PayrollRunStatus::Paid, $paidRun->status);

        /** Assert balanced posted journal created with Debit 6101 = Credit 1102 + 2102 + 2103. */
        $journal = Journal::query()
            ->withoutGlobalScope('company')
            ->with('items')
            ->where('company_id', $tokoMaju->getKey())
            ->where('reference_type', PayrollRun::class)
            ->where('reference_id', $paidRun->getKey())
            ->firstOrFail();

        $this->assertSame(JournalStatus::Posted, $journal->status);

        $accountsByCode = Account::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->get()
            ->keyBy('code');

        $itemsByAccount = $journal->items->keyBy('account_id');
        $expectedExpenseDebit = bcadd(
            bcadd((string) $paidRun->total_net, (string) $paidRun->total_pph21, 4),
            bcadd((string) $paidRun->total_bpjs_employee, (string) $paidRun->total_bpjs_company, 4),
            4,
        );
        $expectedBpjsCredit = bcadd((string) $paidRun->total_bpjs_employee, (string) $paidRun->total_bpjs_company, 4);

        $this->assertSame($expectedExpenseDebit, $itemsByAccount->get($accountsByCode->get('6101')->getKey())->debit);
        $this->assertSame((string) $paidRun->total_net, $itemsByAccount->get($bankAccount->getKey())->credit);
        $this->assertSame((string) $paidRun->total_pph21, $itemsByAccount->get($accountsByCode->get('2102')->getKey())->credit);
        $this->assertSame($expectedBpjsCredit, $itemsByAccount->get($accountsByCode->get('2103')->getKey())->credit);

        /** Assert double-entry balance and idempotency upon duplicate PayrollPaid dispatch. */
        $sumDebit = $journal->items->reduce(static fn (string $carry, $item): string => bcadd($carry, (string) $item->debit, 4), '0.0000');
        $sumCredit = $journal->items->reduce(static fn (string $carry, $item): string => bcadd($carry, (string) $item->credit, 4), '0.0000');
        $this->assertSame($sumDebit, $sumCredit);

        PayrollPaid::dispatch($paidRun);
        $this->assertSame(
            1,
            Journal::query()
                ->withoutGlobalScope('company')
                ->where('reference_type', PayrollRun::class)
                ->where('reference_id', $paidRun->getKey())
                ->count(),
        );
    }

    /**
     * Ensure zero-tax/zero-BPJS payroll runs omit 2102/2103 lines and tenants without Accounting module never error.
     */
    public function test_omits_zero_tax_lines_and_skips_journals_when_accounting_module_is_inactive(): void
    {
        /** Arrange Gym Sehat (no accounting module) with one employee having tax/BPJS disabled. */
        $this->seed([
            SaasModuleSeeder::class,
            TaxMasterSeeder::class,
            TenantDemoSeeder::class,
            CoreDemoSeeder::class,
        ]);

        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();
        $owner2 = User::query()->where('email', 'owner2@test.com')->firstOrFail();
        $branch = Branch::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->firstOrFail();

        Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $gymSehat->getKey(),
            'branch_id' => $branch->getKey(),
            'employee_code' => 'GYM-EMP-01',
            'full_name' => 'Coach Gym',
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '5000000.0000',
            'enable_pph21' => false,
            'enable_bpjs_kesehatan' => false,
            'enable_bpjs_ketenagakerjaan' => false,
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        $payrollService = app(PayrollCalculationService::class);

        $run = $payrollService->generatePayrollRun(
            company: $gymSehat,
            periodMonth: (int) now()->month,
            periodYear: (int) now()->year,
            branch: $branch,
            applyPph21: false,
            applyBpjs: false,
        );

        $approved = $payrollService->approvePayrollRun($run, $owner2);
        $paid = $payrollService->payPayrollRun($approved);

        /** Assert paid cleanly with zero tax, zero BPJS, and zero journals created. */
        $this->assertSame(PayrollRunStatus::Paid, $paid->status);
        $this->assertSame('5000000.0000', $paid->total_net);
        $this->assertSame('0.0000', $paid->total_pph21);
        $this->assertSame(0, Journal::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->count());
    }

    /**
     * Ensure paid payroll runs reject direct modifications, deletions, and invalid status transitions.
     */
    public function test_enforces_paid_immutability_and_lifecycle_transition_guards(): void
    {
        /** Arrange a paid payroll run. */
        $this->seed([
            SaasModuleSeeder::class,
            TaxMasterSeeder::class,
            TenantDemoSeeder::class,
            CoreDemoSeeder::class,
            AccountingDemoSeeder::class,
            HrisDemoSeeder::class,
        ]);

        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $owner = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $payrollRun = PayrollRun::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();

        $payrollService = app(PayrollCalculationService::class);

        /** Draft payroll run cannot be paid before approval. */
        try {
            $payrollService->payPayrollRun($payrollRun);
            $this->fail('Expected DomainException when paying a draft payroll run.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $approved = $payrollService->approvePayrollRun($payrollRun, $owner);
        $paid = $payrollService->payPayrollRun($approved);

        /** Paid payroll run cannot be modified directly. */
        try {
            $paid->update(['notes' => 'Tampered note']);
            $this->fail('Expected DomainException when updating a paid payroll run.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        /** Paid payroll run cannot be deleted. */
        $this->expectException(DomainException::class);
        $paid->delete();
    }
}
