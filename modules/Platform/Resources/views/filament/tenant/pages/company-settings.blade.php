<!-- Tenant company settings page built strictly with native Filament 5 Blade components -->
<x-filament-panels::page>
    <!-- Form container delegating configuration updates to UpdateCompanySettingsAction -->
    <form wire:submit="save">
        <!-- Rendered Filament form schema -->
        {{ $this->form }}

        <!-- Submit button row aligned cleanly at the bottom right -->
        <div class="mt-6 flex items-center justify-end gap-3">
            <x-filament::button
                type="submit"
                icon="heroicon-m-check"
                color="primary"
            >
                Save Settings
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
