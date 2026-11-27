<?php

declare(strict_types=1);

namespace Modules\Hris\Services;

use DomainException;
use InvalidArgumentException;
use Modules\Hris\Data\TaxAndBpjsResult;
use Modules\Hris\Models\Employee;
use Modules\Platform\Models\CompanySetting;
use Modules\Platform\Models\SysTaxPtkp;
use Modules\Platform\Models\SysTaxTerBracket;

/**
 * Calculates Indonesian BPJS Kesehatan, BPJS Ketenagakerjaan, and PP 58/2023 TER PPh 21 using bcmath.
 */
class TaxAndBpjsCalculatorService
{
    private const MONEY_SCALE = 4;

    private const RATE_SCALE = 8;

    private const BPJS_KES_MAX_WAGE = '12000000.0000';

    private const BPJS_JP_MAX_WAGE = '10042300.0000';

    private const DEFAULT_JKK_RATE = '0.0024';

    /**
     * Compute monthly BPJS contributions and PPh 21 withholding honoring run-level and employee-level switches.
     */
    public function calculate(
        Employee $employee,
        ?CompanySetting $settings,
        bool $runApplyPph21,
        bool $runApplyBpjs,
        string $baseSalary,
        string $allowancesTotal = '0.0000',
        string $overtimePay = '0.0000',
        string $bonusTotal = '0.0000',
        ?int $periodYear = null,
    ): TaxAndBpjsResult {
        $base = $this->normalizeNonNegativeMoney($baseSalary, 'base_salary');
        $allowances = $this->normalizeNonNegativeMoney($allowancesTotal, 'allowances_total');
        $overtime = $this->normalizeNonNegativeMoney($overtimePay, 'overtime_pay');
        $bonus = $this->normalizeNonNegativeMoney($bonusTotal, 'bonus_total');
        $fixedWageBase = bcadd($base, $allowances, self::MONEY_SCALE);

        /** Calculate BPJS Kesehatan (1% employee, 4% company, capped at Rp 12,000,000). */
        $bpjsKesEmployee = '0.0000';
        $bpjsKesCompany = '0.0000';

        if ($runApplyBpjs && $employee->enable_bpjs_kesehatan) {
            $kesBase = $this->minDecimal($fixedWageBase, self::BPJS_KES_MAX_WAGE);
            $bpjsKesEmployee = bcmul($kesBase, '0.01', self::MONEY_SCALE);
            $bpjsKesCompany = bcmul($kesBase, '0.04', self::MONEY_SCALE);
        }

        /** Calculate BPJS Ketenagakerjaan (JHT, JP with cap, JKK from company settings, JKM). */
        $bpjsTkEmployee = '0.0000';
        $bpjsTkCompany = '0.0000';
        $jkkCompany = '0.0000';
        $jkmCompany = '0.0000';

        if ($runApplyBpjs && $employee->enable_bpjs_ketenagakerjaan) {
            $jpBase = $this->minDecimal($fixedWageBase, self::BPJS_JP_MAX_WAGE);
            $jkkRate = $this->resolveJkkRate($settings);

            $jhtEmployee = bcmul($fixedWageBase, '0.02', self::MONEY_SCALE);
            $jpEmployee = bcmul($jpBase, '0.01', self::MONEY_SCALE);
            $bpjsTkEmployee = bcadd($jhtEmployee, $jpEmployee, self::MONEY_SCALE);

            $jhtCompany = bcmul($fixedWageBase, '0.037', self::MONEY_SCALE);
            $jpCompany = bcmul($jpBase, '0.02', self::MONEY_SCALE);
            $jkkCompany = bcmul($fixedWageBase, $jkkRate, self::MONEY_SCALE);
            $jkmCompany = bcmul($fixedWageBase, '0.0030', self::MONEY_SCALE);

            $bpjsTkCompany = bcadd(
                bcadd($jhtCompany, $jpCompany, self::MONEY_SCALE),
                bcadd($jkkCompany, $jkmCompany, self::MONEY_SCALE),
                self::MONEY_SCALE,
            );
        }

        /** Include cash earnings plus taxable employer BPJS premiums (Kesehatan, JKK, JKM) per PMK 168/2023. */
        $cashEarnings = bcadd(
            $fixedWageBase,
            bcadd($overtime, $bonus, self::MONEY_SCALE),
            self::MONEY_SCALE,
        );
        $taxableBenefits = bcadd(
            $bpjsKesCompany,
            bcadd($jkkCompany, $jkmCompany, self::MONEY_SCALE),
            self::MONEY_SCALE,
        );
        $taxableGross = bcadd($cashEarnings, $taxableBenefits, self::MONEY_SCALE);

        /** Enforce two-layer switch: payroll run apply_pph21 AND employee enable_pph21 must both be true. */
        if (! $runApplyPph21 || ! $employee->enable_pph21) {
            return new TaxAndBpjsResult(
                bpjsKesehatanEmployee: $bpjsKesEmployee,
                bpjsKesehatanCompany: $bpjsKesCompany,
                bpjsTkEmployee: $bpjsTkEmployee,
                bpjsTkCompany: $bpjsTkCompany,
                jkkCompany: $jkkCompany,
                jkmCompany: $jkmCompany,
                taxableGross: $taxableGross,
                terCategory: $employee->ptkp_status->terCategory(),
                terRatePercent: '0.0000',
                pph21Amount: '0.0000',
            );
        }

        $targetYear = $periodYear ?? (int) now()->year;
        $terCategory = $this->resolveTerCategory($employee->ptkp_status->value, $targetYear);
        $terRatePercent = $this->resolveTerRatePercent($terCategory, $taxableGross, $targetYear);
        $rateMultiplier = bcdiv($terRatePercent, '100', self::RATE_SCALE);
        $pph21Amount = bcmul($taxableGross, $rateMultiplier, self::MONEY_SCALE);

        return new TaxAndBpjsResult(
            bpjsKesehatanEmployee: $bpjsKesEmployee,
            bpjsKesehatanCompany: $bpjsKesCompany,
            bpjsTkEmployee: $bpjsTkEmployee,
            bpjsTkCompany: $bpjsTkCompany,
            jkkCompany: $jkkCompany,
            jkmCompany: $jkmCompany,
            taxableGross: $taxableGross,
            terCategory: $terCategory,
            terRatePercent: $terRatePercent,
            pph21Amount: $pph21Amount,
        );
    }

    /**
     * Resolve the official TER category from global sys_tax_ptkp master records.
     */
    private function resolveTerCategory(string $ptkpCode, int $periodYear): string
    {
        $ptkp = SysTaxPtkp::query()
            ->where('code', $ptkpCode)
            ->where('effective_year', '<=', $periodYear)
            ->orderByDesc('effective_year')
            ->first();

        if (! $ptkp instanceof SysTaxPtkp) {
            $ptkp = SysTaxPtkp::query()
                ->where('code', $ptkpCode)
                ->orderByDesc('effective_year')
                ->first();
        }

        if (! $ptkp instanceof SysTaxPtkp) {
            throw new DomainException("PTKP master record not found for code '{$ptkpCode}'. Run TaxMasterSeeder first.");
        }

        return $ptkp->ter_category;
    }

    /**
     * Resolve the monthly TER percentage matching category and taxable gross income.
     */
    private function resolveTerRatePercent(string $terCategory, string $taxableGross, int $periodYear): string
    {
        $effectiveYear = SysTaxTerBracket::query()
            ->where('ter_category', $terCategory)
            ->where('effective_year', '<=', $periodYear)
            ->max('effective_year');

        if ($effectiveYear === null) {
            $effectiveYear = SysTaxTerBracket::query()
                ->where('ter_category', $terCategory)
                ->max('effective_year');
        }

        if ($effectiveYear === null) {
            throw new DomainException("TER brackets not found for category '{$terCategory}'. Run TaxMasterSeeder first.");
        }

        $brackets = SysTaxTerBracket::query()
            ->where('ter_category', $terCategory)
            ->where('effective_year', (int) $effectiveYear)
            ->orderBy('min_gross')
            ->get();

        foreach ($brackets as $bracket) {
            $minGross = bcadd((string) $bracket->min_gross, '0', self::MONEY_SCALE);
            $aboveMin = bccomp($taxableGross, $minGross, self::MONEY_SCALE) >= 0;
            $belowMax = $bracket->max_gross === null
                || bccomp($taxableGross, bcadd((string) $bracket->max_gross, '0', self::MONEY_SCALE), self::MONEY_SCALE) <= 0;

            if ($aboveMin && $belowMax) {
                return bcadd((string) $bracket->rate_percent, '0', self::MONEY_SCALE);
            }
        }

        throw new DomainException("No matching TER bracket found for category '{$terCategory}' and gross '{$taxableGross}'.");
    }

    /**
     * Resolve the company's configured JKK risk rate or fallback to the lowest risk tier (0.24%).
     */
    private function resolveJkkRate(?CompanySetting $settings): string
    {
        if (! $settings instanceof CompanySetting || $settings->bpjs_jkk_rate === null) {
            return self::DEFAULT_JKK_RATE;
        }

        return bcadd((string) $settings->bpjs_jkk_rate, '0', self::MONEY_SCALE);
    }

    /**
     * Return the smaller of two decimal strings at scale 4.
     */
    private function minDecimal(string $left, string $right): string
    {
        return bccomp($left, $right, self::MONEY_SCALE) <= 0 ? $left : $right;
    }

    /**
     * Validate numeric decimal syntax and reject negative amounts.
     */
    private function normalizeNonNegativeMoney(string $value, string $field): string
    {
        $trimmed = trim($value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw new InvalidArgumentException("Invalid decimal format for {$field}.");
        }

        $normalized = bcadd($trimmed, '0', self::MONEY_SCALE);

        if (bccomp($normalized, '0.0000', self::MONEY_SCALE) < 0) {
            throw new InvalidArgumentException("Amount {$field} cannot be negative.");
        }

        return $normalized;
    }
}
