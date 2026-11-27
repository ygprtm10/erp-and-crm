<?php

declare(strict_types=1);

namespace Modules\Hris\Tests\Unit;

use App\Models\Company;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Hris\Database\Seeders\TaxMasterSeeder;
use Modules\Hris\Enums\EmploymentStatus;
use Modules\Hris\Enums\LeaveStatus;
use Modules\Hris\Enums\PayrollRunStatus;
use Modules\Hris\Enums\PtkpStatus;
use Modules\Hris\Models\Attendance;
use Modules\Hris\Models\Department;
use Modules\Hris\Models\Employee;
use Modules\Hris\Models\LeaveRequest;
use Modules\Hris\Models\PayrollRun;
use Modules\Hris\Models\Payslip;
use Modules\Hris\Models\Position;
use Modules\Hris\Models\WorkShift;
use Modules\Platform\Models\SysTaxProgressiveBracket;
use Modules\Platform\Models\SysTaxPtkp;
use Modules\Platform\Models\SysTaxTerBracket;
use Tests\TestCase;

/**
 * Unit tests verifying BelongsToCompany isolation across HRIS models and TaxMaster/HrisDemo seeder idempotency.
 */
class HrisTenancyAndSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure BelongsToCompany automatically scopes HRIS queries to the active Filament tenant.
     */
    public function test_belongs_to_company_isolates_hris_models_per_tenant(): void
    {
        /** Arrange employees in two separate tenant companies. */
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $empA = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyA->getKey(),
            'employee_code' => 'EMP-A1',
            'full_name' => 'Alpha Employee',
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '5000000.0000',
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        $empB = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyB->getKey(),
            'employee_code' => 'EMP-B1',
            'full_name' => 'Beta Employee',
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '5000000.0000',
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        /** Act by setting active Filament tenant to Company A and creating a department with auto-filled company_id. */
        Filament::setTenant($companyA, isQuiet: true);

        $department = Department::query()->create([
            'code' => 'HRD',
            'name' => 'Human Resources',
        ]);

        try {
            /** Assert only Company A records are visible through scoped queries. */
            $this->assertSame($companyA->getKey(), $department->company_id);
            $this->assertSame([$empA->getKey()], Employee::query()->pluck('id')->all());
            $this->assertNull(Employee::query()->find($empB->getKey()));
        } finally {
            Filament::setTenant(null, isQuiet: true);
        }
    }

    /**
     * Ensure TaxMasterSeeder populates official PTKP, TER, and Article 17 tiers idempotently across runs.
     */
    public function test_tax_master_seeder_is_idempotent_and_populates_all_required_tables(): void
    {
        /** Act by running TaxMasterSeeder twice consecutively. */
        $this->seed(TaxMasterSeeder::class);
        $this->seed(TaxMasterSeeder::class);

        /** Assert exact non-duplicated master counts across all three global tax tables. */
        $this->assertSame(8, SysTaxPtkp::query()->count());
        $this->assertSame(125, SysTaxTerBracket::query()->count());
        $this->assertSame(5, SysTaxProgressiveBracket::query()->count());

        $k3 = SysTaxPtkp::query()->where('code', 'K/3')->firstOrFail();
        $this->assertSame('C', $k3->ter_category);
        $this->assertSame('72000000.0000', $k3->annual_amount);
    }

    /**
     * Ensure DatabaseSeeder runs HrisDemoSeeder idempotently, activates hris only on Toko Maju, and skips demo in production.
     */
    public function test_database_seeder_runs_hris_demo_idempotently_and_keeps_tax_master_in_production(): void
    {
        /** Act by running full DatabaseSeeder twice in testing environment. */
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        /** Assert hris module entitlement gating between Toko Maju and Gym Sehat. */
        $this->assertTrue($tokoMaju->hasActiveModule('hris'));
        $this->assertFalse($gymSehat->hasActiveModule('hris'));

        /** Assert zero duplicate HRIS demo rows. */
        $this->assertSame(2, Department::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->count());
        $this->assertSame(2, Position::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->count());
        $this->assertSame(1, WorkShift::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->count());
        $this->assertSame(2, Employee::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->count());
        $this->assertSame(2, Attendance::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->count());
        $this->assertSame(1, LeaveRequest::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->where('status', LeaveStatus::Pending->value)->count());
        $this->assertSame(1, PayrollRun::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->where('status', PayrollRunStatus::Draft->value)->count());

        $payslips = Payslip::query()
            ->withoutGlobalScope('company')
            ->with('employee')
            ->where('company_id', $tokoMaju->getKey())
            ->get()
            ->keyBy(static fn (Payslip $slip): string => $slip->employee->employee_code);

        $this->assertTrue(bccomp((string) $payslips->get('EMP-001')->pph21_amount, '0.0000', 4) > 0);
        $this->assertTrue(bccomp((string) $payslips->get('EMP-001')->bpjs_kesehatan_employee, '0.0000', 4) > 0);
        $this->assertSame('0.0000', $payslips->get('EMP-002')->pph21_amount);
        $this->assertSame('0.0000', $payslips->get('EMP-002')->bpjs_kesehatan_employee);
    }

    /**
     * Ensure TaxMasterSeeder runs in production while HrisDemoSeeder is skipped.
     */
    public function test_tax_master_seeder_runs_while_hris_demo_seeder_is_skipped_in_production(): void
    {
        /** Arrange simulated production environment. */
        $originalEnv = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
        } finally {
            $this->app['env'] = $originalEnv;
        }

        $this->assertSame(8, SysTaxPtkp::query()->count());
        $this->assertSame(125, SysTaxTerBracket::query()->count());
        $this->assertSame(5, SysTaxProgressiveBracket::query()->count());
        $this->assertSame(0, Employee::query()->withoutGlobalScope('company')->count());
    }
}
