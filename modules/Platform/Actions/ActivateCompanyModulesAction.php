<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use App\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Platform\Enums\CompanyModuleStatus;
use Modules\Platform\Models\CompanyModule;
use Modules\Platform\Models\SaasBundle;
use Modules\Platform\Models\SaasModule;

/**
 * Activates or renews SaaS module entitlements and bundle dependencies for a tenant company.
 */
class ActivateCompanyModulesAction
{
    /**
     * Activate the specified module IDs (and optional bundle modules) for the given company.
     *
     * @param  array<int, int|string>  $moduleIds
     * @param  array<string, mixed>|null  $limits
     * @return Collection<int, CompanyModule>
     */
    public function execute(
        Company $company,
        array $moduleIds = [],
        ?SaasBundle $bundle = null,
        ?CarbonInterface $expiresAt = null,
        CompanyModuleStatus $status = CompanyModuleStatus::Active,
        ?array $limits = null,
    ): Collection {
        $resolvedIds = $this->collectTargetModuleIds($moduleIds, $bundle);

        if ($resolvedIds === []) {
            throw new InvalidArgumentException('At least one module ID or bundle with modules must be provided.');
        }

        $modules = $this->resolveModulesWithDependencies($resolvedIds);

        return DB::transaction(function () use ($company, $modules, $expiresAt, $status, $limits): Collection {
            $entitlements = new Collection;

            foreach ($modules as $module) {
                /** Upsert each entitlement bypassing tenant global scope for admin/webhook execution. */
                $entitlement = CompanyModule::query()
                    ->withoutGlobalScope('company')
                    ->updateOrCreate(
                        [
                            'company_id' => $company->getKey(),
                            'module_id' => $module->getKey(),
                        ],
                        [
                            'status' => $status,
                            'expires_at' => $expiresAt,
                            'limits' => $limits,
                        ],
                    );

                $entitlements->push($entitlement);
            }

            return $entitlements;
        });
    }

    /**
     * Merge explicit module IDs with bundle module IDs into a deduplicated integer list.
     *
     * @param  array<int, int|string>  $moduleIds
     * @return array<int, int>
     */
    private function collectTargetModuleIds(array $moduleIds, ?SaasBundle $bundle): array
    {
        $combined = $moduleIds;

        if ($bundle instanceof SaasBundle && is_array($bundle->module_ids)) {
            $combined = [...$combined, ...$bundle->module_ids];
        }

        return array_values(array_unique(array_map(
            static fn (int|string $id): int => (int) $id,
            array_filter($combined, static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0),
        )));
    }

    /**
     * Fetch requested modules and recursively resolve their declared module dependencies.
     *
     * @param  array<int, int>  $targetIds
     * @return Collection<int, SaasModule>
     */
    private function resolveModulesWithDependencies(array $targetIds): Collection
    {
        $selectedModules = SaasModule::query()
            ->whereIn('id', $targetIds)
            ->get()
            ->keyBy('id');

        if ($selectedModules->count() !== count($targetIds)) {
            throw new InvalidArgumentException('One or more requested SaaS module IDs do not exist.');
        }

        /** Expand required_modules slugs so prerequisite modules are activated alongside add-ons. */
        $queue = $selectedModules->values()->all();

        while ($queue !== []) {
            /** @var SaasModule $current */
            $current = array_shift($queue);
            $requiredSlugs = is_array($current->required_modules) ? $current->required_modules : [];

            if ($requiredSlugs === []) {
                continue;
            }

            $dependencies = SaasModule::query()
                ->whereIn('slug', $requiredSlugs)
                ->get();

            if ($dependencies->count() !== count(array_unique($requiredSlugs))) {
                throw new InvalidArgumentException("Missing required dependency module for '{$current->slug}'.");
            }

            foreach ($dependencies as $dependency) {
                if ($selectedModules->has($dependency->getKey())) {
                    continue;
                }

                $selectedModules->put($dependency->getKey(), $dependency);
                $queue[] = $dependency;
            }
        }

        return $selectedModules->values();
    }
}
