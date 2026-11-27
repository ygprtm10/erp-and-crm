<!-- Tenant IoT monitoring and ESP32 API guide page built strictly with native Filament 5 components -->
<x-filament-panels::page>
    <!-- Operational summary metrics section -->
    <x-filament::section
        heading="Ringkasan Status Perangkat &amp; Absensi IoT"
        description="Pantau konektivitas terminal ESP32 dan aktivitas tap RFID hari ini secara real-time."
    >
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <x-filament::fieldset label="Total Perangkat">
                <p class="text-2xl font-semibold tracking-tight tabular-nums text-gray-950 dark:text-white">
                    {{ $this->summaryMetrics['total_devices'] }}
                </p>
            </x-filament::fieldset>

            <x-filament::fieldset label="Perangkat Online">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-2xl font-semibold tracking-tight tabular-nums text-gray-950 dark:text-white">
                        {{ $this->summaryMetrics['online_devices'] }}
                    </p>
                    <x-filament::badge color="success">Online</x-filament::badge>
                </div>
            </x-filament::fieldset>

            <x-filament::fieldset label="Perangkat Offline">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-2xl font-semibold tracking-tight tabular-nums text-gray-950 dark:text-white">
                        {{ $this->summaryMetrics['offline_devices'] }}
                    </p>
                    <x-filament::badge color="gray">Offline</x-filament::badge>
                </div>
            </x-filament::fieldset>

            <x-filament::fieldset label="Tap Hari Ini">
                <p class="text-2xl font-semibold tracking-tight tabular-nums text-gray-950 dark:text-white">
                    {{ $this->summaryMetrics['taps_today'] }}
                </p>
            </x-filament::fieldset>

            <x-filament::fieldset label="Kartu Belum Terdaftar">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-2xl font-semibold tracking-tight tabular-nums text-gray-950 dark:text-white">
                        {{ $this->summaryMetrics['unregistered_count'] }}
                    </p>
                    <x-filament::badge color="warning">Unregistered</x-filament::badge>
                </div>
            </x-filament::fieldset>
        </div>
    </x-filament::section>

    <!-- Active devices heartbeat status table section -->
    <x-filament::section
        heading="Status Perangkat Aktif"
        description="Daftar perangkat ESP32 berstatus aktif beserta waktu sinyal heartbeat terakhir."
    >
        @if (count($this->deviceStatuses) === 0)
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Belum ada perangkat ESP32 aktif yang terdaftar untuk perusahaan ini.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Kode</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Nama Perangkat</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Cabang</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Koneksi</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Heartbeat Terakhir</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Firmware</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($this->deviceStatuses as $device)
                            <tr class="text-gray-950 dark:text-white">
                                <td class="px-4 py-3 font-mono text-xs whitespace-nowrap">{{ $device['device_code'] }}</td>
                                <td class="px-4 py-3">{{ $device['name'] }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ $device['branch_name'] }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <x-filament::badge :color="$device['online_color']">
                                        {{ $device['online_label'] }}
                                    </x-filament::badge>
                                </td>
                                <td class="px-4 py-3 tabular-nums whitespace-nowrap">{{ $device['last_heartbeat'] }}</td>
                                <td class="px-4 py-3 font-mono text-xs whitespace-nowrap">{{ $device['firmware_version'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <!-- ESP32 REST API integration guide section -->
    <x-filament::section
        heading="Panduan Endpoint API ESP32"
        description="Konfigurasi HTTP client firmware ESP32 untuk heartbeat berkala dan pengiriman tap kartu RFID."
    >
        <div class="space-y-6">
            <!-- Authentication header specification -->
            <x-filament::fieldset label="Header Autentikasi Wajib">
                <code class="block rounded-md border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-xs text-gray-950 dark:border-white/10 dark:bg-white/5 dark:text-white">
                    {{ $this->apiGuide['auth_header'] }}
                </code>
            </x-filament::fieldset>

            <!-- Heartbeat endpoint guide -->
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <x-filament::fieldset label="POST Heartbeat Endpoint">
                    <div class="space-y-3">
                        <code class="block break-all rounded-md border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-xs text-gray-950 dark:border-white/10 dark:bg-white/5 dark:text-white">
                            POST {{ $this->apiGuide['heartbeat_url'] }}
                        </code>
                        <p class="text-xs font-medium text-gray-600 dark:text-gray-400">Contoh Request JSON:</p>
                        <pre class="overflow-x-auto rounded-md border border-gray-200 bg-gray-50 p-3 font-mono text-xs text-gray-950 dark:border-white/10 dark:bg-white/5 dark:text-white">{{ $this->apiGuide['heartbeat_request_json'] }}</pre>
                        <p class="text-xs font-medium text-gray-600 dark:text-gray-400">Contoh Response JSON:</p>
                        <pre class="overflow-x-auto rounded-md border border-gray-200 bg-gray-50 p-3 font-mono text-xs text-gray-950 dark:border-white/10 dark:bg-white/5 dark:text-white">{{ $this->apiGuide['heartbeat_response_json'] }}</pre>
                    </div>
                </x-filament::fieldset>

                <!-- Attendance tap endpoint guide -->
                <x-filament::fieldset label="POST Attendance Tap Endpoint">
                    <div class="space-y-3">
                        <code class="block break-all rounded-md border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-xs text-gray-950 dark:border-white/10 dark:bg-white/5 dark:text-white">
                            POST {{ $this->apiGuide['tap_url'] }}
                        </code>
                        <p class="text-xs font-medium text-gray-600 dark:text-gray-400">Contoh Request JSON:</p>
                        <pre class="overflow-x-auto rounded-md border border-gray-200 bg-gray-50 p-3 font-mono text-xs text-gray-950 dark:border-white/10 dark:bg-white/5 dark:text-white">{{ $this->apiGuide['tap_request_json'] }}</pre>
                        <p class="text-xs font-medium text-gray-600 dark:text-gray-400">Contoh Response OLED JSON:</p>
                        <pre class="overflow-x-auto rounded-md border border-gray-200 bg-gray-50 p-3 font-mono text-xs text-gray-950 dark:border-white/10 dark:bg-white/5 dark:text-white">{{ $this->apiGuide['tap_response_json'] }}</pre>
                    </div>
                </x-filament::fieldset>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
