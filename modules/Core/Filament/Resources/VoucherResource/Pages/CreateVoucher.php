<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\VoucherResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Core\Filament\Resources\VoucherResource;

/**
 * Handles creation of a new promotional voucher for the active tenant company.
 */
class CreateVoucher extends CreateRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = VoucherResource::class;
}
