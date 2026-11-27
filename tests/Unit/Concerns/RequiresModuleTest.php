<?php

declare(strict_types=1);

namespace Tests\Unit\Concerns;

use App\Concerns\RequiresModule;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Tests\TestCase;

/**
 * Unit tests verifying module-based access gating in the RequiresModule trait.
 */
class RequiresModuleTest extends TestCase
{
    /**
     * Reset the active Filament tenant after each test.
     */
    protected function tearDown(): void
    {
        Filament::setTenant(null);

        parent::tearDown();
    }

    /**
     * Ensure access is denied when no active tenant is resolved.
     */
    public function test_denies_access_when_no_tenant_is_active(): void
    {
        /** Arrange no active Filament tenant. */
        Filament::setTenant(null);

        /** Act and assert access is denied. */
        $this->assertFalse(ModuleProtectedPageStub::canAccess());
    }

    /**
     * Ensure access is denied when the active tenant is not a Company instance.
     */
    public function test_denies_access_when_tenant_is_not_a_company(): void
    {
        /** Arrange a non-Company model set as the active tenant. */
        Filament::setTenant(new User, isQuiet: true);

        /** Act and assert access is denied. */
        $this->assertFalse(ModuleProtectedPageStub::canAccess());
    }

    /**
     * Ensure access is denied when the active company does not have the required module active.
     */
    public function test_denies_access_when_required_module_is_inactive(): void
    {
        /** Arrange a company without the required 'accounting' module active. */
        $company = new class extends Company
        {
            /**
             * Simulate no active module subscriptions for the tenant.
             */
            public function hasActiveModule(string $moduleSlug): bool
            {
                return false;
            }
        };

        Filament::setTenant($company, isQuiet: true);

        /** Act and assert access is denied. */
        $this->assertFalse(ModuleProtectedPageStub::canAccess());
    }

    /**
     * Ensure access is granted when the active company has the required module enabled.
     */
    public function test_allows_access_when_required_module_is_active(): void
    {
        /** Arrange a company with only the 'accounting' module active. */
        $company = new class extends Company
        {
            /**
             * Simulate an active subscription strictly for the accounting module.
             */
            public function hasActiveModule(string $moduleSlug): bool
            {
                return $moduleSlug === 'accounting';
            }
        };

        Filament::setTenant($company, isQuiet: true);

        /** Act and assert access is granted. */
        $this->assertTrue(ModuleProtectedPageStub::canAccess());
    }
}

/**
 * Stub Filament Page using RequiresModule for unit test verification.
 */
class ModuleProtectedPageStub extends Page
{
    use RequiresModule;

    /**
     * Require the accounting module slug for access.
     */
    public static function requiredModuleSlug(): string
    {
        return 'accounting';
    }
}
