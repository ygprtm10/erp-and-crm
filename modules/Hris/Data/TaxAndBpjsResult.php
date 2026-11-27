<?php

declare(strict_types=1);

namespace Modules\Hris\Data;

/**
 * Immutable decimal calculation output from TaxAndBpjsCalculatorService.
 */
final readonly class TaxAndBpjsResult
{
    /**
     * Create a new tax and BPJS calculation result object.
     */
    public function __construct(
        public string $bpjsKesehatanEmployee,
        public string $bpjsKesehatanCompany,
        public string $bpjsTkEmployee,
        public string $bpjsTkCompany,
        public string $jkkCompany,
        public string $jkmCompany,
        public string $taxableGross,
        public string $terCategory,
        public string $terRatePercent,
        public string $pph21Amount,
    ) {}
}
