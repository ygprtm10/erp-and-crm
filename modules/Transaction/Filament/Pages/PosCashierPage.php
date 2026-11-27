<?php

declare(strict_types=1);

namespace Modules\Transaction\Filament\Pages;

use App\Concerns\RequiresModule;
use App\Models\Company;
use App\Models\User;
use BackedEnum;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Contact;
use Modules\Core\Models\InventoryStock;
use Modules\Core\Models\ProductVariant;
use Modules\Core\Services\PricingCalculatorService;
use Modules\Platform\Models\CompanySetting;
use Modules\Transaction\Enums\OrderChannel;
use Modules\Transaction\Enums\OrderStatus;
use Modules\Transaction\Enums\PaymentMethod;
use Modules\Transaction\Enums\PosShiftStatus;
use Modules\Transaction\Filament\Resources\PosShiftResource;
use Modules\Transaction\Models\PosShift;
use Modules\Transaction\Services\OrderCreationService;
use Modules\Transaction\Services\OrderPaymentService;
use UnitEnum;

/**
 * Interactive 2-column POS cashier page gated by the pos module subscription.
 */
class PosCashierPage extends Page
{
    use RequiresModule;

    private const MONEY_SCALE = 4;

    private const QUANTITY_SCALE = 2;

    /**
     * Product search keyword bound to the catalog filter input.
     */
    public string $search = '';

    /**
     * Cart items keyed by product_variant_id.
     *
     * @var array<int, array{variant_id: int, qty: string}>
     */
    public array $cart = [];

    /**
     * Optional selected customer contact ID.
     */
    public ?int $contactId = null;

    /**
     * Voucher input field value before validation.
     */
    public string $voucherCode = '';

    /**
     * Validated active voucher code applied to the current cart.
     */
    public ?string $appliedVoucherCode = null;

    /**
     * Feedback message displayed below the voucher input.
     */
    public string $voucherFeedback = '';

    /**
     * Indicates whether the current voucher feedback represents a valid voucher.
     */
    public bool $voucherFeedbackValid = false;

    /**
     * Selected payment method slug (cash, qris, or transfer).
     */
    public string $paymentMethod = PaymentMethod::Cash->value;

    /**
     * Optional Accounting asset account ID for payment settlement.
     */
    public ?int $paymentAccountId = null;

    /**
     * Optional cashier order notes.
     */
    public string $orderNotes = '';

    /**
     * The Blade view rendered by this Filament page.
     */
    protected string $view = 'transaction::filament.pages.pos-cashier';

    /**
     * Expand the cashier workspace to full desktop width for optimal two-column ergonomics.
     */
    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * The sidebar navigation icon for this page.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    /**
     * The navigation label shown in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Kasir POS';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Transaksi & POS';

    /**
     * The page title displayed in the header.
     */
    protected static ?string $title = 'Kasir POS';

    /**
     * Return the required module slug controlling access to this page.
     */
    public static function requiredModuleSlug(): string
    {
        return 'pos';
    }

    /**
     * Initialize default accounting cash account selection if accounting is active.
     */
    public function mount(): void
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Company && $tenant->hasActiveModule('accounting')) {
            $cashAccountId = Account::query()
                ->where('code', '1101')
                ->value('id');

            $this->paymentAccountId = $cashAccountId !== null ? (int) $cashAccountId : null;
        }
    }

    /**
     * Resolve the currently authenticated user's open cashier shift for this tenant.
     */
    #[Computed]
    public function activeShift(): ?PosShift
    {
        $tenant = Filament::getTenant();
        /** @var User|null $user */
        $user = Auth::user();

        if (! $tenant instanceof Company || ! $user instanceof User) {
            return null;
        }

        return PosShift::query()
            ->with('branch')
            ->where('company_id', $tenant->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', PosShiftStatus::Open->value)
            ->latest('opened_at')
            ->first();
    }

    /**
     * Return the URL to the Shift Kasir management page for opening a new shift.
     */
    #[Computed]
    public function shiftsPageUrl(): string
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            ? PosShiftResource::getUrl('index', ['tenant' => $tenant])
            : '#';
    }

    /**
     * Determine whether the active tenant has the accounting module enabled.
     */
    #[Computed]
    public function hasAccountingModule(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company && $tenant->hasActiveModule('accounting');
    }

    /**
     * Return selectable customer contacts for the active tenant.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function customerOptions(): array
    {
        return Contact::query()
            ->where('is_customer', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Return selectable asset accounts when the accounting module is active.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function paymentAccountOptions(): array
    {
        if (! $this->hasAccountingModule) {
            return [];
        }

        return Account::query()
            ->where('type', AccountType::Asset->value)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(static fn (Account $account): array => [
                $account->getKey() => "{$account->code} - {$account->name}",
            ])
            ->all();
    }

    /**
     * Return selectable payment methods supported on the POS screen.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function paymentMethodOptions(): array
    {
        return [
            PaymentMethod::Cash->value => 'Tunai (Cash)',
            PaymentMethod::Qris->value => 'QRIS',
            PaymentMethod::Transfer->value => 'Transfer Bank',
        ];
    }

    /**
     * Return searchable active product variants with effective pricing and branch stock metadata.
     *
     * @return list<array{
     *     id: int,
     *     name: string,
     *     sku: string,
     *     unit: string,
     *     formatted_price: string,
     *     formatted_base_price: string,
     *     has_discount: bool,
     *     stock_label: string,
     *     stock_color: string,
     *     can_add: bool
     * }>
     */
    #[Computed]
    public function catalogItems(): array
    {
        $shift = $this->activeShift;

        if (! $shift instanceof PosShift) {
            return [];
        }

        $searchTerm = trim($this->search);

        $variants = ProductVariant::query()
            ->with('product')
            ->where('is_active', true)
            ->when($searchTerm !== '', function (Builder $query) use ($searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $query->where(function (Builder $sub) use ($like): void {
                    $sub->where('name', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhere('barcode', 'like', $like)
                        ->orWhereHas('product', static function (Builder $productQuery) use ($like): void {
                            $productQuery->where('name', 'like', $like);
                        });
                });
            })
            ->orderBy('id')
            ->limit(36)
            ->get();

        $stockByVariant = InventoryStock::query()
            ->where('branch_id', $shift->branch_id)
            ->whereIn('product_variant_id', $variants->pluck('id')->all())
            ->pluck('quantity', 'product_variant_id');

        $pricingService = app(PricingCalculatorService::class);
        $items = [];

        foreach ($variants as $variant) {
            if ($variant->product === null) {
                continue;
            }

            $pricing = $pricingService->calculateVariantPrice($variant);
            $hasDiscount = bccomp($pricing->discountAmount, '0.0000', self::MONEY_SCALE) > 0;
            $trackStock = (bool) $variant->product->track_stock;
            $rawQty = (string) ($stockByVariant[$variant->getKey()] ?? '0.00');
            $hasStock = ! $trackStock || bccomp($rawQty, '0.00', self::QUANTITY_SCALE) > 0;

            $productName = trim((string) $variant->product->name);
            $variantName = trim((string) $variant->name);

            $items[] = [
                'id' => (int) $variant->getKey(),
                'name' => $variantName !== '' ? "{$productName} - {$variantName}" : $productName,
                'sku' => (string) $variant->sku,
                'unit' => $variant->unit->value,
                'formatted_price' => $this->formatRupiah($pricing->finalPrice),
                'formatted_base_price' => $this->formatRupiah($pricing->basePrice),
                'has_discount' => $hasDiscount,
                'stock_label' => $trackStock ? "Stok: {$rawQty} {$variant->unit->value}" : 'Jasa / Non-Stok',
                'stock_color' => $trackStock ? ($hasStock ? 'success' : 'danger') : 'gray',
                'can_add' => $hasStock,
            ];
        }

        return $items;
    }

    /**
     * Return pre-computed cart line items, voucher impact, tax, and grand total using bcmath.
     *
     * @return array{
     *     items: list<array{
     *         variant_id: int,
     *         name: string,
     *         sku: string,
     *         unit: string,
     *         qty: string,
     *         formatted_unit_price: string,
     *         formatted_subtotal: string
     *     }>,
     *     has_items: bool,
     *     formatted_subtotal: string,
     *     formatted_discount_total: string,
     *     formatted_tax_total: string,
     *     formatted_grand_total: string,
     *     tax_rate_label: string
     * }
     */
    #[Computed]
    public function cartSummary(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company || $this->cart === []) {
            return [
                'items' => [],
                'has_items' => false,
                'formatted_subtotal' => $this->formatRupiah('0.0000'),
                'formatted_discount_total' => $this->formatRupiah('0.0000'),
                'formatted_tax_total' => $this->formatRupiah('0.0000'),
                'formatted_grand_total' => $this->formatRupiah('0.0000'),
                'tax_rate_label' => '0.00%',
            ];
        }

        $pricingService = app(PricingCalculatorService::class);
        $variantIds = array_keys($this->cart);
        $variants = ProductVariant::query()
            ->with('product')
            ->whereIn('id', $variantIds)
            ->get()
            ->keyBy('id');

        $lines = [];
        $grossSubtotal = '0.0000';
        $itemDiscountTotal = '0.0000';
        $netItemsSubtotal = '0.0000';

        foreach ($this->cart as $variantId => $entry) {
            /** @var ProductVariant|null $variant */
            $variant = $variants->get($variantId);

            if (! $variant instanceof ProductVariant || $variant->product === null) {
                continue;
            }

            $qty = $this->normalizePositiveQty((string) ($entry['qty'] ?? '1.00')) ?? '1.00';
            $pricing = $pricingService->calculateVariantPrice($variant);

            $lineGross = bcmul($qty, $pricing->basePrice, self::MONEY_SCALE);
            $lineDiscount = bcmul($qty, $pricing->discountAmount, self::MONEY_SCALE);
            $lineSubtotal = bcsub($lineGross, $lineDiscount, self::MONEY_SCALE);

            $grossSubtotal = bcadd($grossSubtotal, $lineGross, self::MONEY_SCALE);
            $itemDiscountTotal = bcadd($itemDiscountTotal, $lineDiscount, self::MONEY_SCALE);
            $netItemsSubtotal = bcadd($netItemsSubtotal, $lineSubtotal, self::MONEY_SCALE);

            $productName = trim((string) $variant->product->name);
            $variantName = trim((string) $variant->name);

            $lines[] = [
                'variant_id' => (int) $variant->getKey(),
                'name' => $variantName !== '' ? "{$productName} - {$variantName}" : $productName,
                'sku' => (string) $variant->sku,
                'unit' => $variant->unit->value,
                'qty' => $qty,
                'formatted_unit_price' => $this->formatRupiah($pricing->finalPrice),
                'formatted_subtotal' => $this->formatRupiah($lineSubtotal),
            ];
        }

        $voucherDiscount = '0.0000';
        if ($this->appliedVoucherCode !== null && $this->appliedVoucherCode !== '') {
            try {
                $voucherResult = $pricingService->applyVoucher($tenant, $this->appliedVoucherCode, $netItemsSubtotal);
                $voucherDiscount = $voucherResult->discountAmount;
            } catch (DomainException|InvalidArgumentException) {
                $voucherDiscount = '0.0000';
            }
        }

        $discountTotal = bcadd($itemDiscountTotal, $voucherDiscount, self::MONEY_SCALE);
        $taxableBase = bcsub($grossSubtotal, $discountTotal, self::MONEY_SCALE);

        if (bccomp($taxableBase, '0.0000', self::MONEY_SCALE) < 0) {
            $taxableBase = '0.0000';
        }

        $taxRate = $this->resolveTaxRate($tenant);
        $taxTotal = bcdiv(bcmul($taxableBase, $taxRate, self::MONEY_SCALE), '100', self::MONEY_SCALE);
        $grandTotal = bcadd($taxableBase, $taxTotal, self::MONEY_SCALE);

        return [
            'items' => $lines,
            'has_items' => $lines !== [],
            'formatted_subtotal' => $this->formatRupiah($grossSubtotal),
            'formatted_discount_total' => $this->formatRupiah($discountTotal),
            'formatted_tax_total' => $this->formatRupiah($taxTotal),
            'formatted_grand_total' => $this->formatRupiah($grandTotal),
            'tax_rate_label' => $taxRate.'%',
        ];
    }

    /**
     * Add one unit of the selected variant to the active cart or increment its quantity.
     */
    public function addToCart(int $variantId): void
    {
        if (! $this->activeShift instanceof PosShift) {
            Notification::make()
                ->title('Silakan buka shift kasir terlebih dahulu.')
                ->warning()
                ->send();

            return;
        }

        $variant = ProductVariant::query()
            ->where('is_active', true)
            ->find($variantId);

        if (! $variant instanceof ProductVariant) {
            return;
        }

        $currentQty = (string) ($this->cart[$variantId]['qty'] ?? '0.00');
        $newQty = bcadd($currentQty, '1.00', self::QUANTITY_SCALE);

        $this->cart[$variantId] = [
            'variant_id' => $variantId,
            'qty' => $newQty,
        ];

        unset($this->cartSummary);
    }

    /**
     * Update a cart line quantity (supporting decimals such as 3.45) or remove it when zero/invalid.
     */
    public function updateCartQty(int $variantId, string $qty): void
    {
        if (! isset($this->cart[$variantId])) {
            return;
        }

        $normalized = $this->normalizePositiveQty($qty);

        if ($normalized === null) {
            unset($this->cart[$variantId]);
        } else {
            $this->cart[$variantId]['qty'] = $normalized;
        }

        unset($this->cartSummary);
    }

    /**
     * Remove a product variant line from the active cart.
     */
    public function removeFromCart(int $variantId): void
    {
        unset($this->cart[$variantId], $this->cartSummary);
    }

    /**
     * Validate and apply the entered voucher code against the current cart subtotal.
     */
    public function applyVoucher(): void
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company || $this->cart === []) {
            $this->voucherFeedbackValid = false;
            $this->voucherFeedback = 'Tambahkan item ke keranjang sebelum menggunakan voucher.';

            return;
        }

        $netItemsSubtotal = $this->computeNetItemsSubtotal();

        try {
            $result = app(PricingCalculatorService::class)->applyVoucher(
                company: $tenant,
                voucherCode: $this->voucherCode,
                orderSubtotal: $netItemsSubtotal,
            );

            $this->appliedVoucherCode = $result->voucher->code;
            $this->voucherCode = $result->voucher->code;
            $this->voucherFeedbackValid = true;
            $this->voucherFeedback = "Voucher {$result->voucher->code} aktif (-".$this->formatRupiah($result->discountAmount).').';
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->appliedVoucherCode = null;
            $this->voucherFeedbackValid = false;
            $this->voucherFeedback = $exception->getMessage();
        }

        unset($this->cartSummary);
    }

    /**
     * Clear any currently applied voucher from the cashier cart.
     */
    public function clearVoucher(): void
    {
        $this->voucherCode = '';
        $this->appliedVoucherCode = null;
        $this->voucherFeedback = '';
        $this->voucherFeedbackValid = false;

        unset($this->cartSummary);
    }

    /**
     * Create and immediately settle the POS order through OrderCreationService and OrderPaymentService.
     */
    public function checkout(): void
    {
        $tenant = Filament::getTenant();
        $shift = $this->activeShift;
        /** @var User|null $user */
        $user = Auth::user();

        if (! $tenant instanceof Company || ! $shift instanceof PosShift || ! $user instanceof User) {
            Notification::make()
                ->title('Shift kasir aktif diperlukan untuk menyelesaikan transaksi.')
                ->danger()
                ->send();

            return;
        }

        if ($this->cart === []) {
            Notification::make()
                ->title('Keranjang masih kosong.')
                ->warning()
                ->send();

            return;
        }

        $itemsPayload = array_values(array_map(static fn (array $entry): array => [
            'product_variant_id' => (int) $entry['variant_id'],
            'qty' => (string) $entry['qty'],
        ], $this->cart));

        $contact = $this->contactId !== null
            ? Contact::query()->find($this->contactId)
            : null;

        $paymentAccount = $this->hasAccountingModule && $this->paymentAccountId !== null
            ? Account::query()->where('type', AccountType::Asset->value)->find($this->paymentAccountId)
            : null;

        try {
            $order = app(OrderCreationService::class)->createOrder(
                company: $tenant,
                branch: $shift->branch,
                channel: OrderChannel::Pos,
                items: $itemsPayload,
                contact: $contact,
                posShift: $shift,
                voucherCode: $this->appliedVoucherCode,
                createdBy: $user,
                notes: trim($this->orderNotes) !== '' ? trim($this->orderNotes) : null,
                initialStatus: OrderStatus::Completed,
            );

            app(OrderPaymentService::class)->recordManualPayment(
                order: $order,
                method: PaymentMethod::from($this->paymentMethod),
                amount: (string) $order->grand_total,
                account: $paymentAccount,
                recordedBy: $user,
                notes: 'Settled via POS Cashier',
            );

            $this->cart = [];
            $this->contactId = null;
            $this->orderNotes = '';
            $this->clearVoucher();
            unset($this->catalogItems, $this->cartSummary);

            Notification::make()
                ->title("Transaksi {$order->order_number} berhasil dibayar.")
                ->success()
                ->send();
        } catch (DomainException|InvalidArgumentException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Compute the sum of net item subtotals after variant discounts using bcmath.
     */
    private function computeNetItemsSubtotal(): string
    {
        $pricingService = app(PricingCalculatorService::class);
        $variants = ProductVariant::query()
            ->whereIn('id', array_keys($this->cart))
            ->get()
            ->keyBy('id');

        $netTotal = '0.0000';

        foreach ($this->cart as $variantId => $entry) {
            $variant = $variants->get($variantId);

            if (! $variant instanceof ProductVariant) {
                continue;
            }

            $qty = $this->normalizePositiveQty((string) ($entry['qty'] ?? '1.00')) ?? '1.00';
            $pricing = $pricingService->calculateVariantPrice($variant);
            $lineSubtotal = bcmul($qty, $pricing->finalPrice, self::MONEY_SCALE);
            $netTotal = bcadd($netTotal, $lineSubtotal, self::MONEY_SCALE);
        }

        return $netTotal;
    }

    /**
     * Resolve the configured company tax rate percentage string.
     */
    private function resolveTaxRate(Company $company): string
    {
        $settings = CompanySetting::query()
            ->where('company_id', $company->getKey())
            ->first();

        if (! $settings instanceof CompanySetting || $settings->tax_rate === null) {
            return '0.00';
        }

        return bcadd((string) $settings->tax_rate, '0', self::QUANTITY_SCALE);
    }

    /**
     * Validate and normalize a strictly positive decimal quantity string to 2 decimal places.
     */
    private function normalizePositiveQty(string $value): ?string
    {
        $trimmed = trim($value);

        if (! preg_match('/^\d+(\.\d+)?$/', $trimmed)) {
            return null;
        }

        $normalized = bcadd($trimmed, '0', self::QUANTITY_SCALE);

        return bccomp($normalized, '0.00', self::QUANTITY_SCALE) > 0 ? $normalized : null;
    }

    /**
     * Format a bcmath decimal string as an Indonesian Rupiah display string.
     */
    private function formatRupiah(string $amount): string
    {
        $isNegative = str_starts_with($amount, '-');
        $absolute = $isNegative ? substr($amount, 1) : $amount;
        $formatted = 'Rp '.number_format((float) $absolute, 2, ',', '.');

        return $isNegative ? '-'.$formatted : $formatted;
    }
}
