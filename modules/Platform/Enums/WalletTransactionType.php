<?php

declare(strict_types=1);

namespace Modules\Platform\Enums;

/**
 * Categorizes append-only ledger mutations on a tenant escrow wallet.
 */
enum WalletTransactionType: string
{
    case CreditSale = 'credit_sale';
    case DebitWithdraw = 'debit_withdraw';
    case DebitFee = 'debit_fee';
    case RefundReversal = 'refund_reversal';
    case Adjustment = 'adjustment';
}
