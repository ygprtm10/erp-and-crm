<?php

declare(strict_types=1);

namespace Modules\Kpi\Tests\Unit;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Hris\Models\Employee;
use Modules\Kpi\Enums\KpiBonusType;
use Modules\Kpi\Models\KpiBonusTier;
use Modules\Kpi\Services\KpiBonusCalculatorService;
use Tests\TestCase;

/**
 * Unit tests verifying bcmath tier matching and bonus computation in KpiBonusCalculatorService.
 */
class KpiBonusCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure percentage_of_base_salary and fixed_amount tiers compute exact DECIMAL(19,4) bonuses.
     */
    public function test_calculates_percentage_and_fixed_bonus_tiers_with_bcmath_precision(): void
    {
        /** Arrange employee with base_salary 8,333,333.3300 and percentage + fixed tiers. */
        $company = Company::factory()->create();
        $employee = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'employee_code' => 'EMP-BONUS-01',
            'full_name' => 'Bonus Tester',
            'base_salary' => '8333333.3300',
            'join_date' => '2026-01-01',
        ]);

        KpiBonusTier::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'min_score' => '95.00',
            'max_score' => '120.00',
            'grade' => 'A',
            'bonus_type' => KpiBonusType::PercentageOfBaseSalary,
            'bonus_value' => '15.0000',
            'is_active' => true,
        ]);

        KpiBonusTier::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'min_score' => '80.00',
            'max_score' => '94.99',
            'grade' => 'B',
            'bonus_type' => KpiBonusType::FixedAmount,
            'bonus_value' => '750000.0000',
            'is_active' => true,
        ]);

        KpiBonusTier::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'min_score' => '95.00',
            'max_score' => '120.00',
            'grade' => 'S',
            'bonus_type' => KpiBonusType::FixedAmount,
            'bonus_value' => '9999999.0000',
            'is_active' => false,
        ]);

        $calculator = app(KpiBonusCalculatorService::class);

        /** Grade A percentage tier: 8,333,333.3300 * 0.15 = 1,249,999.9995. */
        $gradeA = $calculator->calculate($employee, '95.00');
        $this->assertSame('A', $gradeA['grade']);
        $this->assertSame('1249999.9995', $gradeA['bonus_amount']);

        /** Grade B fixed amount tier. */
        $gradeB = $calculator->calculate($employee, '88.50');
        $this->assertSame('B', $gradeB['grade']);
        $this->assertSame('750000.0000', $gradeB['bonus_amount']);

        /** Unmatched score returns null grade and 0.0000 bonus. */
        $unmatched = $calculator->calculate($employee, '60.00');
        $this->assertNull($unmatched['grade']);
        $this->assertSame('0.0000', $unmatched['bonus_amount']);
    }

    /**
     * Ensure non-numeric final scores throw InvalidArgumentException.
     */
    public function test_rejects_invalid_final_score_format(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'employee_code' => 'EMP-BONUS-02',
            'full_name' => 'Invalid Score Tester',
            'base_salary' => '5000000.0000',
            'join_date' => '2026-01-01',
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(KpiBonusCalculatorService::class)->calculate($employee, 'not-a-number');
    }
}
