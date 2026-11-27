<?php

declare(strict_types=1);

namespace Modules\Platform\Services;

use App\Models\Company;
use Modules\Platform\Enums\CompanyModuleStatus;
use Modules\Platform\Models\CompanyModule;
use Modules\Platform\Models\SaasModule;

/**
 * Prepares pre-formatted owned module entitlements and unowned add-on catalog items for tenant UIs.
 */
class TenantModuleCatalogService
{
    /**
     * Build pre-formatted owned modules and available add-on module lists for the given company.
     *
     * @return array{
     *     owned: list<array{
     *         name: string,
     *         slug: string,
     *         status_label: string,
     *         status_color: string,
     *         expires_at_label: string,
     *         formatted_monthly_price: string
     *     }>,
     *     available_addons: list<array{
     *         name: string,
     *         slug: string,
     *         formatted_monthly_price: string,
     *         required_modules_text: string
     *     }>
     * }
     */
    public function getCatalogForCompany(Company $company): array
    {
        $ownedRecords = CompanyModule::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->with('module')
            ->get();

        $ownedModuleIds = $ownedRecords->pluck('module_id')->all();

        $owned = $ownedRecords
            ->filter(static fn (CompanyModule $entitlement): bool => $entitlement->module instanceof SaasModule)
            ->map(function (CompanyModule $entitlement): array {
                $isExpiredByDate = $entitlement->expires_at !== null && $entitlement->expires_at->isPast();
                $effectiveStatus = $isExpiredByDate ? CompanyModuleStatus::Expired : $entitlement->status;

                return [
                    'name' => $entitlement->module->name,
                    'slug' => $entitlement->module->slug,
                    'status_label' => $this->resolveStatusLabel($effectiveStatus),
                    'status_color' => $this->resolveStatusColor($effectiveStatus),
                    'expires_at_label' => $entitlement->expires_at !== null
                        ? $entitlement->expires_at->format('d M Y')
                        : 'Lifetime',
                    'formatted_monthly_price' => $this->formatRupiah((string) $entitlement->module->monthly_price),
                ];
            })
            ->values()
            ->all();

        $allModulesBySlug = SaasModule::query()
            ->orderBy('name')
            ->get();

        $moduleNamesBySlug = $allModulesBySlug->pluck('name', 'slug');

        $availableAddons = $allModulesBySlug
            ->reject(static fn (SaasModule $module): bool => in_array($module->getKey(), $ownedModuleIds, true))
            ->map(function (SaasModule $module) use ($moduleNamesBySlug): array {
                $requiredSlugs = is_array($module->required_modules) ? $module->required_modules : [];
                $requiredNames = array_map(
                    static fn (string $slug): string => (string) ($moduleNamesBySlug->get($slug) ?? $slug),
                    $requiredSlugs,
                );

                return [
                    'name' => $module->name,
                    'slug' => $module->slug,
                    'formatted_monthly_price' => $this->formatRupiah((string) $module->monthly_price),
                    'required_modules_text' => $requiredNames !== []
                        ? 'Requires: '.implode(', ', $requiredNames)
                        : 'Standalone add-on',
                ];
            })
            ->values()
            ->all();

        return [
            'owned' => $owned,
            'available_addons' => $availableAddons,
        ];
    }

    /**
     * Map module entitlement status to a concise UI label.
     */
    private function resolveStatusLabel(CompanyModuleStatus $status): string
    {
        return match ($status) {
            CompanyModuleStatus::Active => 'Active',
            CompanyModuleStatus::Trial => 'Trial',
            CompanyModuleStatus::Expired => 'Expired',
        };
    }

    /**
     * Map module entitlement status to a Filament semantic badge color.
     */
    private function resolveStatusColor(CompanyModuleStatus $status): string
    {
        return match ($status) {
            CompanyModuleStatus::Active => 'success',
            CompanyModuleStatus::Trial => 'info',
            CompanyModuleStatus::Expired => 'danger',
        };
    }

    /**
     * Format a decimal string as an IDR currency display label.
     */
    private function formatRupiah(string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }
}
