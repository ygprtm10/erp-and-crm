<!-- Financial reports page displaying Trial Balance and Profit & Loss using native Filament 5 components -->
<x-filament-panels::page>
    <!-- Date range filter form with native Section footer submit action -->
    <form id="report-period-form" wire:submit="applyFilters">
        {{ $this->form }}
    </form>

    <!-- Native Filament tabs switching between Trial Balance and Profit & Loss -->
    <x-filament::tabs label="Financial Report Views">
        <x-filament::tabs.item
            :active="$activeTab === 'trial_balance'"
            wire:click="setActiveTab('trial_balance')"
            icon="heroicon-m-scale"
        >
            Neraca Saldo (Trial Balance)
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'profit_and_loss'"
            wire:click="setActiveTab('profit_and_loss')"
            icon="heroicon-m-presentation-chart-line"
        >
            Laba Rugi (Profit &amp; Loss)
        </x-filament::tabs.item>
    </x-filament::tabs>

    @if ($activeTab === 'trial_balance')
        <!-- Trial Balance section -->
        <x-filament::section
            heading="Neraca Saldo (Trial Balance)"
            description="Aggregated posted general ledger balances for the selected reporting period."
        >
            <x-slot name="afterHeader">
                <x-filament::badge :color="$this->trialBalanceData['badge_color']">
                    {{ $this->trialBalanceData['badge_label'] }}
                </x-filament::badge>
            </x-slot>

            @if (count($this->trialBalanceData['rows']) === 0)
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    No posted journal entries found in the selected period.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Code</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Account Name</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 whitespace-nowrap">Debit</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 whitespace-nowrap">Credit</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 whitespace-nowrap">Ending Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($this->trialBalanceData['rows'] as $row)
                                <tr class="text-gray-950 dark:text-white">
                                    <td class="px-4 py-3 text-left font-mono text-xs text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $row['code'] }}</td>
                                    <td class="px-4 py-3 text-left">{{ $row['name'] }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ $row['debit'] }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ $row['credit'] }}</td>
                                    <td class="px-4 py-3 text-right font-medium tabular-nums whitespace-nowrap">{{ $row['ending'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 bg-gray-50/75 font-semibold text-gray-950 dark:border-white/10 dark:bg-white/5 dark:text-white">
                                <td class="px-4 py-3 text-left" colspan="2">Total Keseluruhan</td>
                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ $this->trialBalanceData['total_debit'] }}</td>
                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ $this->trialBalanceData['total_credit'] }}</td>
                                <td class="px-4 py-3 text-right"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @else
        <!-- Profit & Loss statement section -->
        <x-filament::section
            heading="Laporan Laba Rugi (Profit &amp; Loss)"
            description="Posted revenue minus COGS and operating expenses for the selected reporting period."
        >
            <!-- Revenue group table -->
            <div class="space-y-3">
                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Pendapatan (Revenue)</h4>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Code</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Account Name</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 whitespace-nowrap">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @forelse ($this->profitAndLossData['revenues'] as $row)
                                <tr class="text-gray-950 dark:text-white">
                                    <td class="px-4 py-3 text-left font-mono text-xs text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $row['code'] }}</td>
                                    <td class="px-4 py-3 text-left">{{ $row['name'] }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ $row['amount'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="px-4 py-3 text-left text-gray-600 dark:text-gray-400" colspan="3">
                                        No posted revenue entries.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 bg-gray-50/75 font-semibold text-gray-950 dark:border-white/10 dark:bg-white/5 dark:text-white">
                                <td class="px-4 py-3 text-left" colspan="2">Total Pendapatan</td>
                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ $this->profitAndLossData['total_revenue'] }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Expense / COGS group table -->
            <div class="mt-6 space-y-3">
                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Beban &amp; HPP (Expenses)</h4>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Code</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Account Name</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 whitespace-nowrap">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @forelse ($this->profitAndLossData['expenses'] as $row)
                                <tr class="text-gray-950 dark:text-white">
                                    <td class="px-4 py-3 text-left font-mono text-xs text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $row['code'] }}</td>
                                    <td class="px-4 py-3 text-left">{{ $row['name'] }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ $row['amount'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="px-4 py-3 text-left text-gray-600 dark:text-gray-400" colspan="3">
                                        No posted expense entries.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 bg-gray-50/75 font-semibold text-gray-950 dark:border-white/10 dark:bg-white/5 dark:text-white">
                                <td class="px-4 py-3 text-left" colspan="2">Total Beban &amp; HPP</td>
                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ $this->profitAndLossData['total_expense'] }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Net profit / loss summary row -->
            <div class="mt-6 flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50/75 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                <span class="text-base font-semibold text-gray-950 dark:text-white">Laba / Rugi Bersih</span>
                <x-filament::badge :color="$this->profitAndLossData['net_color']" class="tabular-nums whitespace-nowrap">
                    {{ $this->profitAndLossData['net_profit'] }}
                </x-filament::badge>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
