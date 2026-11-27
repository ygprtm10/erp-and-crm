<?php

declare(strict_types=1);

namespace Modules\Accounting\Data;

use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\NormalBalance;

/**
 * Immutable Trial Balance report payload computed from posted journal entries using bcmath.
 */
readonly class TrialBalanceReport
{
    /**
     * Construct a Trial Balance report DTO.
     *
     * @param  list<array{
     *     account_id: int,
     *     code: string,
     *     name: string,
     *     type: AccountType,
     *     normal_balance: NormalBalance,
     *     total_debit: string,
     *     total_credit: string,
     *     ending_balance: string
     * }>  $rows
     */
    public function __construct(
        public string $startDate,
        public string $endDate,
        public array $rows,
        public string $totalDebit,
        public string $totalCredit,
        public bool $isBalanced,
    ) {}
}
