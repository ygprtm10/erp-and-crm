<?php

declare(strict_types=1);

namespace Modules\Accounting\Data;

/**
 * Immutable Profit & Loss report payload computed from posted revenue and expense entries using bcmath.
 */
readonly class ProfitAndLossReport
{
    /**
     * Construct a Profit & Loss report DTO.
     *
     * @param  list<array{account_id: int, code: string, name: string, amount: string}>  $revenues
     * @param  list<array{account_id: int, code: string, name: string, amount: string}>  $expenses
     */
    public function __construct(
        public string $startDate,
        public string $endDate,
        public array $revenues,
        public array $expenses,
        public string $totalRevenue,
        public string $totalExpense,
        public string $netProfit,
    ) {}
}
