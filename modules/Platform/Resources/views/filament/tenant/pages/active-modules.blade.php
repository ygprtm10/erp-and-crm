<!-- Tenant active modules and add-on catalog page built strictly with native Filament 5 Blade components -->
<x-filament-panels::page>
    <!-- Subscribed modules section showing current status and expiration -->
    <x-filament::section
        heading="Subscribed Modules"
        description="Modules currently provisioned for your workspace along with their subscription status."
        icon="heroicon-o-check-badge"
    >
        @if (count($this->ownedModules) === 0)
            <!-- Empty state notice when no modules are assigned yet -->
            <p class="text-sm text-gray-600 dark:text-gray-400">
                No modules are currently assigned to this company.
            </p>
        @else
            <!-- Owned module entitlement cards grid -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->ownedModules as $module)
                    <x-filament::fieldset :label="$module['name']">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                {{ $module['slug'] }}
                            </span>
                            <x-filament::badge :color="$module['status_color']">
                                {{ $module['status_label'] }}
                            </x-filament::badge>
                        </div>

                        <div class="mt-3 flex items-center justify-between text-sm text-gray-700 dark:text-gray-300">
                            <span>Expires</span>
                            <span class="font-medium text-gray-950 dark:text-white">
                                {{ $module['expires_at_label'] }}
                            </span>
                        </div>
                    </x-filament::fieldset>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <!-- Unowned add-on modules catalog section showing pricing and prerequisites -->
    <x-filament::section
        heading="Available Add-On Modules"
        description="Expand your workspace with additional capabilities billed monthly."
        icon="heroicon-o-squares-plus"
    >
        @if (count($this->availableAddOns) === 0)
            <!-- Notice when tenant already owns every module in the catalog -->
            <p class="text-sm text-gray-600 dark:text-gray-400">
                All available SaaS modules are already included in your workspace.
            </p>
        @else
            <!-- Add-on catalog cards grid -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->availableAddOns as $addon)
                    <x-filament::fieldset :label="$addon['name']">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                {{ $addon['required_modules_text'] }}
                            </span>
                            <x-filament::badge color="gray">
                                {{ $addon['formatted_monthly_price'] }} / mo
                            </x-filament::badge>
                        </div>
                    </x-filament::fieldset>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
