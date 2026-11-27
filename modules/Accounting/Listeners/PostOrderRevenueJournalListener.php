<?php

declare(strict_types=1);

namespace Modules\Accounting\Listeners;

use App\Models\Company;
use Modules\Accounting\Actions\SeedDefaultAccountsAction;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Models\Branch;
use Modules\Transaction\Enums\PaymentGatewayMode;
use Modules\Transaction\Enums\PaymentMethod;
use Modules\Transaction\Enums\PaymentTransactionStatus;
use Modules\Transaction\Events\OrderPaid;
use Modules\Transaction\Models\Order;
use Modules\Transaction\Models\Payment;

/**
 * Automatically posts balanced sales revenue and COGS double-entry journals when an order settles.
 */
class PostOrderRevenueJournalListener
{
    private const MONEY_SCALE = 4;

    /**
     * Inject Accounting services required for automated order journal posting.
     */
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly SeedDefaultAccountsAction $seedDefaultAccountsAction,
    ) {}

    /**
     * Post revenue, gateway fee, and COGS/inventory journal lines when Accounting module is active.
     */
    public function handle(OrderPaid $event): void
    {
        $order = $event->order->loadMissing([
            'company',
            'branch' => static fn ($query) => $query->withoutGlobalScope('company'),
            'items' => static fn ($query) => $query->withoutGlobalScope('company'),
            'items.variant' => static fn ($query) => $query->withoutGlobalScope('company'),
            'items.variant.product' => static fn ($query) => $query->withoutGlobalScope('company'),
            'payments' => static fn ($query) => $query->withoutGlobalScope('company'),
        ]);

        $company = $order->company;

        if (! $company instanceof Company || ! $company->hasActiveModule('accounting')) {
            return;
        }

        /** Enforce order-level idempotency so duplicate OrderPaid events never create duplicate journals. */
        $alreadyPosted = Journal::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->getKey())
            ->exists();

        if ($alreadyPosted) {
            return;
        }

        $systemAccounts = $this->resolveSystemAccounts($company);
        $debitBuckets = [];
        $creditBuckets = [];
        $totalPaymentsDebit = '0.0000';

        /** Accumulate Debit entries from settled payments and any gateway/platform fees. */
        foreach ($order->payments as $payment) {
            if ($payment->status !== PaymentTransactionStatus::Paid) {
                continue;
            }

            $netAmount = bcadd((string) $payment->net_amount, '0', self::MONEY_SCALE);
            $feeTotal = bcadd(
                bcadd((string) $payment->gateway_fee_amount, '0', self::MONEY_SCALE),
                bcadd((string) $payment->platform_fee_amount, '0', self::MONEY_SCALE),
                self::MONEY_SCALE,
            );

            $targetAccountId = $this->resolvePaymentDebitAccountId($company, $payment, $systemAccounts);

            if (bccomp($netAmount, '0.0000', self::MONEY_SCALE) > 0) {
                $this->addBucketAmount($debitBuckets, $targetAccountId, $netAmount);
                $totalPaymentsDebit = bcadd($totalPaymentsDebit, $netAmount, self::MONEY_SCALE);
            }

            if (bccomp($feeTotal, '0.0000', self::MONEY_SCALE) > 0) {
                $feeAccountId = (int) $systemAccounts['6102']->getKey();
                $this->addBucketAmount($debitBuckets, $feeAccountId, $feeTotal);
                $totalPaymentsDebit = bcadd($totalPaymentsDebit, $feeTotal, self::MONEY_SCALE);
            }
        }

        /** Accumulate Credit revenue entries matching exact settled payment total via bcmath. */
        if (bccomp($totalPaymentsDebit, '0.0000', self::MONEY_SCALE) > 0) {
            $revenueBuckets = $this->buildRevenueBuckets($company, $order, $systemAccounts, $totalPaymentsDebit);

            foreach ($revenueBuckets as $accountId => $amount) {
                $this->addBucketAmount($creditBuckets, $accountId, $amount);
            }
        }

        /** Accumulate Debit COGS and Credit Inventory lines for stock-tracked items with positive cost. */
        $defaultCogsId = (int) $systemAccounts['5101']->getKey();
        $defaultInventoryId = (int) $systemAccounts['1201']->getKey();

        foreach ($order->items as $item) {
            $product = $item->variant?->product;

            if ($product === null || ! $product->track_stock) {
                continue;
            }

            $cogsAmount = bcmul((string) $item->qty, (string) $item->cost_price_snapshot, self::MONEY_SCALE);

            if (bccomp($cogsAmount, '0.0000', self::MONEY_SCALE) <= 0) {
                continue;
            }

            $cogsAccountId = $this->resolveValidCompanyAccountId($company, $product->cogs_account_id, $defaultCogsId);
            $inventoryAccountId = $this->resolveValidCompanyAccountId($company, $product->inventory_account_id, $defaultInventoryId);

            $this->addBucketAmount($debitBuckets, $cogsAccountId, $cogsAmount);
            $this->addBucketAmount($creditBuckets, $inventoryAccountId, $cogsAmount);
        }

        $journalItems = [];

        foreach ($debitBuckets as $accountId => $amount) {
            if (bccomp($amount, '0.0000', self::MONEY_SCALE) > 0) {
                $journalItems[] = [
                    'account_id' => $accountId,
                    'debit' => $amount,
                    'credit' => '0.0000',
                    'memo' => 'Order '.$order->order_number,
                ];
            }
        }

        foreach ($creditBuckets as $accountId => $amount) {
            if (bccomp($amount, '0.0000', self::MONEY_SCALE) > 0) {
                $journalItems[] = [
                    'account_id' => $accountId,
                    'debit' => '0.0000',
                    'credit' => $amount,
                    'memo' => 'Order '.$order->order_number,
                ];
            }
        }

        if (count($journalItems) < 2) {
            return;
        }

        $branch = $order->branch instanceof Branch ? $order->branch : null;

        $this->journalPostingService->createAndPostJournal(
            company: $company,
            journalDate: now()->toDateString(),
            description: 'Sales revenue and COGS for order '.$order->order_number,
            items: $journalItems,
            branch: $branch,
            referenceType: Order::class,
            referenceId: (int) $order->getKey(),
        );
    }

    /**
     * Build revenue credit buckets aligned exactly to total settled payments despite vouchers or taxes.
     *
     * @param  array<string, Account>  $systemAccounts
     * @return array<int, string>
     */
    private function buildRevenueBuckets(
        Company $company,
        Order $order,
        array $systemAccounts,
        string $targetTotal,
    ): array {
        $defaultRevenueId = (int) $systemAccounts['4101']->getKey();
        $buckets = [];
        $sumItems = '0.0000';

        foreach ($order->items as $item) {
            $lineSubtotal = bcadd((string) $item->subtotal, '0', self::MONEY_SCALE);

            if (bccomp($lineSubtotal, '0.0000', self::MONEY_SCALE) <= 0) {
                continue;
            }

            $product = $item->variant?->product;
            $accountId = $this->resolveValidCompanyAccountId($company, $product?->income_account_id, $defaultRevenueId);
            $this->addBucketAmount($buckets, $accountId, $lineSubtotal);
            $sumItems = bcadd($sumItems, $lineSubtotal, self::MONEY_SCALE);
        }

        if ($buckets === []) {
            return [$defaultRevenueId => $targetTotal];
        }

        $delta = bcsub($targetTotal, $sumItems, self::MONEY_SCALE);

        if (bccomp($delta, '0.0000', self::MONEY_SCALE) === 0) {
            return $buckets;
        }

        /** Apply voucher/tax delta to the largest revenue bucket; fall back cleanly to 4101 if negative. */
        arsort($buckets);
        $primaryAccountId = (int) array_key_first($buckets);
        $adjustedPrimary = bcadd($buckets[$primaryAccountId], $delta, self::MONEY_SCALE);

        if (bccomp($adjustedPrimary, '0.0000', self::MONEY_SCALE) > 0) {
            $buckets[$primaryAccountId] = $adjustedPrimary;

            return $buckets;
        }

        return [$defaultRevenueId => $targetTotal];
    }

    /**
     * Resolve the asset debit account ID for a settled payment based on gateway mode and method.
     *
     * @param  array<string, Account>  $systemAccounts
     */
    private function resolvePaymentDebitAccountId(Company $company, Payment $payment, array $systemAccounts): int
    {
        if ($payment->gateway_mode === PaymentGatewayMode::PlatformMidtrans) {
            return (int) $systemAccounts['1103']->getKey();
        }

        $fallbackCode = $payment->payment_method === PaymentMethod::Cash ? '1101' : '1102';
        $fallbackId = (int) $systemAccounts[$fallbackCode]->getKey();

        return $this->resolveValidCompanyAccountId($company, $payment->account_id, $fallbackId);
    }

    /**
     * Verify an optional account ID belongs to the target company before using it, otherwise return fallback.
     */
    private function resolveValidCompanyAccountId(Company $company, ?int $candidateAccountId, int $fallbackId): int
    {
        if ($candidateAccountId === null) {
            return $fallbackId;
        }

        $belongsToCompany = Account::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->whereKey($candidateAccountId)
            ->exists();

        return $belongsToCompany ? $candidateAccountId : $fallbackId;
    }

    /**
     * Resolve required system COA accounts, provisioning defaults first if missing.
     *
     * @return array<string, Account>
     */
    private function resolveSystemAccounts(Company $company): array
    {
        $requiredCodes = ['1101', '1102', '1103', '1201', '4101', '5101', '6102'];

        $accounts = Account::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->whereIn('code', $requiredCodes)
            ->get()
            ->keyBy('code');

        if ($accounts->count() < count($requiredCodes)) {
            $this->seedDefaultAccountsAction->execute($company);

            $accounts = Account::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $company->getKey())
                ->whereIn('code', $requiredCodes)
                ->get()
                ->keyBy('code');
        }

        $resolved = [];
        foreach ($requiredCodes as $code) {
            $resolved[$code] = $accounts->get($code);
        }

        return $resolved;
    }

    /**
     * Add a monetary amount into an account-keyed accumulator using bcmath precision.
     *
     * @param  array<int, string>  $buckets
     */
    private function addBucketAmount(array &$buckets, int $accountId, string $amount): void
    {
        $existing = $buckets[$accountId] ?? '0.0000';
        $buckets[$accountId] = bcadd($existing, $amount, self::MONEY_SCALE);
    }
}
