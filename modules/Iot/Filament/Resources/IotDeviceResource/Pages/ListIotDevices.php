<?php

declare(strict_types=1);

namespace Modules\Iot\Filament\Resources\IotDeviceResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Iot\Filament\Resources\IotDeviceResource;

/**
 * Lists tenant ESP32 IoT devices with heartbeat connectivity status and token management actions.
 */
class ListIotDevices extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = IotDeviceResource::class;

    /**
     * Expose the create device action in the page header.
     *
     * @return array<int, CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
