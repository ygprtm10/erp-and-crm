<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\VoucherResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Core\Filament\Resources\VoucherResource;

/**
 * Handles editing of an existing tenant promotional voucher.
 */
class EditVoucher extends EditRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = VoucherResource::class;

    /**
     * Define header actions shown on the edit page.
     *
     * @return array<int, DeleteAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
