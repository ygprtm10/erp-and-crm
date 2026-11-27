<?php

declare(strict_types=1);

namespace Modules\Iot\Filament\Resources\IotDeviceResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Iot\Filament\Resources\IotDeviceResource;

/**
 * Edits metadata and operational configuration for an existing ESP32 IoT device.
 */
class EditIotDevice extends EditRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = IotDeviceResource::class;

    /**
     * Expose delete action in the edit page header.
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
