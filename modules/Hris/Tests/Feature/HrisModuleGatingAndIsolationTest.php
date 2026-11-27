<?php

declare(strict_types=1);

namespace Modules\Hris\Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Hris\Enums\AttendanceSource;
use Modules\Hris\Enums\EmploymentStatus;
use Modules\Hris\Enums\LeaveType;
use Modules\Hris\Enums\PtkpStatus;
use Modules\Hris\Filament\HrisPlugin;
use Modules\Hris\Filament\Resources\AttendanceResource;
use Modules\Hris\Filament\Resources\AttendanceResource\Pages\ListAttendances;
use Modules\Hris\Filament\Resources\DepartmentResource;
use Modules\Hris\Filament\Resources\EmployeeResource;
use Modules\Hris\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use Modules\Hris\Filament\Resources\LeaveRequestResource;
use Modules\Hris\Filament\Resources\LeaveRequestResource\Pages\ListLeaveRequests;
use Modules\Hris\Filament\Resources\PayrollRunResource;
use Modules\Hris\Filament\Resources\PayrollRunResource\Pages\ListPayrollRuns;
use Modules\Hris\Filament\Resources\PositionResource;
use Modules\Hris\Filament\Resources\WorkShiftResource;
use Modules\Hris\Models\Attendance;
use Modules\Hris\Models\Employee;
use Modules\Hris\Models\LeaveRequest;
use Modules\Hris\Models\PayrollRun;
use Modules\Hris\Services\AttendanceService;
use Modules\Hris\Services\LeaveRequestService;
use Modules\Hris\Services\PayrollCalculationService;
use Modules\Platform\Actions\ActivateCompanyModulesAction;
use Modules\Platform\Filament\Tenant\Pages\CompanySettingsPage;
use Modules\Platform\Models\SaasModule;
use Tests\TestCase;

/**
 * Feature tests verifying HrisPlugin registration, RequiresModule 403 gating, and cross-tenant HRIS isolation.
 */
class HrisModuleGatingAndIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure Toko Maju accesses all HRIS resources and payroll settings while Gym Sehat receives 403 Forbidden.
     */
    public function test_requires_module_grants_toko_maju_and_forbids_gym_sehat_across_all_hris_routes(): void
    {
        /** Arrange seeded Toko Maju (hris active) and Gym Sehat (hris inactive). */
        $this->seed(DatabaseSeeder::class);

        $appPanel = Filament::getPanel('app');
        $this->assertTrue($appPanel->hasPlugin('hris'));
        $this->assertSame('hris', HrisPlugin::make()->getId());

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $owner2 = User::query()->where('email', 'owner2@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        $resourceClasses = [
            DepartmentResource::class,
            PositionResource::class,
            WorkShiftResource::class,
            EmployeeResource::class,
            AttendanceResource::class,
            LeaveRequestResource::class,
            PayrollRunResource::class,
        ];

        /** Act & Assert 1: Toko Maju owner can access every HRIS resource and sees payroll defaults in settings. */
        $this->actingAs($owner1);
        Filament::setCurrentPanel($appPanel);
        Filament::setTenant($tokoMaju, isQuiet: true);

        foreach ($resourceClasses as $resourceClass) {
            $this->assertTrue($resourceClass::canAccess());
            $this->get($resourceClass::getUrl('index', ['tenant' => $tokoMaju]))->assertOk();
        }

        $seededRun = PayrollRun::query()->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $this->get(PayrollRunResource::getUrl('view', ['tenant' => $tokoMaju, 'record' => $seededRun]))->assertOk();

        $this->get(CompanySettingsPage::getUrl(['tenant' => $tokoMaju]))
            ->assertOk()
            ->assertSee('Pengaturan Default Payroll (PPh 21 &amp; BPJS)', false);

        $this->flushSession();

        /** Act & Assert 2: Gym Sehat owner receives 403 Forbidden on all HRIS routes and does not see payroll defaults. */
        $this->actingAs($owner2);
        Filament::setTenant($gymSehat, isQuiet: true);

        foreach ($resourceClasses as $resourceClass) {
            $this->assertFalse($resourceClass::canAccess());
            $this->get($resourceClass::getUrl('index', ['tenant' => $gymSehat]))->assertForbidden();
        }

        $this->get(CompanySettingsPage::getUrl(['tenant' => $gymSehat]))
            ->assertOk()
            ->assertDontSee('Pengaturan Default Payroll (PPh 21 &amp; BPJS)', false);
    }

    /**
     * Ensure HRIS employees, attendances, leave requests, and payroll runs stay strictly isolated per tenant.
     */
    public function test_hris_resources_enforce_cross_tenant_isolation_when_both_tenants_have_hris_active(): void
    {
        /** Arrange seeded Toko Maju and activate hris on Gym Sehat with its own HRIS records. */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $owner2 = User::query()->where('email', 'owner2@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        $hrisModule = SaasModule::query()->where('slug', 'hris')->firstOrFail();
        app(ActivateCompanyModulesAction::class)->execute($gymSehat, [$hrisModule->getKey()]);

        $gymEmployee = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $gymSehat->getKey(),
            'employee_code' => 'GYM-STF-01',
            'full_name' => 'Gym Trainer',
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '5500000.0000',
            'employment_status' => EmploymentStatus::Active,
            'join_date' => now()->toDateString(),
        ]);

        $gymAttendance = app(AttendanceService::class)->recordClockIn(
            employee: $gymEmployee,
            clockInAt: now()->setTime(9, 0),
            source: AttendanceSource::Web,
        );

        $gymLeave = app(LeaveRequestService::class)->submitLeave(
            employee: $gymEmployee,
            type: LeaveType::Annual,
            startDate: now()->addDays(5),
            endDate: now()->addDays(5),
            reason: 'Gym staff leave',
        );

        $gymPayrollRun = app(PayrollCalculationService::class)->generatePayrollRun(
            company: $gymSehat,
            periodMonth: (int) now()->month,
            periodYear: (int) now()->year,
            applyPph21: false,
            applyBpjs: false,
            payrollNumber: 'PAY-GYM-001',
        );

        $tokoEmployee = Employee::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $tokoAttendance = Attendance::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $tokoLeave = LeaveRequest::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $tokoPayrollRun = PayrollRun::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();

        /** Assert Toko Maju sees only Toko Maju records and gets 404 for Gym Sehat employee/payroll URLs. */
        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        Livewire::test(ListEmployees::class)
            ->assertCanSeeTableRecords([$tokoEmployee])
            ->assertCanNotSeeTableRecords([$gymEmployee]);

        Livewire::test(ListAttendances::class)
            ->assertCanSeeTableRecords([$tokoAttendance])
            ->assertCanNotSeeTableRecords([$gymAttendance]);

        Livewire::test(ListLeaveRequests::class)
            ->assertCanSeeTableRecords([$tokoLeave])
            ->assertCanNotSeeTableRecords([$gymLeave]);

        Livewire::test(ListPayrollRuns::class)
            ->assertCanSeeTableRecords([$tokoPayrollRun])
            ->assertCanNotSeeTableRecords([$gymPayrollRun]);

        $this->get('/app/'.$tokoMaju->slug.'/employees/'.$gymEmployee->getKey().'/edit')->assertNotFound();
        $this->get('/app/'.$tokoMaju->slug.'/payroll-runs/'.$gymPayrollRun->getKey())->assertNotFound();

        $this->flushSession();

        /** Assert Gym Sehat sees only Gym Sehat records. */
        $this->actingAs($owner2);
        Filament::setTenant($gymSehat, isQuiet: true);

        Livewire::test(ListEmployees::class)
            ->assertCanSeeTableRecords([$gymEmployee])
            ->assertCanNotSeeTableRecords([$tokoEmployee]);

        Livewire::test(ListPayrollRuns::class)
            ->assertCanSeeTableRecords([$gymPayrollRun])
            ->assertCanNotSeeTableRecords([$tokoPayrollRun]);
    }
}
