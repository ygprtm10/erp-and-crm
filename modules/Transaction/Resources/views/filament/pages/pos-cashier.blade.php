<!-- Interactive two-column POS Cashier page built strictly with native Filament 5 Blade components -->
<x-filament-panels::page>
    @if (! $this->activeShift)
        <!-- Guided empty state when no cashier shift is currently open -->
        <x-filament::section
            heading="Shift Kasir Belum Dibuka"
            description="Anda harus membuka shift kasir pada cabang aktif sebelum dapat memproses transaksi penjualan POS."
            icon="heroicon-o-clock"
        >
            <div class="flex flex-wrap items-center justify-between gap-4 p-1">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Buka shift kasir untuk mencatat modal awal kas laci dan melacak total transaksi tunai harian secara akurat.
                </p>

                <x-filament::button
                    tag="a"
                    :href="$this->shiftsPageUrl"
                    icon="heroicon-m-play"
                    color="primary"
                >
                    Buka Shift Kasir
                </x-filament::button>
            </div>
        </x-filament::section>
    @else
        <!-- Active cashier shift header bar with generous bottom spacing -->
        <div class="mb-6">
            <x-filament::section>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <x-filament::badge color="success" icon="heroicon-m-check-circle">
                            Shift Aktif
                        </x-filament::badge>
                        <span class="text-sm font-medium text-gray-950 dark:text-white">
                            Cabang: {{ $this->activeShift->branch?->name ?? '-' }}
                        </span>
                    </div>

                    <x-filament::button
                        tag="a"
                        :href="$this->shiftsPageUrl"
                        color="gray"
                        size="sm"
                        icon="heroicon-m-clock"
                    >
                        Kelola Shift
                    </x-filament::button>
                </div>
            </x-filament::section>
        </div>

        <!-- Responsive 2-column POS workspace: catalog on the left, sticky cart and checkout on the right -->
        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-12">
            <!-- Left column: searchable product variant catalog -->
            <div class="lg:col-span-7 xl:col-span-8">
                <x-filament::section
                    heading="Katalog Produk & Varian"
                    description="Cari nama produk, varian, SKU, atau barcode lalu tambahkan ke keranjang."
                >
                    <!-- Full-width product search bar -->
                    <div class="mb-4 w-full">
                        <x-filament::input.wrapper class="w-full" prefix-icon="heroicon-m-magnifying-glass">
                            <x-filament::input
                                type="text"
                                wire:model.live.debounce.300ms="search"
                                placeholder="Cari produk, SKU, atau barcode..."
                            />
                        </x-filament::input.wrapper>
                    </div>

                    @if (count($this->catalogItems) === 0)
                        <!-- Empty catalog search state -->
                        <div class="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-white/15 dark:text-gray-400">
                            Tidak ada varian produk aktif yang cocok dengan pencarian.
                        </div>
                    @else
                        <!-- Responsive 3-column desktop catalog grid -->
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach ($this->catalogItems as $item)
                                <!-- Individual catalog variant card -->
                                <div class="flex flex-col justify-between gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                                    <div class="space-y-2">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">
                                                    {{ $item['name'] }}
                                                </h4>
                                                <p class="mt-0.5 font-mono text-xs text-gray-500 dark:text-gray-400">
                                                    {{ $item['sku'] }}
                                                </p>
                                            </div>

                                            <x-filament::badge :color="$item['stock_color']" size="sm" class="shrink-0">
                                                {{ $item['stock_label'] }}
                                            </x-filament::badge>
                                        </div>

                                        <div class="flex items-baseline gap-2 pt-1">
                                            <span class="text-base font-bold tabular-nums text-gray-950 dark:text-white">
                                                {{ $item['formatted_price'] }}
                                            </span>
                                            @if ($item['has_discount'])
                                                <span class="text-xs line-through tabular-nums text-gray-400 dark:text-gray-500">
                                                    {{ $item['formatted_base_price'] }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>

                                    <x-filament::button
                                        wire:click="addToCart({{ $item['id'] }})"
                                        :disabled="! $item['can_add']"
                                        icon="heroicon-m-plus"
                                        color="primary"
                                        size="sm"
                                        class="w-full"
                                    >
                                        Tambah
                                    </x-filament::button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-filament::section>
            </div>

            <!-- Right column: sticky cart summary, customer, voucher, and payment checkout -->
            <div class="lg:sticky lg:top-6 lg:col-span-5 xl:col-span-4">
                <x-filament::section
                    heading="Keranjang & Pembayaran"
                    description="Atur kuantitas, terapkan voucher, lalu selesaikan pembayaran."
                >
                    <div class="space-y-5">
                        @if (! $this->cartSummary['has_items'])
                            <!-- Empty cart state -->
                            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-white/15 dark:text-gray-400">
                                Keranjang masih kosong. Pilih produk di sebelah kiri untuk memulai transaksi.
                            </div>
                        @else
                            <!-- Cart line items container -->
                            <div class="divide-y divide-gray-200 rounded-xl border border-gray-200 dark:divide-white/10 dark:border-white/10">
                                @foreach ($this->cartSummary['items'] as $cartItem)
                                    <!-- Single cart item row -->
                                    <div class="flex items-center justify-between gap-3 p-3">
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
                                                {{ $cartItem['name'] }}
                                            </p>
                                            <p class="mt-0.5 text-xs tabular-nums text-gray-500 dark:text-gray-400">
                                                {{ $cartItem['formatted_unit_price'] }} / {{ $cartItem['unit'] }}
                                            </p>
                                        </div>

                                        <div class="w-24 shrink-0">
                                            <x-filament::input.wrapper class="w-full">
                                                <x-filament::input
                                                    type="number"
                                                    step="0.01"
                                                    min="0.01"
                                                    :value="$cartItem['qty']"
                                                    wire:change="updateCartQty({{ $cartItem['variant_id'] }}, $event.target.value)"
                                                />
                                            </x-filament::input.wrapper>
                                        </div>

                                        <div class="shrink-0 text-right">
                                            <p class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white whitespace-nowrap">
                                                {{ $cartItem['formatted_subtotal'] }}
                                            </p>
                                            <button
                                                type="button"
                                                wire:click="removeFromCart({{ $cartItem['variant_id'] }})"
                                                class="mt-1 text-xs font-medium text-danger-600 hover:underline dark:text-danger-400"
                                            >
                                                Hapus
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <!-- Customer and voucher inputs -->
                        <div class="space-y-4">
                            <!-- Optional customer selector -->
                            <div class="space-y-1.5">
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                                    Pelanggan (Opsional)
                                </label>
                                <x-filament::input.wrapper class="w-full">
                                    <x-filament::input.select wire:model="contactId">
                                        <option value="">Walk-in Customer</option>
                                        @foreach ($this->customerOptions as $id => $name)
                                            <option value="{{ $id }}">{{ $name }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </div>

                            <!-- Proportional full-width voucher code input row -->
                            <div class="space-y-1.5">
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                                    Kode Voucher
                                </label>
                                <div class="flex w-full items-center gap-2">
                                    <x-filament::input.wrapper class="min-w-0 flex-1">
                                        <x-filament::input
                                            type="text"
                                            wire:model="voucherCode"
                                            placeholder="Masukkan kode voucher..."
                                        />
                                    </x-filament::input.wrapper>

                                    <x-filament::button
                                        wire:click="applyVoucher"
                                        color="gray"
                                        size="md"
                                        class="shrink-0"
                                    >
                                        Terapkan
                                    </x-filament::button>

                                    @if ($appliedVoucherCode)
                                        <x-filament::button
                                            wire:click="clearVoucher"
                                            color="danger"
                                            size="md"
                                            class="shrink-0"
                                        >
                                            Hapus
                                        </x-filament::button>
                                    @endif
                                </div>

                                @if ($voucherFeedback !== '')
                                    <p class="text-xs {{ $voucherFeedbackValid ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                                        {{ $voucherFeedback }}
                                    </p>
                                @endif
                            </div>
                        </div>

                        <!-- Financial totals summary card pre-computed via bcmath -->
                        <div class="space-y-2.5 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm dark:border-white/10 dark:bg-white/5">
                            <div class="flex items-center justify-between gap-4 text-gray-600 dark:text-gray-400">
                                <span>Subtotal</span>
                                <span class="text-right tabular-nums whitespace-nowrap">{{ $this->cartSummary['formatted_subtotal'] }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-4 text-gray-600 dark:text-gray-400">
                                <span>Total Diskon</span>
                                <span class="text-right tabular-nums whitespace-nowrap">-{{ $this->cartSummary['formatted_discount_total'] }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-4 text-gray-600 dark:text-gray-400">
                                <span>Pajak ({{ $this->cartSummary['tax_rate_label'] }})</span>
                                <span class="text-right tabular-nums whitespace-nowrap">{{ $this->cartSummary['formatted_tax_total'] }}</span>
                            </div>
                            <div class="mt-2.5 flex items-center justify-between gap-4 border-t border-gray-200 pt-2.5 text-base font-semibold text-gray-950 dark:border-white/10 dark:text-white">
                                <span>Grand Total</span>
                                <span class="text-right tabular-nums whitespace-nowrap">{{ $this->cartSummary['formatted_grand_total'] }}</span>
                            </div>
                        </div>

                        <!-- Payment controls and checkout submission -->
                        <div class="space-y-4">
                            <!-- Payment method selection -->
                            <div class="space-y-1.5">
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                                    Metode Pembayaran
                                </label>
                                <x-filament::input.wrapper class="w-full">
                                    <x-filament::input.select wire:model="paymentMethod">
                                        @foreach ($this->paymentMethodOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </div>

                            @if ($this->hasAccountingModule)
                                <!-- Optional accounting asset account when accounting module is active -->
                                <div class="space-y-1.5">
                                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                                        Akun Kas / Bank (Akuntansi)
                                    </label>
                                    <x-filament::input.wrapper class="w-full">
                                        <x-filament::input.select wire:model="paymentAccountId">
                                            @foreach ($this->paymentAccountOptions as $id => $label)
                                                <option value="{{ $id }}">{{ $label }}</option>
                                            @endforeach
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                </div>
                            @endif

                            <!-- Order notes -->
                            <div class="space-y-1.5">
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                                    Catatan Pesanan (Opsional)
                                </label>
                                <x-filament::input.wrapper class="w-full">
                                    <x-filament::input
                                        type="text"
                                        wire:model="orderNotes"
                                        placeholder="Catatan tambahan..."
                                    />
                                </x-filament::input.wrapper>
                            </div>

                            <!-- Primary checkout submit button -->
                            <x-filament::button
                                wire:click="checkout"
                                :disabled="! $this->cartSummary['has_items']"
                                icon="heroicon-m-banknotes"
                                color="primary"
                                class="w-full"
                            >
                                Bayar &amp; Selesaikan
                            </x-filament::button>
                        </div>
                    </div>
                </x-filament::section>
            </div>
        </div>
    @endif
</x-filament-panels::page>
