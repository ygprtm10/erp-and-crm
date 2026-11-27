<?php

declare(strict_types=1);

namespace Modules\Hris\Tests\Unit;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Hris\Database\Seeders\TaxMasterSeeder;
use Modules\Hris\Enums\EmploymentStatus;
use Modules\Hris\Enums\PtkpStatus;
use Modules\Hris\Models\Employee;
use Modules\Hris\Services\TaxAndBpjsCalculatorService;
use Modules\Platform\Models\CompanySetting;
use Tests\TestCase;

/**
 * Unit tests verifying Indonesian TER PPh 21, BPJS caps, and two-layer toggle behavior.
 */
class TaxAndBpjsCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure active switches compute exact BPJS contributions and PP 58/2023 TER PPh 21 via bcmath.
     */
    public function test_calculates_ter_pph21_and_bpjs_when_both_run_and_employee_switches_are_enabled(): void
    {
        /** Arrange seeded global tax master tables and an employee earning Rp 9,000,000 fixed wage (TK/0). */
        $this->seed(TaxMasterSeeder::class);

        $company = Company::factory()->create();
        $settings = CompanySetting::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'bpjs_jkk_rate' => '0.0024',
        ]);

        $employee = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'employee_code' => 'EMP-TAX-01',
            'full_name' => 'Taxable Staff',
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '8000000.0000',
            'fixed_allowance' => '1000000.0000',
            'enable_pph21' => true,
            'enable_bpjs_kesehatan' => true,
            'enable_bpjs_ketenagakerjaan' => true,
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        /** Act by calculating BPJS and PPh 21 with both run switches enabled. */
        $result = app(TaxAndBpjsCalculatorService::class)->calculate(
            employee: $employee,
            settings: $settings,
            runApplyPph21: true,
            runApplyBpjs: true,
            baseSalary: '8000000.0000',
            allowancesTotal: '1000000.0000',
            overtimePay: '0.0000',
            bonusTotal: '0.0000',
            periodYear: 2026,
        );

        /**
         * Expected BPJS on 9,000,000 base:
         * Kes Emp (1%) = 90,000.0000; Kes Comp (4%) = 360,000.0000
         * JHT Emp (2%) = 180,000; JP Emp (1%) = 90,000 => TK Emp = 270,000.0000
         * JHT Comp (3.7%) = 333,000; JP Comp (2%) = 180,000; JKK (0.24%) = 21,600; JKM (0.30%) = 27,000 => TK Comp = 561,600.0000
         * Taxable Gross = 9,000,000 + 360,000 + 21,600 + 27,000 = 9,408,600.0000
         * Category A (>8,550,000 .. 9,650,000) => TER 1.75% => 9,408,600 * 0.0175 = 164,650.5000
         */
        $this->assertSame('90000.0000', $result->bpjsKesehatanEmployee);
        $this->assertSame('360000.0000', $result->bpjsKesehatanCompany);
        $this->assertSame('270000.0000', $result->bpjsTkEmployee);
        $this->assertSame('561600.0000', $result->bpjsTkCompany);
        $this->assertSame('9408600.0000', $result->taxableGross);
        $this->assertSame('A', $result->terCategory);
        $this->assertSame('1.7500', $result->terRatePercent);
        $this->assertSame('164650.5000', $result->pph21Amount);
    }

    /**
     * Ensure BPJS Kesehatan caps at Rp 12,000,000 and BPJS JP caps at Rp 10,042,300 for high salaries.
     */
    public function test_enforces_bpjs_kesehatan_and_jp_wage_ceilings(): void
    {
        /** Arrange seeded tax master and an employee earning Rp 20,000,000. */
        $this->seed(TaxMasterSeeder::class);

        $company = Company::factory()->create();
        $settings = CompanySetting::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'bpjs_jkk_rate' => '0.0024',
        ]);

        $employee = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'employee_code' => 'EMP-CAP-01',
            'full_name' => 'Executive',
            'ptkp_status' => PtkpStatus::K3,
            'base_salary' => '20000000.0000',
            'enable_pph21' => true,
            'enable_bpjs_kesehatan' => true,
            'enable_bpjs_ketenagakerjaan' => true,
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        /** Act by calculating statutory contributions. */
        $result = app(TaxAndBpjsCalculatorService::class)->calculate(
            employee: $employee,
            settings: $settings,
            runApplyPph21: true,
            runApplyBpjs: true,
            baseSalary: '20000000.0000',
            periodYear: 2026,
        );

        /**
         * Assert BPJS Kesehatan capped at 12,000,000 (120,000 emp / 480,000 comp)
         * and BPJS TK uses uncapped JHT (2%/3.7% on 20M) + capped JP (1%/2% on 10,042,300).
         */
        $this->assertSame('120000.0000', $result->bpjsKesehatanEmployee);
        $this->assertSame('480000.0000', $result->bpjsKesehatanCompany);
        $this->assertSame('500423.0000', $result->bpjsTkEmployee);
        $this->assertSame('1048846.0000', $result->bpjsTkCompany);
        $this->assertSame('C', $result->terCategory);
    }

    /**
     * Ensure two-layer switches return exact 0.0000 whenever either payroll run or employee flag is off.
     */
    public function test_returns_zero_when_run_switch_or_employee_switch_is_disabled(): void
    {
        /** Arrange an employee with tax/BPJS enabled and test run-off, plus employee-off with run-on. */
        $this->seed(TaxMasterSeeder::class);

        $company = Company::factory()->create();
        $settings = CompanySetting::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'bpjs_jkk_rate' => '0.0024',
        ]);

        $enabledEmployee = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'employee_code' => 'EMP-ON',
            'full_name' => 'Enabled Employee',
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '10000000.0000',
            'enable_pph21' => true,
            'enable_bpjs_kesehatan' => true,
            'enable_bpjs_ketenagakerjaan' => true,
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        $disabledEmployee = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'employee_code' => 'EMP-OFF',
            'full_name' => 'Disabled Employee',
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '10000000.0000',
            'enable_pph21' => false,
            'enable_bpjs_kesehatan' => false,
            'enable_bpjs_ketenagakerjaan' => false,
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        $calculator = app(TaxAndBpjsCalculatorService::class);

        /** Case 1: Run switches OFF even though employee flags are ON. */
        $runOff = $calculator->calculate($enabledEmployee, $settings, false, false, '10000000.0000', periodYear: 2026);
        $this->assertSame('0.0000', $runOff->pph21Amount);
        $this->assertSame('0.0000', $runOff->bpjsKesehatanEmployee);
        $this->assertSame('0.0000', $runOff->bpjsTkEmployee);

        /** Case 2: Run switches ON while employee flags are OFF. */
        $empOff = $calculator->calculate($disabledEmployee, $settings, true, true, '10000000.0000', periodYear: 2026);
        $this->assertSame('0.0000', $empOff->pph21Amount);
        $this->assertSame('0.0000', $empOff->bpjsKesehatanEmployee);
        $this->assertSame('0.0000', $empOff->bpjsTkCompany);

        /** Reject negative monetary inputs. */
        $this->expectException(InvalidArgumentException::class);
        $calculator->calculate($enabledEmployee, $settings, true, true, '-100.0000');
    }
}
