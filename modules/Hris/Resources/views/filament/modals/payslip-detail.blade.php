<!-- Native Filament 5 payslip detail breakdown modal displaying pre-formatted PHP values -->
<div class="space-y-5 p-1">
    <!-- Employee & payroll period metadata section -->
    <x-filament::section
        heading="Identitas & Periode"
        description="Ringkasan informasi karyawan dan kehadiran pada periode penggajian."
    >
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <!-- Employee identifier and full name -->
            <x-filament::fieldset label="Karyawan">
                <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $employeeName }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $employeeCode }} · PTKP {{ $ptkpStatus }}</p>
            </x-filament::fieldset>

            <!-- Department and job position -->
            <x-filament::fieldset label="Departemen & Jabatan">
                <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $positionName }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $departmentName }}</p>
            </x-filament::fieldset>

            <!-- Payroll batch and attendance recap -->
            <x-filament::fieldset label="Periode & Kehadiran">
                <p class="text-sm font-semibold text-gray-950 dark:text-white tabular-nums">{{ $periodLabel }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 tabular-nums">
                    Hadir {{ $attendanceDays }}h · Telat {{ $lateMinutes }}m · Lembur {{ $overtimeMinutes }}m
                </p>
            </x-filament::fieldset>
        </div>
    </x-filament::section>

    <!-- Earnings and deductions breakdown columns -->
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <!-- Earnings breakdown section -->
        <x-filament::section heading="Pendapatan (Earnings)">
            <div class="space-y-2.5">
                @forelse ($earnings as $row)
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <span class="text-gray-600 dark:text-gray-300">{{ $row['name'] }}</span>
                        <span class="font-medium text-right text-gray-950 dark:text-white tabular-nums whitespace-nowrap">{{ $row['amount'] }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">Tidak ada komponen pendapatan.</p>
                @endforelse

                <!-- Gross earnings total footer -->
                <div class="flex items-center justify-between gap-4 border-t border-gray-200 pt-3 text-sm font-semibold dark:border-white/10">
                    <span class="text-gray-950 dark:text-white">Total Pendapatan Bruto</span>
                    <span class="text-right text-gray-950 dark:text-white tabular-nums whitespace-nowrap">{{ $formattedGrossSalary }}</span>
                </div>
            </div>
        </x-filament::section>

        <!-- Statutory and other employee deductions section -->
        <x-filament::section heading="Potongan Karyawan (Deductions)">
            <div class="space-y-2.5">
                @forelse ($deductions as $row)
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <span class="text-gray-600 dark:text-gray-300">{{ $row['name'] }}</span>
                        <span class="font-medium text-right text-danger-600 dark:text-danger-400 tabular-nums whitespace-nowrap">- {{ $row['amount'] }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">Tidak ada potongan PPh 21, BPJS, atau potongan lain.</p>
                @endforelse

                <!-- Deductions total footer -->
                <div class="flex items-center justify-between gap-4 border-t border-gray-200 pt-3 text-sm font-semibold dark:border-white/10">
                    <span class="text-gray-950 dark:text-white">Total Potongan</span>
                    <span class="text-right text-gray-950 dark:text-white tabular-nums whitespace-nowrap">{{ $formattedTotalDeductions }}</span>
                </div>
            </div>
        </x-filament::section>
    </div>

    <!-- Employer BPJS contribution disclosure section -->
    <x-filament::section
        heading="Kontribusi BPJS Ditanggung Perusahaan"
        description="Manfaat BPJS yang dibayarkan oleh perusahaan (tidak memotong Take Home Pay)."
    >
        @if (count($contributions) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">Tidak ada kontribusi BPJS perusahaan pada slip ini.</p>
        @else
            <div class="space-y-2">
                @foreach ($contributions as $row)
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <span class="text-gray-600 dark:text-gray-300">{{ $row['name'] }}</span>
                        <span class="font-medium text-right text-gray-950 dark:text-white tabular-nums whitespace-nowrap">{{ $row['amount'] }}</span>
                    </div>
                @endforeach

                <div class="flex items-center justify-between gap-4 border-t border-gray-200 pt-3 text-sm font-semibold dark:border-white/10">
                    <span class="text-gray-950 dark:text-white">Total Kontribusi Perusahaan</span>
                    <span class="text-right text-gray-950 dark:text-white tabular-nums whitespace-nowrap">{{ $formattedTotalContributions }}</span>
                </div>
            </div>
        @endif
    </x-filament::section>

    <!-- Final net Take Home Pay summary section -->
    <x-filament::section>
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Take Home Pay ({{ $payrollNumber }})</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">Gaji bersih yang diterima karyawan</p>
            </div>
            <x-filament::badge color="success" size="lg" class="tabular-nums whitespace-nowrap">
                {{ $formattedNetSalary }}
            </x-filament::badge>
        </div>
    </x-filament::section>
</div>
