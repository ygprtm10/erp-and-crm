<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\Journal;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Category;
use Modules\Core\Models\Contact;
use Modules\Core\Models\InventoryStock;
use Modules\Core\Models\Product;
use Modules\Core\Models\Voucher;
use Modules\Hris\Models\Attendance;
use Modules\Hris\Models\Department;
use Modules\Hris\Models\Employee;
use Modules\Hris\Models\LeaveRequest;
use Modules\Hris\Models\PayrollRun;
use Modules\Hris\Models\Payslip;
use Modules\Hris\Models\Position;
use Modules\Hris\Models\WorkShift;
use Modules\Iot\Models\IotAttendanceLog;
use Modules\Iot\Models\IotDevice;
use Modules\Kpi\Models\KpiBonusTier;
use Modules\Kpi\Models\KpiEvaluation;
use Modules\Kpi\Models\KpiIndicator;
use Modules\Kpi\Models\KpiPeriod;
use Modules\Platform\Database\Factories\CompanyFactory;
use Modules\Platform\Enums\CompanyModuleStatus;
use Modules\Platform\Models\CompanyModule;
use Modules\Platform\Models\CompanySetting;
use Modules\Platform\Models\CompanyUser;
use Modules\Platform\Models\SaasSubscription;
use Modules\Platform\Models\SaasTenantBankAccount;
use Modules\Platform\Models\SaasTenantWallet;
use Modules\Platform\Models\SaasWithdrawal;
use Modules\Transaction\Models\Order;
use Modules\Transaction\Models\PosShift;
use Modules\Transaction\Models\PosTable;

/**
 * Represents a tenant organization within the multi-tenant SaaS application.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $custom_domain
 * @property string $business_type
 * @property bool $is_active
 */
class Company extends Model implements HasName
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'custom_domain',
        'business_type',
        'is_active',
    ];

    /**
     * Create a new factory instance for the Company model.
     */
    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Return the display name used by Filament's tenant switcher.
     */
    public function getFilamentName(): string
    {
        return $this->name;
    }

    /**
     * Define the users associated with this company via the company_user pivot.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->using(CompanyUser::class)
            ->withPivot(['branch_id', 'role_id', 'is_owner'])
            ->withTimestamps();
    }

    /**
     * Define the per-column settings record for this company.
     */
    public function settings(): HasOne
    {
        return $this->hasOne(CompanySetting::class);
    }

    /**
     * Define the module subscriptions assigned to this company.
     */
    public function companyModules(): HasMany
    {
        return $this->hasMany(CompanyModule::class);
    }

    /**
     * Define the SaaS subscription checkout records for this company.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(SaasSubscription::class);
    }

    /**
     * Define the escrow wallet owned by this company for Platform Midtrans settlements.
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(SaasTenantWallet::class);
    }

    /**
     * Define the verified withdrawal destination bank accounts belonging to this company.
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(SaasTenantBankAccount::class);
    }

    /**
     * Define the withdrawal requests submitted by this company.
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(SaasWithdrawal::class);
    }

    /**
     * Define operational branches belonging to this company.
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * Define CRM contacts belonging to this company.
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * Define catalog categories belonging to this company.
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * Define catalog products belonging to this company.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Define promotional vouchers belonging to this company.
     */
    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    /**
     * Define branch inventory stock balances belonging to this company.
     */
    public function inventoryStocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class);
    }

    /**
     * Define Chart of Accounts entries belonging to this company.
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * Define general ledger journals belonging to this company.
     */
    public function journals(): HasMany
    {
        return $this->hasMany(Journal::class);
    }

    /**
     * Define operational expenses belonging to this company.
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Define POS tables belonging to this company.
     */
    public function posTables(): HasMany
    {
        return $this->hasMany(PosTable::class);
    }

    /**
     * Define cashier shifts belonging to this company.
     */
    public function posShifts(): HasMany
    {
        return $this->hasMany(PosShift::class);
    }

    /**
     * Define unified transaction orders belonging to this company.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Define HRIS departments belonging to this company.
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * Define HRIS job positions belonging to this company.
     */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    /**
     * Define HRIS work shifts belonging to this company.
     */
    public function workShifts(): HasMany
    {
        return $this->hasMany(WorkShift::class);
    }

    /**
     * Define HRIS employees belonging to this company.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Define HRIS attendance records belonging to this company.
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Define HRIS leave requests belonging to this company.
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * Define HRIS monthly payroll runs belonging to this company.
     */
    public function payrollRuns(): HasMany
    {
        return $this->hasMany(PayrollRun::class);
    }

    /**
     * Define HRIS employee payslips belonging to this company.
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * Define IoT ESP32 devices belonging to this company.
     */
    public function iotDevices(): HasMany
    {
        return $this->hasMany(IotDevice::class);
    }

    /**
     * Define IoT attendance scan logs belonging to this company.
     */
    public function iotAttendanceLogs(): HasMany
    {
        return $this->hasMany(IotAttendanceLog::class);
    }

    /**
     * Define KPI indicator definitions belonging to this company.
     */
    public function kpiIndicators(): HasMany
    {
        return $this->hasMany(KpiIndicator::class);
    }

    /**
     * Define KPI evaluation periods belonging to this company.
     */
    public function kpiPeriods(): HasMany
    {
        return $this->hasMany(KpiPeriod::class);
    }

    /**
     * Define KPI bonus tiers belonging to this company.
     */
    public function kpiBonusTiers(): HasMany
    {
        return $this->hasMany(KpiBonusTier::class);
    }

    /**
     * Define employee KPI evaluations belonging to this company.
     */
    public function kpiEvaluations(): HasMany
    {
        return $this->hasMany(KpiEvaluation::class);
    }

    /**
     * Determine whether this company has an active or non-expired trial subscription for the given module slug.
     */
    public function hasActiveModule(string $moduleSlug): bool
    {
        $now = now();

        return $this->companyModules()
            ->withoutGlobalScope('company')
            ->whereIn('status', [
                CompanyModuleStatus::Active->value,
                CompanyModuleStatus::Trial->value,
            ])
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            })
            ->whereHas('module', function (Builder $query) use ($moduleSlug): void {
                $query->where('slug', $moduleSlug);
            })
            ->exists();
    }
}
