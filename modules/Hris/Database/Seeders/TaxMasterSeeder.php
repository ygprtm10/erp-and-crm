<?php

declare(strict_types=1);

namespace Modules\Hris\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Models\SysTaxProgressiveBracket;
use Modules\Platform\Models\SysTaxPtkp;
use Modules\Platform\Models\SysTaxTerBracket;

/**
 * Idempotently seeds global Indonesian PTKP, PP 58/2023 TER brackets, and UU HPP Article 17 tiers.
 */
class TaxMasterSeeder extends Seeder
{
    private const EFFECTIVE_YEAR = 2024;

    /**
     * Run the database seeds across all environments.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedPtkpStatuses();
            $this->seedTerBrackets();
            $this->seedProgressiveBrackets();
        });
    }

    /**
     * Seed all eight official Indonesian PTKP statuses and their PP 58/2023 TER categories.
     */
    private function seedPtkpStatuses(): void
    {
        $rows = [
            ['code' => 'TK/0', 'ter_category' => 'A', 'annual_amount' => '54000000.0000'],
            ['code' => 'TK/1', 'ter_category' => 'A', 'annual_amount' => '58500000.0000'],
            ['code' => 'K/0', 'ter_category' => 'A', 'annual_amount' => '58500000.0000'],
            ['code' => 'TK/2', 'ter_category' => 'B', 'annual_amount' => '63000000.0000'],
            ['code' => 'K/1', 'ter_category' => 'B', 'annual_amount' => '63000000.0000'],
            ['code' => 'TK/3', 'ter_category' => 'B', 'annual_amount' => '67500000.0000'],
            ['code' => 'K/2', 'ter_category' => 'B', 'annual_amount' => '67500000.0000'],
            ['code' => 'K/3', 'ter_category' => 'C', 'annual_amount' => '72000000.0000'],
        ];

        foreach ($rows as $row) {
            SysTaxPtkp::query()->updateOrCreate(
                [
                    'code' => $row['code'],
                    'effective_year' => self::EFFECTIVE_YEAR,
                ],
                [
                    'ter_category' => $row['ter_category'],
                    'annual_amount' => $row['annual_amount'],
                ],
            );
        }
    }

    /**
     * Seed complete PP 58/2023 monthly TER brackets for categories A, B, and C.
     */
    private function seedTerBrackets(): void
    {
        $tiersByCategory = [
            'A' => [
                [5400000, '0.0000'],
                [5650000, '0.2500'],
                [5950000, '0.5000'],
                [6300000, '0.7500'],
                [6750000, '1.0000'],
                [7500000, '1.2500'],
                [8550000, '1.5000'],
                [9650000, '1.7500'],
                [10050000, '2.0000'],
                [10350000, '2.2500'],
                [10700000, '2.5000'],
                [11050000, '3.0000'],
                [11600000, '3.5000'],
                [12500000, '4.0000'],
                [13750000, '5.0000'],
                [15100000, '6.0000'],
                [16950000, '7.0000'],
                [19750000, '8.0000'],
                [24150000, '9.0000'],
                [26450000, '10.0000'],
                [28000000, '11.0000'],
                [30050000, '12.0000'],
                [32400000, '13.0000'],
                [35400000, '14.0000'],
                [39100000, '15.0000'],
                [43850000, '16.0000'],
                [47800000, '17.0000'],
                [51400000, '18.0000'],
                [56300000, '19.0000'],
                [62200000, '20.0000'],
                [68600000, '21.0000'],
                [77500000, '22.0000'],
                [89000000, '23.0000'],
                [103000000, '24.0000'],
                [125000000, '25.0000'],
                [157000000, '26.0000'],
                [206000000, '27.0000'],
                [337000000, '28.0000'],
                [454000000, '29.0000'],
                [550000000, '30.0000'],
                [695000000, '31.0000'],
                [910000000, '32.0000'],
                [1400000000, '33.0000'],
                [null, '34.0000'],
            ],
            'B' => [
                [6200000, '0.0000'],
                [6500000, '0.2500'],
                [6850000, '0.5000'],
                [7300000, '0.7500'],
                [9200000, '1.0000'],
                [10750000, '1.5000'],
                [11250000, '2.0000'],
                [11600000, '2.5000'],
                [12600000, '3.0000'],
                [13600000, '4.0000'],
                [14950000, '5.0000'],
                [16400000, '6.0000'],
                [18450000, '7.0000'],
                [21850000, '8.0000'],
                [26000000, '9.0000'],
                [27700000, '10.0000'],
                [29350000, '11.0000'],
                [31450000, '12.0000'],
                [33950000, '13.0000'],
                [37100000, '14.0000'],
                [41100000, '15.0000'],
                [45800000, '16.0000'],
                [49500000, '17.0000'],
                [53800000, '18.0000'],
                [58500000, '19.0000'],
                [64000000, '20.0000'],
                [71000000, '21.0000'],
                [80000000, '22.0000'],
                [93000000, '23.0000'],
                [109000000, '24.0000'],
                [129000000, '25.0000'],
                [163000000, '26.0000'],
                [211000000, '27.0000'],
                [374000000, '28.0000'],
                [459000000, '29.0000'],
                [555000000, '30.0000'],
                [704000000, '31.0000'],
                [957000000, '32.0000'],
                [1405000000, '33.0000'],
                [null, '34.0000'],
            ],
            'C' => [
                [6600000, '0.0000'],
                [6950000, '0.2500'],
                [7350000, '0.5000'],
                [7800000, '0.7500'],
                [8850000, '1.0000'],
                [9800000, '1.2500'],
                [10950000, '1.5000'],
                [11200000, '1.7500'],
                [12050000, '2.0000'],
                [12950000, '3.0000'],
                [14150000, '4.0000'],
                [15550000, '5.0000'],
                [17050000, '6.0000'],
                [19500000, '7.0000'],
                [22700000, '8.0000'],
                [26600000, '9.0000'],
                [28100000, '10.0000'],
                [30100000, '11.0000'],
                [32600000, '12.0000'],
                [35400000, '13.0000'],
                [38900000, '14.0000'],
                [43000000, '15.0000'],
                [47400000, '16.0000'],
                [51200000, '17.0000'],
                [55800000, '18.0000'],
                [60400000, '19.0000'],
                [66700000, '20.0000'],
                [74500000, '21.0000'],
                [83200000, '22.0000'],
                [95600000, '23.0000'],
                [110400000, '24.0000'],
                [134000000, '25.0000'],
                [169000000, '26.0000'],
                [221000000, '27.0000'],
                [390000000, '28.0000'],
                [463000000, '29.0000'],
                [561000000, '30.0000'],
                [709000000, '31.0000'],
                [965000000, '32.0000'],
                [1419000000, '33.0000'],
                [null, '34.0000'],
            ],
        ];

        foreach ($tiersByCategory as $category => $tiers) {
            $previousMax = null;

            foreach ($tiers as [$maxGrossInt, $ratePercent]) {
                $minGross = $previousMax === null
                    ? '0.0000'
                    : bcadd($previousMax, '0.0001', 4);
                $maxGross = $maxGrossInt === null
                    ? null
                    : bcadd((string) $maxGrossInt, '0', 4);

                SysTaxTerBracket::query()->updateOrCreate(
                    [
                        'ter_category' => $category,
                        'min_gross' => $minGross,
                        'effective_year' => self::EFFECTIVE_YEAR,
                    ],
                    [
                        'max_gross' => $maxGross,
                        'rate_percent' => $ratePercent,
                    ],
                );

                $previousMax = $maxGross;
            }
        }
    }

    /**
     * Seed progressive annual Article 17 UU HPP brackets (5%, 15%, 25%, 30%, 35%).
     */
    private function seedProgressiveBrackets(): void
    {
        $tiers = [
            ['min_pkp' => '0.0000', 'max_pkp' => '60000000.0000', 'rate_percent' => '5.0000'],
            ['min_pkp' => '60000000.0001', 'max_pkp' => '250000000.0000', 'rate_percent' => '15.0000'],
            ['min_pkp' => '250000000.0001', 'max_pkp' => '500000000.0000', 'rate_percent' => '25.0000'],
            ['min_pkp' => '500000000.0001', 'max_pkp' => '5000000000.0000', 'rate_percent' => '30.0000'],
            ['min_pkp' => '5000000000.0001', 'max_pkp' => null, 'rate_percent' => '35.0000'],
        ];

        foreach ($tiers as $tier) {
            SysTaxProgressiveBracket::query()->updateOrCreate(
                [
                    'min_pkp' => $tier['min_pkp'],
                    'effective_year' => self::EFFECTIVE_YEAR,
                ],
                [
                    'max_pkp' => $tier['max_pkp'],
                    'rate_percent' => $tier['rate_percent'],
                ],
            );
        }
    }
}
