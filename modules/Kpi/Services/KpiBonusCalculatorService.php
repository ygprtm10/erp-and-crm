<?php

declare(strict_types=1);

namespace Modules\Kpi\Services;

use InvalidArgumentException;
use Modules\Hris\Models\Employee;
use Modules\Kpi\Enums\KpiBonusType;
use Modules\Kpi\Models\KpiBonusTier;

/**
 * Matches an employee's final KPI score against tenant bonus tiers and computes payout amounts using bcmath.
 */
class KpiBonusCalculatorService
{
    private const MONEY_SCALE = 4;

    private const SCORE_SCALE = 2;

    private const DIVISION_SCALE = 8;

    /**
     * Determine the matching bonus grade and calculated monetary bonus amount for the given final score.
     *
     * @return array{grade: ?string, bonus_amount: string}
     */
    public function calculate(Employee $employee, string $finalScore): array
    {
        $trimmed = trim($finalScore);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw new InvalidArgumentException('Final score must be a valid decimal string.');
        }

        $normalizedScore = bcadd($trimmed, '0', self::SCORE_SCALE);

        $tiers = KpiBonusTier::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $employee->company_id)
            ->where('is_active', true)
            ->orderByDesc('min_score')
            ->get();

        $matchedTier = $tiers->first(function (KpiBonusTier $tier) use ($normalizedScore): bool {
            return bccomp($normalizedScore, (string) $tier->min_score, self::SCORE_SCALE) >= 0
                && bccomp($normalizedScore, (string) $tier->max_score, self::SCORE_SCALE) <= 0;
        });

        if (! $matchedTier instanceof KpiBonusTier) {
            return [
                'grade' => null,
                'bonus_amount' => '0.0000',
            ];
        }

        $bonusAmount = match ($matchedTier->bonus_type) {
            KpiBonusType::PercentageOfBaseSalary => bcmul(
                bcadd((string) $employee->base_salary, '0', self::MONEY_SCALE),
                bcdiv((string) $matchedTier->bonus_value, '100', self::DIVISION_SCALE),
                self::MONEY_SCALE,
            ),
            KpiBonusType::FixedAmount => bcadd((string) $matchedTier->bonus_value, '0', self::MONEY_SCALE),
        };

        return [
            'grade' => $matchedTier->grade,
            'bonus_amount' => $bonusAmount,
        ];
    }
}
