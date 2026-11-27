# Blueprint Arsitektur & Instruksi AI Agent — Modular SaaS ERP & CRM

Dokumen ini adalah **Single Source of Truth** untuk seluruh pengembangan aplikasi SaaS ERP & CRM Multi-Tenant. Setiap kali AI Agent (Antigravity IDE) diminta membuat fitur, migrasi, model, atau antarmuka baru, AI **WAJIB** mematuhi seluruh aturan, standar Clean Code, konvensi komentar, standar UI anti-*AI slop* berbasis komponen Filament 5, kewajiban Unit Test, larangan membuka browser, struktur folder, pola komunikasi antar modul, aturan *payment gateway*, dan skema database di bawah ini.

## 1. Aturan Wajib AI Agent (Guardrails)

1. **Pertahankan Struktur & Komentar Eksisting:**
   * DILARANG menghapus, mengubah, atau meringkas komentar kode (*code comments*) yang sudah ada tanpa instruksi eksplisit.
   * DILARANG melakukan *refactor* pada file atau fungsi di luar ruang lingkup tugas yang sedang diminta.

2. **Anti-Halusinasi & Akurasi Versi:**
   * Gunakan sintaks dan konvensi resmi **Laravel 13**, **Filament 5**, **Livewire 4**, dan **PHP 8.3+**.
   * Jika memakai *package* atau *method* tertentu, pastikan memang ada di versi tersebut; jangan mengarang *namespace*, *helper*, atau nama komponen Blade yang tidak ada.

3. **Standar Lingkungan Pengembangan:**
   * OS lokal: **Windows 10** dengan **PowerShell** dan **Laravel Herd** (`.test` domain). Pastikan seluruh perintah terminal yang disarankan kompatibel dengan PowerShell di Windows 10.
   * Alur Git: selalu kerjakan per fitur/modul pada *feature branch* terpisah.

4. **Standar Clean Code (*Base Code Quality*):**
   * Seluruh *base code* WAJIB menerapkan prinsip **Clean Code** dan **SOLID** (Single Responsibility Principle, Dependency Injection, dan pemisahan tanggung jawab yang jelas).
   * Gunakan **strict typing** di PHP: setiap parameter dan *return type* function wajib dideklarasikan secara eksplisit, gunakan PHP `enum` atau konstanta untuk menghindari *magic strings* dan *magic numbers*.
   * Terapkan pola **Early Return** untuk menghindari *nested* `if/else` yang dalam dan sulit dibaca.
   * Jaga agar Controller, Livewire Class, dan Filament Resource/Page tetap ramping (*thin*); seluruh logika bisnis WAJIB dipisahkan ke `Actions/` atau `Services/` dengan nama method yang deskriptif.

5. **Larangan Keras PHP di Dalam Blade (*Zero-PHP in Blade*):**
   * **DILARANG KERAS** menggunakan tag `@php ... @endphp` maupun `<?php ... ?>` di dalam file `.blade.php`.
   * **DILARANG** menulis logika PHP, *query* database, kalkulasi, atau manipulasi variabel kompleks di dalam file `.blade.php`.
   * Seluruh kode PHP WAJIB diletakkan di dalam **PHP Class** terpisah (Class-based Livewire 4 Components, Class-based Blade Components, Filament Page Classes, Controllers, ViewModels, Actions, Services, atau Model Accessors). Jangan gunakan sintaks single-file yang mencampur blok `<?php` di dalam file `.blade.php`.
   * File `.blade.php` hanya bertugas menampilkan data yang sudah matang dari PHP Class menggunakan directive tampilan dasar (`@if`, `@foreach`, `{{ $variable }}`, komponen Blade Filament 5, Tailwind CSS, dan Alpine.js).
   * Pisahkan secara tegas antara **stateless Blade UI components** (murni tampilan) dan **stateful Livewire 4 components** (mengelola *state* dan aksi lewat PHP Class).

6. **Standar Wajib Komentar Kode (*English Commenting Convention*):**
   * Setiap **class, function/method, property penting, dan blok logika penting** WAJIB diberikan komentar dalam **Bahasa Inggris** yang ringkas, jelas, dan menjelaskan tujuan/konteksnya.
   * **Di dalam PHP Class:** WAJIB menggunakan format komentar **`/** ... */`** (PHPDoc / Docblock) untuk class, setiap method, maupun penanda blok kode penting di dalam class.
   * **Di dalam file Blade (`.blade.php`):** WAJIB menggunakan format komentar HTML **`<!-- ... -->`** dalam Bahasa Inggris untuk menandai setiap bagian/seksi antarmuka penting (jangan gunakan `{{-- ... --}}` atau komentar PHP).

   **Contoh Benar — PHP Class:**

   ```php
   <?php

   namespace Modules\Transaction\Actions;

   use Modules\Transaction\Models\Order;

   /**
    * Handles order total calculation with strict decimal precision.
    */
   class CalculateOrderTotal
   {
       /**
        * Calculate the final grand total for the given order.
        */
       public function execute(Order $order): string
       {
           /** Validate that the order contains at least one line item before computing totals. */
           if ($order->items->isEmpty()) {
               return '0.0000';
           }

           /** Subtract total discounts from subtotal and apply tax using bcmath precision. */
           $afterDiscount = bcsub($order->subtotal, $order->discount_total, 4);

           return bcadd($afterDiscount, $order->tax_total, 4);
       }
   }
   ```

   **Contoh Benar — File Blade Publik (`.blade.php`):**

   ```blade
   <!-- Order summary card displaying pre-computed totals from the Livewire class -->
   <div class="rounded-lg border border-gray-200 bg-white p-4">
       <!-- Order header section -->
       <h3 class="text-lg font-semibold text-gray-900">{{ $orderNumber }}</h3>

       <!-- Pre-formatted grand total passed directly from the PHP class -->
       <p class="mt-2 text-xl font-bold text-gray-900">{{ $formattedGrandTotal }}</p>
   </div>
   ```

   *(DILARANG menulis `@php $total = $subtotal - $discount; @endphp` di dalam file `.blade.php`.)*

7. **Disiplin Batas Modul (*Strict Module Boundaries*):**
   * Modul A DILARANG melakukan *write/mutate* langsung ke tabel milik Modul B (contoh: modul `Transaction` tidak boleh langsung memanggil `Journal::create()` atau mengubah `saas_tenant_wallets` secara langsung).
   * Komunikasi lintas modul WAJIB menggunakan **Laravel Events & Listeners** atau memanggil **Action/Contract** resmi milik modul tujuan.

8. **Integritas Data, Finansial & Dompet (*Escrow Safety*):**
   * Seluruh nilai uang WAJIB menggunakan `DECIMAL(19, 4)` (dilarang `FLOAT`/`DOUBLE`).
   * Seluruh kuantitas barang, berat laundry, dan kuota membership WAJIB menggunakan `DECIMAL(10, 2)` atau `DECIMAL(12, 2)`.
   * Operasi penulisan transaksi, stok, jurnal, dan mutasi saldo dompet WAJIB dibungkus `DB::transaction()`.
   * Perubahan saldo pada `saas_tenant_wallets` WAJIB menggunakan *pessimistic locking* (`->lockForUpdate()`) dan WAJIB selalu disertai 1 baris *append-only* di `saas_wallet_transactions` agar anti *race-condition* dan dapat diaudit.
   * Seluruh tabel pengaturan (`company_settings`, `hr_payroll_settings`, `web_settings`) WAJIB memakai struktur **per-column** (bukan *key-value*), dengan enkripsi (`encrypted` cast) untuk kunci API pihak ketiga (`midtrans_server_key`, `fonnte_token`).
   * Seluruh model operasional WAJIB menggunakan trait `BelongsToCompany` untuk isolasi *tenant* otomatis.

9. **Wajib Unit Test untuk Setiap Kode & Function (*Zero-Bug Testing Discipline*):**
   * Setiap **class, function/method, Action, Service, Model logic, Event Listener, dan API/Webhook handler** yang dibuat atau dimodifikasi WAJIB langsung disertai **Unit Test** (di `modules/{ModuleName}/Tests/Unit/`) — dan **Feature Test** (di `modules/{ModuleName}/Tests/Feature/`) bila menyangkut alur HTTP/Webhook/Event/Database lintas komponen.
   * Setiap Unit Test WAJIB mencakup minimal: **happy path** (alur normal), **edge cases** (nilai nol, presisi `bcmath`, batas saldo/kuota, *idempotency* webhook/IoT), dan **failure/validation cases** (input tidak valid, akses lintas tenant ditolak, debit != kredit ditolak).
   * Seluruh file test tetap WAJIB mematuhi standar **Clean Code**, *strict typing*, dan komentar Bahasa Inggris (`/** ... */`).
   * Sebelum menyatakan tugas selesai, AI WAJIB menjalankan `php artisan test` melalui PowerShell dan memastikan **seluruh test berstatus PASS (0 failures, 0 errors)**.

10. **Larangan Membuka Browser & Kendali Regulasi Penuh di Tangan User (*No Browser Automation & User Authority*):**
    * **DILARANG KERAS** membuka browser, menjalankan *browser tool/subagent*, *headless browser* (Playwright, Puppeteer, Selenium, Laravel Dusk), atau *web preview* untuk mengecek fitur, menguji alur klik, ataupun memeriksa tampilan UI.
    * AI cukup memverifikasi kebenaran logika dan integritas kode secara statis serta melalui pengujian CLI (`php artisan test`).
    * **Segala regulasi, pengecekan UI secara manual di browser, aturan bisnis, kebijakan harga/fee/withdraw, serta keputusan *approval* akhir sepenuhnya berada di tangan User.** AI DILARANG mengubah regulasi bisnis, menambahkan asumsi aturan sepihak, atau mengambil tindakan di luar instruksi eksplisit User. Jika ada celah aturan bisnis yang belum ditentukan, AI wajib bertanya kepada User daripada mengarang aturan sendiri.

11. **Standar UI Anti-"AI Slop" & Wajib Komponen Blade Filament 5 (*Native Filament UI Standard*):**
    * **DILARANG KERAS menghasilkan UI "AI Slop":**
      * DILARANG memakai gradasi warna generik/norak (misal `bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500`), efek *glow*/neon shadow, *glassmorphism* berlebihan, animasi memantul (`animate-bounce`/`animate-pulse`) tanpa fungsi nyata, atau taburan emoji sebagai pengganti ikon di dalam UI ERP/CRM.
      * DILARANG membuat *card* bersarang berlebihan, *badge* warna-warni acak di luar palet semantik, atau *hardcoded* warna statis yang merusak tampilan *Light Mode* dan *Dark Mode*.
      * Utamakan estetika aplikasi bisnis/ERP yang bersih, tajam, rapi, memiliki hierarki tipografi yang jelas, kepadatan informasi yang tepat, dan konsisten di seluruh halaman.
    * **Wajib Komponen Blade & Builder Bawaan Filament 5 untuk Setiap Custom Page:**
      * Saat membuat *Custom Page* di dalam Panel Filament 5 (`Admin` maupun `Tenant`), **WAJIB** menggunakan komponen Blade resmi Filament 5 dan/atau Filament Schema/Table/Infolist/Action Builder daripada merakit ulang `<div class="bg-white rounded-xl shadow ...">`, `<button>`, atau modal mentah dari nol.
      * Gunakan komponen bawaan Filament 5 seperti:
        * `<x-filament-panels::page>` sebagai pembungkus halaman wajib.
        * `<x-filament::section>` untuk blok konten/panel (dengan atribut `heading`, `description`, `icon`, `collapsible`, atau slot `headerEnd`).
        * `<x-filament::button>` dan `<x-filament::link>` untuk tombol dan tautan aksi (gunakan warna semantik Filament: `primary`, `gray`, `success`, `warning`, `danger`, `info`).
        * `<x-filament::badge>` untuk status dan label ringkas.
        * `<x-filament::tabs>` dan `<x-filament::tabs.item>` untuk navigasi tab.
        * `<x-filament::modal>` untuk dialog/konfirmasi kustom jika tidak memakai Filament Action Modal.
        * `<x-filament::input.wrapper>`, `<x-filament::fieldset>`, `<x-filament::dropdown>`, `<x-filament::icon>`, `<x-filament::loading-indicator>`, dan `<x-filament::breadcrumbs>` sesuai kebutuhan antarmuka.
      * Gunakan **Heroicons** (atau icon set resmi yang terdaftar di panel Filament) melalui properti `icon` bawaan Filament—jangan menulis SVG mentah panjang di dalam Blade kecuali logo kustom.
      * Jika *custom page* membutuhkan form, tabel, atau ringkasan data, prioritaskan merender Filament Schema/Form, Filament Table, atau Filament Widgets di dalam `<x-filament-panels::page>` agar *spacing*, validasi, dan *dark mode* 100% menyatu dengan sistem.
    * **Standar UI Halaman Non-Panel (POS, QR Order, Web Perusahaan):**
      * Tetap terapkan prinsip anti-*AI slop*: tata letak fungsional, kontras keterbacaan tinggi, warna netral + 1 warna aksen utama dari pengaturan tenant (`web_settings.primary_color`), komponen Blade berbasis PHP Class yang modular, tanpa dekorasi klise.

    **Contoh Benar — Filament 5 Custom Page Blade (`modules/Platform/Resources/views/filament/tenant/pages/wallet-overview.blade.php`):**

    ```blade
    <!-- Tenant wallet overview custom page built strictly with native Filament 5 Blade components -->
    <x-filament-panels::page>
        <!-- Wallet balance summary section using Filament native section and grid utilities -->
        <x-filament::section
            heading="Ringkasan Dompet Platform"
            description="Saldo dana dari transaksi pelanggan yang menggunakan Midtrans Platform."
        >
            <!-- Balance metrics grid with pre-formatted values from the Filament Page PHP class -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <!-- Available balance metric block -->
                <x-filament::fieldset label="Saldo Tersedia">
                    <p class="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">
                        {{ $this->formattedAvailableBalance }}
                    </p>
                </x-filament::fieldset>

                <!-- Pending withdrawal balance metric block -->
                <x-filament::fieldset label="Dalam Proses Penarikan">
                    <p class="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">
                        {{ $this->formattedPendingBalance }}
                    </p>
                </x-filament::fieldset>

                <!-- Active payment gateway mode indicator -->
                <x-filament::fieldset label="Mode Payment Gateway">
                    <x-filament::badge :color="$this->gatewayStatusColor">
                        {{ $this->gatewayStatusLabel }}
                    </x-filament::badge>
                </x-filament::fieldset>
            </div>

            <!-- Action trigger row aligned to the section footer -->
            <div class="mt-6 flex items-center justify-end gap-3">
                <x-filament::button
                    wire:click="mountAction('requestWithdrawal')"
                    icon="heroicon-m-banknotes"
                    color="primary"
                >
                    Ajukan Penarikan Saldo
                </x-filament::button>
            </div>
        </x-filament::section>

        <!-- Native Filament table rendering wallet ledger history -->
        {{ $this->table }}
    </x-filament-panels::page>
    ```

## 2. Ringkasan Tech Stack, Model Bisnis & Dual-Mode Payment Gateway

### Tech Stack

* **Backend & Admin Panel:** PHP 8.3+, Laravel 13, Filament 5 (Multi-Panel, Multi-Tenancy, & Native Filament Blade Components), Livewire 4 (Class-based).
* **Frontend Publik / POS / QR Order:** Class-based Blade Components, Tailwind CSS, Alpine.js, Laravel Reverb (WebSocket untuk Layar Dapur / KDS).
* **Database & Cache:** MySQL 8+ (1 Database Multi-Tenancy berbasis `company_id`), Redis (Queue & Cache di VPS).
* **Testing:** PHPUnit / Pest via CLI (`php artisan test`) — **tanpa browser testing**.
* **Integrasi:** Midtrans (Snap & Core API — Dual-Mode Platform & Tenant), Fonnte WhatsApp API, Hardware IoT ESP32 (REST API + Offline Buffer Sync).

### Arsitektur Multi-Panel Filament 5

1. **Super Admin Panel (`/backoffice`):** Tanpa tenancy. Khusus pemilik SaaS untuk kelola Tenant (`companies`), Paket (`saas_bundles`), Modul (`saas_modules`), Log Webhook Midtrans Platform, **Manajemen Saldo & Persetujuan Withdraw Tenant (`saas_withdrawals`)**, Tarif Pajak Nasional (`sys_tax_*`), Template COA Default, dan fitur *Impersonate*.
2. **Tenant Panel (`/app/{company:slug}`):** Multi-Tenancy aktif (`Company` sebagai Tenant Model). Menu *sidebar* muncul/hilang secara dinamis sesuai modul aktif milik *tenant* di tabel `company_modules`, dilengkapi menu **Pengaturan Payment Gateway** serta **Dompet Saldo & Request Withdraw**.

### Model Bisnis: Hybrid (Bundle Industri + Add-on Modul)

* Di halaman depan (*landing page*), user memilih paket siap pakai (`saas_bundles`, misal: *Paket Kasir*, *Paket Pembukuan*, *Paket Laundry*, *Paket Gym*, *All-in-One*).
* Di dalam sistem, hak akses dikontrol per modul melalui `company_modules` (`status`, `expires_at`, `limits`). User dapat menambah/memperpanjang modul secara satuan (*add-on*) via Midtrans milik Platform tanpa sistem hitung prorata harian yang rumit.

### Arsitektur Dual-Mode Payment Gateway (Midtrans) & Sistem Withdraw

1. **Langganan SaaS (`saas_subscriptions`):**
   * SELALU menggunakan akun **Midtrans milik Platform** (`config('services.midtrans.*')`).
   * Format `order_number` WAJIB memakai prefix khusus **`SUB-`** agar *webhook* Platform mudah membedakannya dari transaksi milik pelanggan UMKM.

2. **Transaksi Pelanggan UMKM (`trx_orders` — POS, QR Order, Invoice, Web, Membership):**
   * Diselesaikan melalui satu service terpusat (`PaymentGatewayResolver`) berdasarkan `company_settings.payment_gateway_mode`:
     * **Mode A — `own_midtrans` (Midtrans Milik User):** Sistem memakai `midtrans_server_key` & `midtrans_client_key` milik tenant tersebut. *Webhook* diarahkan ke `/api/webhooks/midtrans/tenant/{company:slug}` dan diverifikasi dengan *server key* milik tenant. Dana langsung cair ke akun Midtrans tenant (`trx_payments.gateway_owner = 'tenant'`), **tidak masuk ke saldo dompet Platform**.
     * **Mode B — `platform_midtrans` (Midtrans Milik Platform / Default untuk UMKM baru):** Sistem memakai Midtrans milik Platform dengan prefix order **`TRX-`**. *Webhook* masuk ke `/api/webhooks/midtrans/platform` (`trx_payments.gateway_owner = 'platform'`). Saat status *settlement/capture*, dana bersih (`amount - gateway_fee_amount - platform_fee_amount`) otomatis dikreditkan ke `saas_tenant_wallets.available_balance` dan dicatat di `saas_wallet_transactions`.

3. **Alur Request Withdraw (Khusus Mode `platform_midtrans`):**
   * **Pengajuan (Tenant):** UMKM mendaftarkan rekening bank di `saas_tenant_bank_accounts`, lalu mengajukan penarikan saldo di `saas_withdrawals`. Sistem mengunci baris dompet (`lockForUpdate()`), memvalidasi saldo cukup, memindahkan nominal dari `available_balance` ke `pending_balance` (*hold*), dan menyimpan *snapshot* data rekening di `bank_snapshot`.
   * **Persetujuan & Pencairan (Super Admin):** Admin memverifikasi mutasi, melakukan transfer bank, mengunggah bukti transfer (`transfer_proof_path`), lalu mengubah status menjadi `completed`. Sistem mengurangi `pending_balance`, menambah `total_withdrawn`, menulis baris `debit_withdraw` di `saas_wallet_transactions`, dan memicu event `WithdrawalCompleted` agar jurnal Akuntansi UMKM otomatis mencatat perpindahan dana dari **Saldo Platform** ke **Bank UMKM**.
   * **Penolakan (Super Admin):** Jika status diubah menjadi `rejected` (wajib isi `rejection_reason`), sistem mengembalikan dana dari `pending_balance` ke `available_balance`.

## 3. Rekomendasi Modul Fundamental & Roadmap Pengerjaan

Jangan membangun seluruh 60 tabel dan semua modul sekaligus. Bangun sistem secara bertahap dalam **3 Gelombang**.

### Gelombang 1 — Fundamental Sistem / MVP Inti (WAJIB DIBANGUN PERTAMA)

Empat modul ini adalah tulang punggung aplikasi. Begitu selesai, produk sudah bisa dipakai dan dijual ke UMKM dagang, jasa, maupun laundry—termasuk fitur langsung terima pembayaran QRIS tanpa harus punya akun Midtrans sendiri.

1. **`Platform` (SaaS Core, Billing & Tenant Wallet/Withdraw):**
   * Multi-tenancy (`companies`, `company_settings`, `company_user`), Super Admin Panel, sistem *feature flag* (`saas_modules`, `saas_bundles`, `company_modules`), *checkout* langganan Midtrans Platform (`saas_subscriptions`, `saas_payment_logs`), **Dompet Tenant & Persetujuan Withdraw** (`saas_tenant_wallets`, `saas_wallet_transactions`, `saas_tenant_bank_accounts`, `saas_withdrawals`), dan *Impersonate*.

2. **`Core` (Master Data, CRM & Inventori Dasar):**
   * Cabang (`core_branches`), Kontak terpadu (`crm_contacts` sebagai Customer/Vendor/Member + `crm_interactions`), Katalog Produk & Varian (`core_products`, `core_product_variants`, diskon, voucher), serta Stok per cabang (`inv_stocks`, `inv_stock_movements`).

3. **`Transaction` (Mesin Transaksi Terpadu & Dual-Mode Midtrans):**
   * Satu pintu untuk Invoice B2B, Kasir sederhana, *Tracking* tahapan jasa/laundry (`trx_orders`, `trx_order_items`, `trx_order_stages`, `trx_order_vouchers`, `trx_payments`, `trx_order_logs`), serta `PaymentGatewayResolver` (routing `platform_midtrans` vs `own_midtrans`).

4. **`Accounting` (Akuntansi Double-Entry):**
   * Seeder COA standar per jenis usaha (termasuk akun sistem **Saldo / Piutang Platform** dan **Beban Biaya Payment Gateway**), Jurnal Umum otomatis dari event transaksi/biaya/withdraw (`acc_journals`, `acc_journal_items`), Biaya Operasional (`acc_expenses`), serta Laporan Laba Rugi, Neraca, dan Buku Besar.

### Gelombang 2 — Ekspansi Kanal Penjualan & Retensi

5. **`Pos` (Aplikasi Kasir Penuh & QR Self-Order ala Gacoan):**
   * Layar kasir layar penuh (Class-based Livewire 4 + Alpine.js), Manajemen Shift (`pos_shifts`), Meja & QR Token (`pos_tables`), serta Layar Dapur / KDS *real-time* via Laravel Reverb.

6. **`Web` (Company Profile, Katalog & Tracking Nota Publik):**
   * `web_settings`, `web_pages`, `web_sections`, etalase produk dari `core_products`, dan halaman publik cek status cucian/servis.

7. **`Membership` (Gym, Studio & Deposit Laundry):**
   * `mem_plans`, `mem_subscriptions` (kuota fleksibel `visit`/`kg`/`credit`), dan `mem_checkins`.

### Gelombang 3 — SDM, Performa & Integrasi Hardware

8. **`Hris` (Kepegawaian & Payroll + PPh 21 Opsional):**
   * Master karyawan, shift, absensi, cuti, penggajian, tabel tarif pajak global (`sys_tax_*`), serta sakelar 2 lapis (`hr_payroll_settings.enable_pph21` & `hr_employees.is_taxable`) untuk TER Jan–Nov & rekalkulasi Pasal 17 Desember.

9. **`Iot` (Hardware Absensi & Check-in ESP32):**
   * `iot_devices`, `hr_employee_credentials`, `iot_attendance_logs`, REST API autentikasi token alat, mode *enroll*, dan sinkronisasi *batch offline*.

10. **`Kpi` (Penilaian Kinerja Otomatis):**
    * `hr_kpi_indicators` (driver otomatis dari Omzet, CRM, Absensi IoT, & Order Stages), `hr_kpi_periods`, `hr_kpi_evaluations`, dan integrasi `bonus_amount` ke `hr_payroll_items`.

## 4. Rancangan Struktur Folder Modular Monolith

Satu proyek Laravel 13, satu repositori, namun seluruh logika bisnis dan pengujiannya diisolasi ke dalam folder `modules/`. Folder `app/` hanya menyimpan kode global yang dipakai bersama seluruh sistem.

```text
erp-crm-saas/
├── app/                                      # Global Application Shell (bukan domain bisnis)
│   ├── Concerns/                             # Shared Traits lintas modul
│   │   ├── BelongsToCompany.php              # Global Scope company_id + auto-fill saat creating
│   │   └── RequiresModule.php                # Gate Filament canAccess() berbasis company_modules
│   ├── Models/
│   │   ├── User.php                          # Model autentikasi global
│   │   └── Company.php                       # Filament Tenant Model
│   ├── Providers/
│   │   ├── AppServiceProvider.php
│   │   └── Filament/
│   │       ├── AdminPanelProvider.php        # Panel Super Admin (/backoffice)
│   │       └── AppPanelProvider.php          # Panel Tenant Multi-Tenancy (/app/{slug})
│   └── Support/
│       └── BaseModuleServiceProvider.php     # Parent ServiceProvider untuk semua modul
│
├── modules/                                  # <-- SELURUH MODUL BISNIS DI SINI (Namespace: Modules\)
│   ├── Platform/                             # [Gelombang 1] Billing SaaS, Wallet, Withdraw, SysTax
│   │   ├── Actions/                          # CreditTenantWallet.php, RequestWithdrawal.php, ApproveWithdrawal.php
│   │   ├── Database/
│   │   │   ├── Migrations/
│   │   │   └── Seeders/
│   │   ├── Events/                           # CompanyRegistered.php, WithdrawalCompleted.php
│   │   ├── Filament/
│   │   │   ├── Admin/                        # Resource khusus Super Admin (Tenants, Withdrawals, Logs)
│   │   │   │   ├── Resources/
│   │   │   │   └── Widgets/
│   │   │   └── Tenant/                       # Billing, Toko Modul, Pengaturan Midtrans, Dompet & Withdraw
│   │   │       ├── Pages/
│   │   │       └── Resources/
│   │   ├── Listeners/                        # CreditWalletOnPlatformOrderPaid.php
│   │   ├── Models/
│   │   ├── Providers/
│   │   │   └── PlatformServiceProvider.php
│   │   ├── Resources/views/                  # Custom Page Blade (wajib komponen Filament 5, zero PHP, <!-- -->)
│   │   ├── Routes/
│   │   │   └── api.php                       # Webhook Midtrans Platform (/api/webhooks/midtrans/platform)
│   │   └── Tests/                            # WAJIB ada Unit & Feature test untuk modul ini
│   │       ├── Unit/
│   │       └── Feature/
│   │
│   ├── Core/                                 # [Gelombang 1] Branches, CRM, Products, Inventory
│   │   ├── Actions/
│   │   ├── Database/
│   │   │   └── Migrations/
│   │   ├── Filament/
│   │   │   ├── CorePlugin.php                # Filament 5 Plugin pendaftaran menu modul
│   │   │   └── Resources/
│   │   ├── Listeners/                        # Contoh: DeductStockOnOrderPaid.php
│   │   ├── Models/
│   │   ├── Providers/
│   │   │   └── CoreServiceProvider.php
│   │   └── Tests/
│   │       ├── Unit/
│   │       └── Feature/
│   │
│   ├── Transaction/                          # [Gelombang 1] Unified Orders, Invoice, Payments, Stages
│   │   ├── Actions/                          # CreateOrderPayment.php, HandleMidtransCallback.php
│   │   ├── Database/
│   │   │   └── Migrations/
│   │   ├── Events/                           # OrderPaid.php, OrderStageCompleted.php
│   │   ├── Filament/
│   │   │   ├── TransactionPlugin.php
│   │   │   └── Resources/
│   │   ├── Models/
│   │   ├── Providers/
│   │   │   └── TransactionServiceProvider.php
│   │   ├── Services/
│   │   │   └── PaymentGatewayResolver.php    # Memilih Midtrans Platform vs Midtrans Tenant
│   │   ├── Routes/
│   │   │   └── api.php                       # Webhook Midtrans Tenant (/api/webhooks/midtrans/tenant/{company:slug})
│   │   └── Tests/
│   │       ├── Unit/
│   │       └── Feature/
│   │
│   ├── Accounting/                           # [Gelombang 1] COA, Journals, Expenses, Reports
│   │   ├── Actions/                          # PostJournalEntry.php, SeedDefaultCoa.php
│   │   ├── Contracts/                        # Interface resmi untuk dipanggil modul lain
│   │   ├── Database/
│   │   │   ├── Migrations/
│   │   │   └── Seeders/
│   │   ├── Events/                           # ExpensePaid.php
│   │   ├── Filament/
│   │   │   ├── AccountingPlugin.php
│   │   │   ├── Pages/                        # Neraca, Laba Rugi, Buku Besar (wajib komponen Filament 5)
│   │   │   ├── Resources/
│   │   │   └── Widgets/
│   │   ├── Listeners/                        # RecordOrderJournal.php, RecordWithdrawalJournal.php, RecordPayrollJournal.php
│   │   ├── Models/
│   │   ├── Providers/
│   │   │   └── AccountingServiceProvider.php
│   │   ├── Resources/views/                  # Custom Page Blade (wajib komponen Filament 5, zero PHP, <!-- -->)
│   │   └── Tests/
│   │       ├── Unit/
│   │       └── Feature/
│   │
│   ├── Pos/                                  # [Gelombang 2] Kasir Livewire (Class-based), Shift, QR Order & KDS
│   │   ├── Livewire/                         # PHP Classes untuk komponen interaktif POS & KDS
│   │   ├── Resources/views/                  # Pure Blade templates (zero PHP, <!-- --> comments)
│   │   ├── View/Components/                  # Class-based stateless Blade Components
│   │   └── Tests/                            # Unit/ & Feature/
│   ├── Web/                                  # [Gelombang 2] Company Profile, Katalog, Tracking Nota (+ Tests/)
│   ├── Membership/                           # [Gelombang 2] Gym & Deposit Laundry (+ Tests/)
│   ├── Hris/                                 # [Gelombang 3] Karyawan, Absensi, Payroll, PPh 21 (+ Tests/)
│   ├── Iot/                                  # [Gelombang 3] ESP32 Devices, Credentials, Raw Logs API (+ Tests/)
│   └── Kpi/                                  # [Gelombang 3] Indikator, Driver Auto-Pull, Evaluasi (+ Tests/)
│
├── bootstrap/
│   └── providers.php                         # Registrasi seluruh Module ServiceProvider
├── database/
│   └── migrations/                           # HANYA tabel sistem Laravel (users, jobs, cache, sessions)
├── tests/                                    # Test untuk App shell global (BelongsToCompany, RequiresModule)
├── phpunit.xml                               # Daftarkan testsuites modules/*/Tests/Unit & Feature
└── composer.json                             # Autoload PSR-4 "Modules\\" => "modules/"
```

## 5. Implementasi Teknis Modular Monolith, ServiceProvider & Unit Test

Seluruh contoh di bawah ini sudah mengikuti aturan **Clean Code** dan **English `/** ... */` docblock comments**.

### 5.1 Registrasi Namespace di `composer.json`

Tambahkan mapping PSR-4 untuk `Modules\` lalu jalankan `composer dump-autoload`:

```json
{
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Modules\\": "modules/",
            "Database\\Factories\\": "database/factories/",
            "Database\\Seeders\\": "database/seeders/"
        }
    }
}
```

### 5.2 Parent `BaseModuleServiceProvider` (`app/Support/BaseModuleServiceProvider.php`)

Agar setiap modul tidak mengulang kode *boilerplate* untuk memuat migrasi, rute, view, dan konfigurasi:

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Base service provider that standardizes module asset, migration, and route loading.
 */
abstract class BaseModuleServiceProvider extends ServiceProvider
{
    /**
     * Return the directory name of the module under the modules/ folder.
     */
    abstract protected function moduleName(): string;

    /**
     * Resolve an absolute path inside the current module directory.
     */
    protected function modulePath(string $subPath = ''): string
    {
        $base = base_path('modules/' . $this->moduleName());

        return $subPath !== '' ? $base . '/' . ltrim($subPath, '/') : $base;
    }

    /**
     * Bootstrap module migrations, views, routes, and event listeners.
     */
    public function boot(): void
    {
        $this->bootMigrations();
        $this->bootViews();
        $this->bootRoutes();
        $this->bootListeners();
    }

    /**
     * Register database migrations belonging to this module if present.
     */
    protected function bootMigrations(): void
    {
        $path = $this->modulePath('Database/Migrations');

        if (is_dir($path)) {
            $this->loadMigrationsFrom($path);
        }
    }

    /**
     * Register Blade views under the lowercase module namespace if present.
     */
    protected function bootViews(): void
    {
        $path = $this->modulePath('Resources/views');

        if (is_dir($path)) {
            $this->loadViewsFrom($path, strtolower($this->moduleName()));
        }
    }

    /**
     * Register web and API routes belonging to this module if present.
     */
    protected function bootRoutes(): void
    {
        /** Load module web routes with standard web middleware. */
        $webRoute = $this->modulePath('Routes/web.php');
        if (file_exists($webRoute)) {
            Route::middleware('web')->group($webRoute);
        }

        /** Load module API routes under the /api prefix. */
        $apiRoute = $this->modulePath('Routes/api.php');
        if (file_exists($apiRoute)) {
            Route::prefix('api')->middleware('api')->group($apiRoute);
        }
    }

    /**
     * Register cross-module event listeners in concrete module providers.
     */
    protected function bootListeners(): void
    {
        /** Override in child providers when the module subscribes to domain events. */
    }
}
```

### 5.3 Contoh `ServiceProvider` Modul (`modules/Accounting/Providers/AccountingServiceProvider.php`)

```php
<?php

namespace Modules\Accounting\Providers;

use App\Support\BaseModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Modules\Accounting\Listeners\RecordOrderJournal;
use Modules\Accounting\Listeners\RecordWithdrawalJournal;
use Modules\Platform\Events\WithdrawalCompleted;
use Modules\Transaction\Events\OrderPaid;

/**
 * Service provider for the Accounting domain module.
 */
class AccountingServiceProvider extends BaseModuleServiceProvider
{
    /**
     * Return the module directory name.
     */
    protected function moduleName(): string
    {
        return 'Accounting';
    }

    /**
     * Register module contracts and container bindings.
     */
    public function register(): void
    {
        /** Bind public Accounting interfaces to their concrete implementations here. */
    }

    /**
     * Subscribe accounting journal listeners to financial domain events.
     */
    protected function bootListeners(): void
    {
        Event::listen(OrderPaid::class, RecordOrderJournal::class);
        Event::listen(WithdrawalCompleted::class, RecordWithdrawalJournal::class);
    }
}
```

### 5.4 Registrasi di `bootstrap/providers.php`

Setiap modul yang sudah dibuat didaftarkan secara eksplisit di `bootstrap/providers.php`:

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\Filament\AppPanelProvider::class,

    /** Wave 1: Fundamental system modules. */
    Modules\Platform\Providers\PlatformServiceProvider::class,
    Modules\Core\Providers\CoreServiceProvider::class,
    Modules\Transaction\Providers\TransactionServiceProvider::class,
    Modules\Accounting\Providers\AccountingServiceProvider::class,

    /** Wave 2 & 3: Enable incrementally as each module is built. */
    // Modules\Pos\Providers\PosServiceProvider::class,
    // Modules\Web\Providers\WebServiceProvider::class,
    // Modules\Membership\Providers\MembershipServiceProvider::class,
    // Modules\Hris\Providers\HrisServiceProvider::class,
    // Modules\Iot\Providers\IotServiceProvider::class,
    // Modules\Kpi\Providers\KpiServiceProvider::class,
];
```

### 5.5 Pola Filament 5 Plugin per Modul (`modules/Accounting/Filament/AccountingPlugin.php`)

Setiap modul membungkus Resource, Page, dan Widget-nya ke dalam **Filament Plugin** agar `AppPanelProvider.php` tetap bersih:

```php
<?php

namespace Modules\Accounting\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Filament 5 plugin that registers all Accounting resources, pages, and widgets.
 */
class AccountingPlugin implements Plugin
{
    /**
     * Resolve a new instance of the Accounting Filament plugin.
     */
    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * Return the unique plugin identifier.
     */
    public function getId(): string
    {
        return 'accounting';
    }

    /**
     * Discover and register Accounting UI components on the given Filament panel.
     */
    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(
                in: base_path('modules/Accounting/Filament/Resources'),
                for: 'Modules\\Accounting\\Filament\\Resources'
            )
            ->discoverPages(
                in: base_path('modules/Accounting/Filament/Pages'),
                for: 'Modules\\Accounting\\Filament\\Pages'
            )
            ->discoverWidgets(
                in: base_path('modules/Accounting/Filament/Widgets'),
                for: 'Modules\\Accounting\\Filament\\Widgets'
            );
    }

    /**
     * Execute runtime boot hooks when the Filament panel is active.
     */
    public function boot(Panel $panel): void
    {
        /** Add panel-level runtime hooks here if required by the module. */
    }
}
```

Lalu daftarkan di `app/Providers/Filament/AppPanelProvider.php`:

```php
$panel
    ->id('app')
    ->path('app')
    ->tenant(Company::class, slugAttribute: 'slug')
    ->plugins([
        PlatformTenantPlugin::make(),
        CorePlugin::make(),
        TransactionPlugin::make(),
        AccountingPlugin::make(),
    ]);
```

### 5.6 Dua Trait Wajib di `app/Concerns/`

**1. Isolasi Tenant Otomatis (`BelongsToCompany.php`)**
Dipasang pada seluruh Model operasional agar query tidak pernah bocor lintas perusahaan:

```php
<?php

namespace App\Concerns;

use App\Models\Company;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enforces automatic multi-tenant scoping and assignment via company_id.
 */
trait BelongsToCompany
{
    /**
     * Register the tenant global scope and auto-populate company_id on creation.
     */
    public static function bootBelongsToCompany(): void
    {
        /** Automatically filter all queries to the active Filament tenant. */
        static::addGlobalScope('company', function (Builder $builder): void {
            if ($tenant = Filament::getTenant()) {
                $builder->where($builder->getModel()->getTable() . '.company_id', $tenant->getKey());
            }
        });

        /** Assign the active tenant ID when creating a new operational record. */
        static::creating(function (Model $model): void {
            if (empty($model->company_id) && ($tenant = Filament::getTenant())) {
                $model->company_id = $tenant->getKey();
            }
        });
    }

    /**
     * Define the owning company relationship for this tenant-scoped model.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
```

**2. Sakelar Akses Modul Filament (`RequiresModule.php`)**
Dipasang pada Resource/Page/Widget milik modul berbayar agar menu otomatis tersembunyi jika modul tidak aktif pada tenant:

```php
<?php

namespace App\Concerns;

use Filament\Facades\Filament;

/**
 * Restricts Filament resource or page visibility based on active tenant modules.
 */
trait RequiresModule
{
    /**
     * Return the module slug required to access this Filament component.
     */
    abstract public static function requiredModuleSlug(): string;

    /**
     * Determine whether the current tenant has an active subscription for this module.
     */
    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        /** Deny access immediately if no tenant is resolved or the module is inactive. */
        if (! $tenant || ! $tenant->hasActiveModule(static::requiredModuleSlug())) {
            return false;
        }

        return parent::canAccess();
    }
}
```

### 5.7 Konfigurasi `phpunit.xml` & Standar Unit Test Modul

Daftarkan seluruh folder test modul di `phpunit.xml` agar perintah `php artisan test` otomatis mengeksekusi semua test di seluruh modul:

```xml
<testsuites>
    <testsuite name="Unit">
        <directory>tests/Unit</directory>
        <directory>modules/*/Tests/Unit</directory>
    </testsuite>
    <testsuite name="Feature">
        <directory>tests/Feature</directory>
        <directory>modules/*/Tests/Feature</directory>
    </testsuite>
</testsuites>
```

**Contoh Standar Unit Test (`modules/Transaction/Tests/Unit/CalculateOrderTotalTest.php`):**

```php
<?php

namespace Modules\Transaction\Tests\Unit;

use Illuminate\Support\Collection;
use Modules\Transaction\Actions\CalculateOrderTotal;
use Modules\Transaction\Models\Order;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests verifying strict decimal calculation in CalculateOrderTotal.
 */
class CalculateOrderTotalTest extends TestCase
{
    /**
     * Ensure an order with no line items returns zero with four decimal places.
     */
    public function test_returns_zero_when_order_has_no_items(): void
    {
        /** Arrange an order instance with an empty items relation. */
        $order = new Order();
        $order->setRelation('items', new Collection());

        /** Act by executing the order total calculator. */
        $result = (new CalculateOrderTotal())->execute($order);

        /** Assert exact zero decimal output. */
        $this->assertSame('0.0000', $result);
    }

    /**
     * Ensure subtotal, discount, and tax are calculated accurately using bcmath precision.
     */
    public function test_calculates_grand_total_with_discount_and_tax(): void
    {
        /** Arrange an order with line items, discount, and tax amounts. */
        $order = new Order([
            'subtotal' => '150000.0000',
            'discount_total' => '15000.0000',
            'tax_total' => '14850.0000',
        ]);
        $order->setRelation('items', new Collection(['dummy_item']));

        /** Act by computing the grand total. */
        $result = (new CalculateOrderTotal())->execute($order);

        /** Assert exact expected grand total without floating-point drift. */
        $this->assertSame('149850.0000', $result);
    }
}
```

## 6. Cetak Biru Database Lengkap (60 Tabel Terintegrasi)

Seluruh tabel operasional di bawah ini wajib menyertakan `id`, `company_id` (indexed + FK ke `companies`), `created_at`, dan `updated_at`.

### Grup 1: Platform SaaS, Multi-Tenancy, Wallet/Withdraw & Master Global (16 Tabel) — *Gelombang 1*

1. **`companies`**: `name`, `slug` (unique), `custom_domain`, `business_type`, `is_active`.
2. **`company_settings`**: `company_id`, `currency`, `tax_rate`, `timezone`, `logo_path`, `payment_gateway_mode` (`platform_midtrans` default / `own_midtrans`), `midtrans_is_production` (boolean, default `false`), `midtrans_merchant_id`, `midtrans_server_key` *(encrypted)*, `midtrans_client_key`, `fonnte_token` *(encrypted)*, `invoice_prefix`.
3. **`users`**: standar Laravel + `phone`, `is_super_admin`.
4. **`company_user`**: `company_id`, `user_id`, `branch_id`, `role_id`, `is_owner`.
5. **`saas_modules`**: `slug` (unique: `pos`, `qr_order`, `accounting`, `hris`, `kpi`, `iot`, `membership`, `laundry`, `web`), `name`, `monthly_price`, `required_modules` (json).
6. **`saas_bundles`**: `slug`, `name`, `monthly_price`, `annual_price`, `module_ids` (json), `is_active`.
7. **`company_modules`**: `company_id`, `module_id`, `status` (`trial`/`active`/`expired`), `expires_at`, `limits` (json).
8. **`saas_subscriptions`**: `company_id`, `bundle_id` (nullable), `order_number` (unique, prefix `SUB-`), `module_ids` (json), `amount`, `status`, `midtrans_snap_token`, `paid_at`.
9. **`saas_payment_logs`**: `saas_subscription_id` (nullable), `trx_order_id` (nullable), `gateway_owner` (`platform`/`tenant`), `event`, `http_status`, `payload` (json).
10. **`saas_tenant_wallets`**: `company_id` (unique), `available_balance` (`DECIMAL(19,4)`), `pending_balance` (`DECIMAL(19,4)`), `total_earned` (`DECIMAL(19,4)`), `total_withdrawn` (`DECIMAL(19,4)`).
11. **`saas_wallet_transactions`**: `company_id`, `wallet_id`, `type` (`credit_sale`/`debit_withdraw`/`debit_fee`/`refund_reversal`/`adjustment`), `amount` (`DECIMAL(19,4)`), `balance_before` (`DECIMAL(19,4)`), `balance_after` (`DECIMAL(19,4)`), `reference_type`, `reference_id` *(polimorfik ke `trx_payments` / `saas_withdrawals`)*, `description`.
12. **`saas_tenant_bank_accounts`**: `company_id`, `bank_code`, `bank_name`, `account_number`, `account_holder_name`, `is_primary`, `is_verified`.
13. **`saas_withdrawals`**: `company_id`, `wallet_id`, `bank_account_id`, `withdrawal_number` (unique, prefix `WD-`), `amount` (`DECIMAL(19,4)`), `admin_fee` (`DECIMAL(19,4)`), `net_transfer_amount` (`DECIMAL(19,4)`), `bank_snapshot` (json), `status` (`pending`/`approved`/`processing`/`completed`/`rejected`), `requested_by`, `processed_by`, `processed_at`, `transfer_proof_path`, `rejection_reason`, `notes`.
14. **`sys_tax_ptkp`** *(Global)*: `code` (`TK/0`..`K/3`), `ter_category` (`A`/`B`/`C`), `annual_amount`, `effective_year`.
15. **`sys_tax_ter_brackets`** *(Global)*: `ter_category`, `min_gross`, `max_gross`, `rate_percent`, `effective_year`.
16. **`sys_tax_progressive_brackets`** *(Global)*: `min_pkp`, `max_pkp`, `rate_percent`, `effective_year`.

### Grup 2: Core Master Data, CRM & Inventori (10 Tabel) — *Gelombang 1*

17. **`core_branches`**: `company_id`, `code`, `name`, `address`, `phone`, `latitude`, `longitude`, `is_main`.
18. **`crm_contacts`**: `company_id`, `code`, `name`, `email`, `phone`, `address`, `is_customer`, `is_vendor`, `is_member`, `lead_stage`, `points_balance`.
19. **`crm_interactions`**: `company_id`, `contact_id`, `user_id`, `channel` (`wa`/`call`/`visit`/`email`), `notes`, `next_follow_up_at`.
20. **`core_categories`**: `company_id`, `parent_id`, `name`, `type` (`product`/`menu`/`service`/`membership`).
21. **`core_products`**: `company_id`, `category_id`, `name`, `slug`, `type` (`goods`/`menu`/`service`/`membership`), `track_stock`, `show_on_web`, `image_path`, `income_account_id`, `cogs_account_id`, `inventory_account_id`.
22. **`core_product_variants`**: `company_id`, `product_id`, `name`, `sku`, `barcode`, `unit` (`pcs`/`kg`/`m2`/`visit`/`paket`), `cost_price`, `selling_price`, `is_active`.
23. **`core_product_discounts`**: `company_id`, `product_variant_id`, `discount_type` (`percent`/`fixed`), `discount_value`, `starts_at`, `ends_at`, `is_active`.
24. **`core_vouchers`**: `company_id`, `code`, `discount_type`, `discount_value`, `min_order_amount`, `quota`, `used_count`, `starts_at`, `ends_at`.
25. **`inv_stocks`**: `company_id`, `branch_id`, `product_variant_id`, `quantity` (`DECIMAL(12,2)`), `min_stock_alert`.
26. **`inv_stock_movements`**: `company_id`, `branch_id`, `product_variant_id`, `type` (`in`/`out`/`adjustment`/`transfer`), `qty_change`, `unit_cost`, `reference_type`, `reference_id`.

### Grup 3: Transaksi Terpadu — POS, QR Order, Invoice & Laundry (8 Tabel) — *Gelombang 1 & 2*

27. **`pos_tables`**: `company_id`, `branch_id`, `table_number`, `qr_token` (unique), `capacity`, `status`.
28. **`pos_shifts`**: `company_id`, `branch_id`, `user_id`, `opened_at`, `closed_at`, `opening_cash`, `expected_cash`, `actual_cash`, `status`.
29. **`trx_orders`**: `company_id`, `branch_id`, `order_number` (unique, prefix `TRX-`), `channel` (`pos`/`qr_order`/`web`/`invoice`/`membership`/`laundry`), `contact_id`, `pos_shift_id`, `pos_table_id`, `salesperson_employee_id`, `order_status`, `payment_status` (`unpaid`/`partial`/`paid`/`refunded`), `subtotal`, `discount_total`, `tax_total`, `grand_total`, `due_date`, `estimated_ready_at`, `rack_location`, `picked_up_at`, `notes`.
30. **`trx_order_items`**: `company_id`, `order_id`, `product_variant_id`, `item_name_snapshot`, `qty` (`DECIMAL(10,2)`), `unit_snapshot`, `unit_price`, `cost_price_snapshot`, `discount_amount`, `subtotal`, `kitchen_status` (`queued`/`cooking`/`done`), `notes`.
31. **`trx_order_stages`**: `company_id`, `order_id`, `handled_by_employee_id`, `stage_name`, `started_at`, `completed_at`, `notes`.
32. **`trx_order_vouchers`**: `company_id`, `order_id`, `voucher_id`, `voucher_code_snapshot`, `discount_applied`.
33. **`trx_payments`**: `company_id`, `order_id`, `payment_method` (`cash`/`qris`/`va`/`transfer`/`quota`), `gateway_owner` (`offline`/`tenant`/`platform`), `account_id`, `amount` (`DECIMAL(19,4)`), `gateway_fee_amount` (`DECIMAL(19,4)`), `platform_fee_amount` (`DECIMAL(19,4)`), `net_amount` (`DECIMAL(19,4)`), `paid_at`, `gateway_reference`, `status`.
34. **`trx_order_logs`**: `company_id`, `order_id`, `event`, `payload` (json), `created_by`.

### Grup 4: Akuntansi Double-Entry (4 Tabel) — *Gelombang 1*

35. **`acc_accounts`**: `company_id`, `parent_id`, `code`, `name`, `type` (`asset`/`liability`/`equity`/`revenue`/`expense`), `normal_balance` (`debit`/`credit`), `is_system`. *(Wajib punya akun sistem: Kas, Bank, Piutang/Saldo Platform, Persediaan, Pendapatan, HPP, Beban Biaya Payment Gateway).*
36. **`acc_journals`**: `company_id`, `branch_id`, `journal_number`, `journal_date`, `description`, `reference_type`, `reference_id`, `status` (`draft`/`posted`/`void`), `created_by`.
37. **`acc_journal_items`**: `company_id`, `journal_id`, `account_id`, `debit` (`DECIMAL(19,4)`), `credit` (`DECIMAL(19,4)`), `memo`.
38. **`acc_expenses`**: `company_id`, `branch_id`, `contact_id`, `expense_account_id`, `payment_account_id`, `expense_number`, `amount`, `expense_date`, `due_date`, `receipt_path`, `status`.

### Grup 5: HRIS, Absensi & Payroll + PPh 21 Opsional (9 Tabel) — *Gelombang 3*

39. **`hr_payroll_settings`**: `company_id`, `enable_pph21` (default `false`), `default_tax_method` (`gross`/`gross_up`/`net`), `enable_bpjs_kesehatan`, `enable_bpjs_ketenagakerjaan`, `jkk_risk_rate`, `salary_expense_account_id`, `pph21_payable_account_id`, `bpjs_payable_account_id`.
40. **`hr_departments`**: `company_id`, `code`, `name`.
41. **`hr_positions`**: `company_id`, `code`, `name`.
42. **`hr_employees`**: `company_id`, `branch_id`, `user_id`, `department_id`, `position_id`, `employee_code`, `full_name`, `join_date`, `base_salary`, `bank_account`, `nik_npwp`, `ptkp_code`, `employment_tax_type`, `is_taxable`, `tax_method_override`, `has_bpjs_kesehatan`, `has_bpjs_ketenagakerjaan`, `status`.
43. **`hr_shifts`**: `company_id`, `name`, `start_time`, `end_time`, `late_tolerance_minutes`.
44. **`hr_attendances`**: `company_id`, `employee_id`, `shift_id`, `date`, `source` (`iot_device`/`mobile_gps`/`manual`), `clock_in`, `clock_out`, `clock_in_log_id`, `clock_out_log_id`, `lat_in`, `lng_in`, `photo_in`, `late_minutes`, `overtime_minutes`, `status`.
45. **`hr_leaves`**: `company_id`, `employee_id`, `type` (`annual`/`sick`/`unpaid`), `start_date`, `end_date`, `reason`, `status`, `approved_by`.
46. **`hr_payrolls`**: `company_id`, `period_year`, `period_month`, `total_amount`, `payment_account_id`, `status` (`draft`/`approved`/`paid`), `paid_at`.
47. **`hr_payroll_items`**: `company_id`, `payroll_id`, `employee_id`, `kpi_evaluation_id`, `base_salary`, `allowances`, `overtime`, `kpi_bonus`, `deductions`, `bpjs_kes_company`, `bpjs_kes_employee`, `bpjs_tk_company`, `bpjs_tk_employee`, `tax_enabled_snapshot`, `tax_method_snapshot`, `ptkp_snapshot`, `ter_category_snapshot`, `taxable_gross_income`, `ter_rate_snapshot`, `tax_allowance`, `pph21`, `is_final_period_recalc`, `net_salary`.

### Grup 6: IoT Hardware Absensi & Check-in ESP32 (3 Tabel) — *Gelombang 3*

48. **`iot_devices`**: `company_id`, `branch_id`, `device_uid` (unique), `name`, `purpose` (`attendance`/`member_checkin`), `auth_token_hash`, `mode` (`normal`/`enroll`), `firmware_version`, `last_heartbeat_at`, `status`.
49. **`hr_employee_credentials`**: `company_id`, `employee_id`, `iot_device_id` (nullable), `type` (`rfid_uid`/`fingerprint_slot`/`pin`), `credential_value`, `is_active`.
50. **`iot_attendance_logs`**: `company_id`, `branch_id`, `iot_device_id`, `employee_id` (nullable), `credential_value`, `punched_at`, `received_at`, `sync_source` (`realtime`/`offline_buffer`), `direction_hint` (`auto`/`in`/`out`), `processed_at`. *(Unique index: `iot_device_id`, `credential_value`, `punched_at`)*.

### Grup 7: KPI Karyawan (4 Tabel) — *Gelombang 3*

51. **`hr_kpi_indicators`**: `company_id`, `department_id`, `position_id`, `code`, `name`, `unit`, `polarity`, `data_source` (`manual`/`iot_attendance_ontime`/`trx_sales_revenue`/`crm_deals_won`/`order_stages_done`), `default_weight`, `is_active`.
52. **`hr_kpi_periods`**: `company_id`, `name`, `start_date`, `end_date`, `status`.
53. **`hr_kpi_evaluations`**: `company_id`, `kpi_period_id`, `employee_id`, `evaluator_employee_id`, `final_score`, `grade`, `bonus_amount`, `status`, `notes`.
54. **`hr_kpi_evaluation_items`**: `company_id`, `evaluation_id`, `kpi_indicator_id`, `weight`, `target_value`, `actual_value`, `achievement_percent`, `weighted_score`, `evidence_notes`.

### Grup 8: Membership — Gym & Deposit Laundry (3 Tabel) — *Gelombang 2*

55. **`mem_plans`**: `company_id`, `product_variant_id`, `duration_days`, `quota_amount` (`DECIMAL(10,2)`), `quota_unit` (`visit`/`kg`/`credit`), `access_all_branches`, `benefits` (json).
56. **`mem_subscriptions`**: `company_id`, `contact_id`, `mem_plan_id`, `order_id`, `member_qr_code` (unique), `starts_at`, `ends_at`, `remaining_quota` (`DECIMAL(10,2)`), `status`.
57. **`mem_checkins`**: `company_id`, `branch_id`, `mem_subscription_id`, `iot_device_id` (nullable), `quota_used` (`DECIMAL(10,2)`), `checked_in_at`, `checked_out_at`, `verified_by`.

### Grup 9: Web Perusahaan, Katalog & Tracking Publik (3 Tabel) — *Gelombang 2*

58. **`web_settings`**: `company_id`, `theme_slug`, `site_title`, `tagline`, `primary_color`, `hero_title`, `hero_subtitle`, `hero_image`, `about_content`, `whatsapp_number`, `instagram_url`, `show_product_catalog`, `enable_online_checkout`, `enable_order_tracking`.
59. **`web_pages`**: `company_id`, `title`, `slug`, `content`, `seo_title`, `seo_description`, `is_published`.
60. **`web_sections`**: `company_id`, `web_page_id`, `type` (`hero`/`features`/`gallery`/`testimonial`/`cta`), `sort_order`, `data` (json), `is_active`.

## 7. Peta Event Lintas Modul (Event-Driven Contract)

Setiap kali AI membangun fitur yang menyentuh lebih dari satu modul, ikuti jalur Event berikut:

| Event | Dipicu Oleh | Listener yang Mendengarkan | Aksi yang Dijalankan |
| :--- | :--- | :--- | :--- |
| `CompanyRegistered` | `Platform` | `Platform`, `Core`, `Accounting`, `Hris` | Buat baris `saas_tenant_wallets` (saldo 0), buat cabang utama (`is_main`), seed COA default sesuai `business_type`, buat baris `company_settings` (`payment_gateway_mode = 'platform_midtrans'`) & `hr_payroll_settings` (`enable_pph21 = false`). |
| `OrderPaid` | `Transaction` | `Platform`, `Core`, `Accounting`, `Pos`, `Membership` | Jika `gateway_owner = 'platform'`, kredit `saas_tenant_wallets` sebesar `net_amount` + catat `saas_wallet_transactions`; potong `inv_stocks` + catat `inv_stock_movements`; buat jurnal otomatis (jika `platform`, Debit **Saldo Platform** + **Beban Fee PG**; jika `tenant`/`offline`, Debit **Kas/Bank**); kirim pesanan ke KDS (`kitchen_status = queued`); aktifkan `mem_subscriptions` jika item bertipe membership. |
| `WithdrawalCompleted` | `Platform` | `Accounting` | Buat jurnal otomatis di pembukuan tenant: **Debit** Bank UMKM (+ Beban Biaya Transfer jika ada `admin_fee`), **Kredit** Saldo/Piutang Platform. |
| `ExpensePaid` | `Accounting` | `Accounting` | Buat jurnal otomatis Debit Beban & Kredit Kas/Bank. |
| `IotLogReceived` | `Iot` | `Hris`, `Membership` | Jika `purpose = attendance` → evaluasi shift & update `hr_attendances`. Jika `purpose = member_checkin` → validasi & catat `mem_checkins`. |
| `PayrollPaid` | `Hris` | `Accounting` | Buat jurnal Debit Beban Gaji/Bonus/BPJS & Kredit Kas/Bank + Utang PPh 21 (jika `enable_pph21 = true`). |

## 8. Checklist Eksekusi AI untuk Setiap Tugas Baru

Sebelum menulis dan menyelesaikan kode untuk fitur apa pun, AI WAJIB memverifikasi urutan ini:

1. **Identifikasi Modul:** Pastikan file ditempatkan di `modules/{ModuleName}/` yang sesuai (bukan menumpuk di `app/`).
2. **Clean Code & Zero-PHP in Blade:** Pastikan seluruh logika PHP berada di dalam PHP Class (Actions/Services/Livewire Class/View Components), tidak ada satu pun tag `@php` atau `<?php` di dalam file `.blade.php`, dan gunakan *strict typing* serta *early return*.
3. **Standar Komentar Bahasa Inggris:** Pastikan setiap class, function/method, dan blok logika penting memiliki komentar berbahasa Inggris dengan format `/** ... */` di dalam PHP Class dan `<!-- ... -->` di dalam file `.blade.php`.
4. **Migration & Indexing:** Gunakan tipe `DECIMAL` yang presisi, tambahkan *composite index* diawali `company_id`, dan pasang *foreign key* (`nullOnDelete()` untuk lintas modul opsional, `restrictOnDelete()` untuk integritas keuangan & dompet).
5. **Model:** Pasang `use BelongsToCompany;`, definisikan `$casts` (termasuk `encrypted` untuk API key & `decimal:4` untuk uang).
6. **Logika Bisnis & Locking:** Taruh di `Actions/` (bukan langsung menumpuk di Filament Resource atau Controller), bungkus operasi multi-tabel dengan `DB::transaction()`, dan gunakan `lockForUpdate()` untuk segala mutasi saldo dompet/withdraw.
7. **Verifikasi Webhook Midtrans:** WAJIB memverifikasi `signature_key` menggunakan *server key* yang sesuai (`config` untuk rute Platform, atau `company_settings.midtrans_server_key` untuk rute Tenant) dan mencatat *payload* mentah ke log sebelum memproses status lunas.
8. **Filament 5 Resource/Page:** Pasang `use RequiresModule;` untuk modul non-core, lalu daftarkan lewat `{Module}Plugin.php`.
9. **UI Anti-"AI Slop" & Komponen Blade Filament 5:** Pastikan tidak ada gradasi norak, efek *glow*, emoji dekoratif, atau markup kartu/tombol mentah yang meniru UI Filament secara manual; setiap *custom page* di panel Filament WAJIB memakai komponen Blade resmi Filament 5 (`<x-filament-panels::page>`, `<x-filament::section>`, `<x-filament::button>`, `<x-filament::badge>`, `<x-filament::fieldset>`, dll.) agar konsisten dengan *Light/Dark Mode*.
10. **Validasi Jurnal:** Setiap *Action* yang menulis ke `acc_journal_items` WAJIB memverifikasi `SUM(debit) === SUM(credit)` menggunakan `bcmath` sebelum *commit*.
11. **Wajib Unit Test & Verifikasi CLI:** Buat Unit Test (dan Feature Test bila relevan) untuk setiap kode/function baru di `modules/{ModuleName}/Tests/`, lalu jalankan `php artisan test` di PowerShell hingga 100% PASS tanpa error.
12. **Tanpa Browser & Patuh Regulasi User:** DILARANG membuka browser atau *browser preview* untuk mengecek UI maupun fitur. Serahkan seluruh pengecekan visual, pengujian manual, dan keputusan regulasi sepenuhnya kepada User.