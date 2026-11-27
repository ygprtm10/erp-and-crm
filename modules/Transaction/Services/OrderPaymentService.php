<?php

declare(strict_types=1);

namespace Modules\Transaction\Services;

use App\Models\Company;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Accounting\Models\Account;
use Modules\Platform\Enums\GatewayOwner;
use Modules\Platform\Services\PaymentGatewayResolver;
use Modules\Transaction\Enums\OrderStatus;
use Modules\Transaction\Enums\PaymentGatewayMode;
use Modules\Transaction\Enums\PaymentMethod;
use Modules\Transaction\Enums\PaymentStatus;
use Modules\Transaction\Enums\PaymentTransactionStatus;
use Modules\Transaction\Events\OrderPaid;
use Modules\Transaction\Exceptions\InvalidMidtransSignatureException;
use Modules\Transaction\Models\Order;
use Modules\Transaction\Models\OrderLog;
use Modules\Transaction\Models\Payment;

/**
 * Handles manual payments, partial payment accumulation, dual-mode Midtrans webhooks, and OrderPaid dispatch.
 */
class OrderPaymentService
{
    private const MONEY_SCALE = 4;

    /**
     * Inject the Platform PaymentGatewayResolver for dual-mode signature verification.
     */
    public function __construct(
        private readonly PaymentGatewayResolver $paymentGatewayResolver,
    ) {}

    /**
     * Record a manual cash, transfer, QRIS, or quota payment and transition order status when fully settled.
     */
    public function recordManualPayment(
        Order $order,
        PaymentMethod $method,
        string $amount,
        ?Account $account = null,
        ?User $recordedBy = null,
        ?CarbonInterface $paidAt = null,
        ?string $gatewayReference = null,
        ?string $notes = null,
    ): Payment {
        $normalizedAmount = $this->normalizePositiveMoney($amount, 'amount');

        $dispatchOrderPaid = false;

        /** @var Payment $payment */
        $payment = DB::transaction(function () use (
            $order,
            $method,
            $normalizedAmount,
            $account,
            $recordedBy,
            $paidAt,
            $gatewayReference,
            $notes,
            &$dispatchOrderPaid,
        ): Payment {
            $lockedOrder = Order::query()
                ->withoutGlobalScope('company')
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder instanceof Order) {
                throw new DomainException('Order not found.');
            }

            if ($lockedOrder->order_status === OrderStatus::Cancelled) {
                throw new DomainException('Cannot record payment for a cancelled order.');
            }

            if (in_array($lockedOrder->payment_status, [PaymentStatus::Paid, PaymentStatus::Refunded], true)) {
                throw new DomainException('Order has already been settled or refunded.');
            }

            if ($account instanceof Account && (int) $account->company_id !== (int) $lockedOrder->company_id) {
                throw new InvalidArgumentException('Payment account does not belong to the order company.');
            }

            $existingPaid = $this->sumPaidPayments((int) $lockedOrder->getKey());
            $newTotalPaid = bcadd($existingPaid, $normalizedAmount, self::MONEY_SCALE);
            $grandTotal = bcadd((string) $lockedOrder->grand_total, '0', self::MONEY_SCALE);

            if (bccomp($newTotalPaid, $grandTotal, self::MONEY_SCALE) > 0) {
                throw new DomainException('Payment amount exceeds the remaining unpaid order balance.');
            }

            $payment = Payment::query()
                ->withoutGlobalScope('company')
                ->create([
                    'company_id' => $lockedOrder->company_id,
                    'order_id' => $lockedOrder->getKey(),
                    'account_id' => $account?->getKey(),
                    'payment_method' => $method,
                    'gateway_mode' => PaymentGatewayMode::Manual,
                    'amount' => $normalizedAmount,
                    'gateway_fee_amount' => '0.0000',
                    'platform_fee_amount' => '0.0000',
                    'net_amount' => $normalizedAmount,
                    'paid_at' => $paidAt ?? now(),
                    'gateway_reference' => $gatewayReference,
                    'status' => PaymentTransactionStatus::Paid,
                ]);

            $isFullyPaid = bccomp($newTotalPaid, $grandTotal, self::MONEY_SCALE) >= 0;

            $lockedOrder->update([
                'payment_status' => $isFullyPaid ? PaymentStatus::Paid : PaymentStatus::Partial,
            ]);

            OrderLog::query()
                ->withoutGlobalScope('company')
                ->create([
                    'company_id' => $lockedOrder->company_id,
                    'order_id' => $lockedOrder->getKey(),
                    'event' => 'manual_payment_recorded',
                    'payload' => [
                        'payment_id' => $payment->getKey(),
                        'payment_method' => $method->value,
                        'amount' => $normalizedAmount,
                        'total_paid' => $newTotalPaid,
                        'payment_status' => $isFullyPaid ? PaymentStatus::Paid->value : PaymentStatus::Partial->value,
                        'notes' => $notes,
                    ],
                    'created_by' => $recordedBy?->getKey(),
                ]);

            $dispatchOrderPaid = $isFullyPaid;

            return $payment;
        });

        if ($dispatchOrderPaid) {
            $freshOrder = $order->fresh(['items.variant.product', 'payments', 'branch', 'company']);

            if ($freshOrder instanceof Order) {
                OrderPaid::dispatch($freshOrder, $payment);
            }
        }

        return $payment;
    }

    /**
     * Verify SHA-512 signature for Platform or Tenant Midtrans webhooks, log raw payloads, and settle idempotently.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleMidtransWebhook(array $payload, ?Company $scopedTenantCompany = null): Payment
    {
        $orderNumber = trim((string) ($payload['order_id'] ?? ''));
        $statusCode = (string) ($payload['status_code'] ?? '');
        $rawGrossAmount = (string) ($payload['gross_amount'] ?? '');
        $signatureKey = (string) ($payload['signature_key'] ?? '');
        $transactionStatus = strtolower(trim((string) ($payload['transaction_status'] ?? '')));

        if ($orderNumber === '' || $statusCode === '' || $rawGrossAmount === '' || $signatureKey === '') {
            throw new InvalidArgumentException('Incomplete Midtrans webhook payload.');
        }

        $orderQuery = Order::query()
            ->withoutGlobalScope('company')
            ->where('order_number', $orderNumber);

        if ($scopedTenantCompany instanceof Company) {
            $orderQuery->where('company_id', $scopedTenantCompany->getKey());
        }

        $order = $orderQuery->first();

        if (! $order instanceof Order) {
            throw new DomainException("Order [{$orderNumber}] not found for Midtrans webhook.");
        }

        $company = Company::query()->findOrFail($order->company_id);
        $credentials = $this->paymentGatewayResolver->resolveForCompany($company);

        if ($scopedTenantCompany instanceof Company && $credentials->gatewayOwner !== GatewayOwner::Tenant) {
            throw new InvalidMidtransSignatureException('Company is not configured for tenant-owned Midtrans webhooks.');
        }

        if (! $scopedTenantCompany instanceof Company && $credentials->gatewayOwner !== GatewayOwner::Platform) {
            throw new InvalidMidtransSignatureException('Order is not configured for Platform Midtrans webhooks.');
        }

        $expectedSignature = hash('sha512', $orderNumber.$statusCode.$rawGrossAmount.$credentials->serverKey);

        if (! hash_equals($expectedSignature, $signatureKey)) {
            throw new InvalidMidtransSignatureException('Invalid Midtrans webhook signature.');
        }

        $gatewayReference = trim((string) ($payload['transaction_id'] ?? $orderNumber));
        $normalizedGrossAmount = $this->normalizePositiveMoney($rawGrossAmount, 'gross_amount');
        $gatewayFeeAmount = $this->normalizeNonNegativeMoney((string) ($payload['gateway_fee_amount'] ?? '0.0000'), 'gateway_fee_amount');
        $platformFeeAmount = $this->normalizeNonNegativeMoney((string) ($payload['platform_fee_amount'] ?? '0.0000'), 'platform_fee_amount');
        $totalFees = bcadd($gatewayFeeAmount, $platformFeeAmount, self::MONEY_SCALE);

        if (bccomp($totalFees, $normalizedGrossAmount, self::MONEY_SCALE) > 0) {
            throw new InvalidArgumentException('Total Midtrans fees cannot exceed the gross payment amount.');
        }

        $netAmount = bcsub($normalizedGrossAmount, $totalFees, self::MONEY_SCALE);
        $gatewayMode = $credentials->gatewayOwner === GatewayOwner::Platform
            ? PaymentGatewayMode::PlatformMidtrans
            : PaymentGatewayMode::OwnMidtrans;
        $paymentMethod = $this->mapMidtransPaymentType((string) ($payload['payment_type'] ?? ''));
        $targetTransactionStatus = $this->mapMidtransTransactionStatus(
            $transactionStatus,
            isset($payload['fraud_status']) ? (string) $payload['fraud_status'] : null,
        );

        $dispatchOrderPaid = false;

        /** @var Payment $payment */
        $payment = DB::transaction(function () use (
            $order,
            $payload,
            $gatewayReference,
            $gatewayMode,
            $paymentMethod,
            $normalizedGrossAmount,
            $gatewayFeeAmount,
            $platformFeeAmount,
            $netAmount,
            $targetTransactionStatus,
            &$dispatchOrderPaid,
        ): Payment {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->withoutGlobalScope('company')
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /** Always record immutable raw webhook payload in trx_order_logs before processing. */
            OrderLog::query()
                ->withoutGlobalScope('company')
                ->create([
                    'company_id' => $lockedOrder->company_id,
                    'order_id' => $lockedOrder->getKey(),
                    'event' => 'midtrans_webhook_received',
                    'payload' => $payload,
                    'created_by' => null,
                ]);

            $existingPayment = Payment::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $lockedOrder->company_id)
                ->where('order_id', $lockedOrder->getKey())
                ->where('gateway_reference', $gatewayReference)
                ->lockForUpdate()
                ->first();

            /** Enforce idempotency: ignore duplicate settlement webhooks once gateway_reference is paid. */
            if ($existingPayment instanceof Payment && $existingPayment->status === PaymentTransactionStatus::Paid) {
                return $existingPayment;
            }

            $wasOrderPaid = $lockedOrder->payment_status === PaymentStatus::Paid;

            $attributes = [
                'payment_method' => $paymentMethod,
                'gateway_mode' => $gatewayMode,
                'amount' => $normalizedGrossAmount,
                'gateway_fee_amount' => $gatewayFeeAmount,
                'platform_fee_amount' => $platformFeeAmount,
                'net_amount' => $netAmount,
                'paid_at' => $targetTransactionStatus === PaymentTransactionStatus::Paid ? now() : null,
                'status' => $targetTransactionStatus,
            ];

            if ($existingPayment instanceof Payment) {
                $existingPayment->update($attributes);
                $payment = $existingPayment->refresh();
            } else {
                $payment = Payment::query()
                    ->withoutGlobalScope('company')
                    ->create(array_merge($attributes, [
                        'company_id' => $lockedOrder->company_id,
                        'order_id' => $lockedOrder->getKey(),
                        'account_id' => null,
                        'gateway_reference' => $gatewayReference,
                    ]));
            }

            if ($targetTransactionStatus === PaymentTransactionStatus::Paid) {
                $totalPaid = $this->sumPaidPayments((int) $lockedOrder->getKey());
                $grandTotal = bcadd((string) $lockedOrder->grand_total, '0', self::MONEY_SCALE);
                $isFullyPaid = bccomp($totalPaid, $grandTotal, self::MONEY_SCALE) >= 0;

                $lockedOrder->update([
                    'payment_status' => $isFullyPaid ? PaymentStatus::Paid : PaymentStatus::Partial,
                ]);

                $dispatchOrderPaid = ! $wasOrderPaid && $isFullyPaid;
            }

            return $payment;
        });

        if ($dispatchOrderPaid) {
            $freshOrder = $order->fresh(['items.variant.product', 'payments', 'branch', 'company']);

            if ($freshOrder instanceof Order) {
                OrderPaid::dispatch($freshOrder, $payment);
            }
        }

        return $payment;
    }

    /**
     * Sum all paid payment amounts for the given order with strict bcmath scale 4 precision.
     */
    private function sumPaidPayments(int $orderId): string
    {
        $amounts = Payment::query()
            ->withoutGlobalScope('company')
            ->where('order_id', $orderId)
            ->where('status', PaymentTransactionStatus::Paid->value)
            ->pluck('amount');

        $total = '0.0000';
        foreach ($amounts as $amount) {
            $total = bcadd($total, bcadd((string) $amount, '0', self::MONEY_SCALE), self::MONEY_SCALE);
        }

        return $total;
    }

    /**
     * Map Midtrans payment_type string to internal PaymentMethod enum.
     */
    private function mapMidtransPaymentType(string $paymentType): PaymentMethod
    {
        return match (strtolower(trim($paymentType))) {
            'qris', 'gopay', 'shopeepay' => PaymentMethod::Qris,
            'bank_transfer', 'echannel', 'permata', 'bca_va', 'bni_va', 'bri_va', 'cimb_va' => PaymentMethod::Va,
            default => PaymentMethod::Transfer,
        };
    }

    /**
     * Map Midtrans transaction_status and fraud_status to PaymentTransactionStatus enum.
     */
    private function mapMidtransTransactionStatus(string $transactionStatus, ?string $fraudStatus): PaymentTransactionStatus
    {
        if ($transactionStatus === 'settlement') {
            return PaymentTransactionStatus::Paid;
        }

        if ($transactionStatus === 'capture') {
            $normalizedFraud = strtolower(trim((string) ($fraudStatus ?? 'accept')));

            return $normalizedFraud === 'accept'
                ? PaymentTransactionStatus::Paid
                : PaymentTransactionStatus::Pending;
        }

        if (in_array($transactionStatus, ['deny', 'cancel', 'expire', 'failure'], true)) {
            return PaymentTransactionStatus::Failed;
        }

        if (in_array($transactionStatus, ['refund', 'partial_refund'], true)) {
            return PaymentTransactionStatus::Refunded;
        }

        return PaymentTransactionStatus::Pending;
    }

    /**
     * Validate numeric decimal syntax and ensure the monetary amount is strictly positive.
     */
    private function normalizePositiveMoney(string $value, string $field): string
    {
        $normalized = $this->normalizeNonNegativeMoney($value, $field);

        if (bccomp($normalized, '0.0000', self::MONEY_SCALE) <= 0) {
            throw new InvalidArgumentException("The {$field} must be greater than zero.");
        }

        return $normalized;
    }

    /**
     * Validate numeric decimal syntax and ensure the monetary amount is non-negative.
     */
    private function normalizeNonNegativeMoney(string $value, string $field): string
    {
        $trimmed = trim($value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw new InvalidArgumentException("Invalid decimal format for {$field}.");
        }

        $normalized = bcadd($trimmed, '0', self::MONEY_SCALE);

        if (bccomp($normalized, '0.0000', self::MONEY_SCALE) < 0) {
            throw new InvalidArgumentException("The {$field} cannot be negative.");
        }

        return $normalized;
    }
}
