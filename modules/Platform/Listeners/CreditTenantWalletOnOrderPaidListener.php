<?php

declare(strict_types=1);

namespace Modules\Platform\Listeners;

use App\Models\Company;
use Modules\Platform\Enums\WalletTransactionType;
use Modules\Platform\Models\SaasWalletTransaction;
use Modules\Platform\Services\WalletLedgerService;
use Modules\Transaction\Enums\PaymentGatewayMode;
use Modules\Transaction\Enums\PaymentTransactionStatus;
use Modules\Transaction\Events\OrderPaid;
use Modules\Transaction\Models\Payment;

/**
 * Credits tenant Platform escrow wallets when customer orders settle via platform_midtrans.
 */
class CreditTenantWalletOnOrderPaidListener
{
    private const MONEY_SCALE = 4;

    /**
     * Inject WalletLedgerService for locked escrow wallet mutations.
     */
    public function __construct(
        private readonly WalletLedgerService $walletLedgerService,
    ) {}

    /**
     * Idempotently credit net_amount into saas_tenant_wallets for each settled platform_midtrans payment.
     */
    public function handle(OrderPaid $event): void
    {
        $order = $event->order->loadMissing([
            'company',
            'payments' => static fn ($query) => $query->withoutGlobalScope('company'),
        ]);

        $company = $order->company;

        if (! $company instanceof Company) {
            return;
        }

        foreach ($order->payments as $payment) {
            if (
                $payment->gateway_mode !== PaymentGatewayMode::PlatformMidtrans
                || $payment->status !== PaymentTransactionStatus::Paid
            ) {
                continue;
            }

            if (bccomp((string) $payment->net_amount, '0.0000', self::MONEY_SCALE) <= 0) {
                continue;
            }

            /** Skip if this payment was already credited to the escrow ledger. */
            $alreadyCredited = SaasWalletTransaction::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $company->getKey())
                ->where('type', WalletTransactionType::CreditSale->value)
                ->where('reference_type', Payment::class)
                ->where('reference_id', $payment->getKey())
                ->exists();

            if ($alreadyCredited) {
                continue;
            }

            $this->walletLedgerService->creditSale(
                company: $company,
                amount: (string) $payment->net_amount,
                referenceType: Payment::class,
                referenceId: (int) $payment->getKey(),
                description: 'Order settlement '.$order->order_number,
            );
        }
    }
}
