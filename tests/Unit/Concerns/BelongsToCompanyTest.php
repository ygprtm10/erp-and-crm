<?php

declare(strict_types=1);

namespace Tests\Unit\Concerns;

use App\Concerns\BelongsToCompany;
use App\Models\Company;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Unit tests verifying tenant global scoping and auto-assignment in BelongsToCompany.
 */
class BelongsToCompanyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a temporary operational table to test the BelongsToCompany trait.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_operational_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('title');
            $table->timestamps();
        });
    }

    /**
     * Clear any active Filament tenant and drop the temporary operational table.
     */
    protected function tearDown(): void
    {
        Filament::setTenant(null);
        Schema::dropIfExists('test_operational_records');

        parent::tearDown();
    }

    /**
     * Ensure creating a model auto-populates company_id from the active Filament tenant.
     */
    public function test_creating_auto_fills_company_id_from_active_tenant(): void
    {
        /** Arrange an active tenant company. */
        $company = Company::factory()->create();
        Filament::setTenant($company, isQuiet: true);

        /** Act by creating a record without specifying company_id. */
        $record = OperationalRecordStub::query()->create(['title' => 'Invoice 001']);

        /** Assert company_id was populated and the company relationship resolves. */
        $this->assertSame($company->getKey(), $record->company_id);
        $this->assertTrue($record->company->is($company));
    }

    /**
     * Ensure an explicitly provided company_id is preserved even when another tenant is active.
     */
    public function test_creating_preserves_explicit_company_id(): void
    {
        /** Arrange two companies with Company A active as the Filament tenant. */
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        Filament::setTenant($companyA, isQuiet: true);

        /** Act by creating a record explicitly assigned to Company B. */
        $record = OperationalRecordStub::query()->create([
            'company_id' => $companyB->getKey(),
            'title' => 'Explicit Tenant Record',
        ]);

        /** Assert Company B's ID was not overwritten by Company A. */
        $this->assertSame($companyB->getKey(), $record->company_id);
    }

    /**
     * Ensure queries are strictly isolated to the active tenant and never leak cross-tenant data.
     */
    public function test_global_scope_isolates_queries_between_tenants(): void
    {
        /** Arrange records belonging to two separate companies. */
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $recordA = OperationalRecordStub::query()->create([
            'company_id' => $companyA->getKey(),
            'title' => 'Tenant A Secret',
        ]);
        $recordB = OperationalRecordStub::query()->create([
            'company_id' => $companyB->getKey(),
            'title' => 'Tenant B Secret',
        ]);

        /** Act by scoping Filament to Company A and querying records. */
        Filament::setTenant($companyA, isQuiet: true);
        $visibleToA = OperationalRecordStub::query()->pluck('id')->all();

        /** Assert only Company A's record is returned and Company B's record is invisible. */
        $this->assertSame([$recordA->getKey()], $visibleToA);
        $this->assertNull(OperationalRecordStub::query()->find($recordB->getKey()));
    }

    /**
     * Ensure queries without an active tenant remain unscoped and do not fail.
     */
    public function test_queries_without_active_tenant_return_all_records(): void
    {
        /** Arrange records across two companies while no tenant is active. */
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        OperationalRecordStub::query()->create(['company_id' => $companyA->getKey(), 'title' => 'Record A']);
        OperationalRecordStub::query()->create(['company_id' => $companyB->getKey(), 'title' => 'Record B']);

        Filament::setTenant(null);

        /** Act by querying all records in console/global context. */
        $count = OperationalRecordStub::query()->count();

        /** Assert both records are accessible when no tenant scope is active. */
        $this->assertSame(2, $count);
    }
}

/**
 * Lightweight Eloquent stub model for testing the BelongsToCompany trait.
 */
class OperationalRecordStub extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the stub model.
     *
     * @var string
     */
    protected $table = 'test_operational_records';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'title',
    ];
}
