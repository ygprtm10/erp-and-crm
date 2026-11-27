<!-- Tenant wallet overview custom page built strictly with native Filament 5 Blade components -->
<x-filament-panels::page>
    <!-- Wallet balance summary section using Filament native section and fieldsets -->
    <x-filament::section
        heading="Platform Escrow Wallet"
        description="Balances settled from customer payments processed via Midtrans Platform."
        icon="heroicon-o-wallet"
    >
        <!-- Pre-formatted wallet metrics passed from WalletOverviewPage PHP class -->
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <!-- Available balance metric -->
            <x-filament::fieldset label="Available Balance">
                <p class="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">
                    {{ $this->formattedAvailableBalance }}
                </p>
            </x-filament::fieldset>

            <!-- Pending withdrawal balance metric -->
            <x-filament::fieldset label="Pending Withdrawal">
                <p class="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">
                    {{ $this->formattedPendingBalance }}
                </p>
            </x-filament::fieldset>

            <!-- Active payment gateway mode badge -->
            <x-filament::fieldset label="Payment Gateway Mode">
                <x-filament::badge :color="$this->gatewayStatusColor">
                    {{ $this->gatewayStatusLabel }}
                </x-filament::badge>
            </x-filament::fieldset>
        </div>

        <!-- Withdrawal action trigger aligned to section footer -->
        <div class="mt-6 flex items-center justify-end gap-3">
            <x-filament::button
                wire:click="mountAction('requestWithdrawal')"
                icon="heroicon-m-banknotes"
                color="primary"
            >
                Request Withdraw
            </x-filament::button>
        </div>
    </x-filament::section>

    <!-- Native Filament table rendering append-only wallet transactions -->
    {{ $this->table }}
</x-filament-panels::page>
