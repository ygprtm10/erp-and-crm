<?php

declare(strict_types=1);

namespace Modules\Iot\Providers;

use App\Support\BaseModuleServiceProvider;
use Modules\Iot\Actions\BindRfidCardAction;
use Modules\Iot\Services\DeviceHeartbeatService;
use Modules\Iot\Services\DeviceProvisioningService;
use Modules\Iot\Services\IotAttendanceIngestionService;

/**
 * Service provider for the IoT ESP32 hardware integration module.
 */
class IotServiceProvider extends BaseModuleServiceProvider
{
    /**
     * Return the module directory name.
     */
    protected function moduleName(): string
    {
        return 'Iot';
    }

    /**
     * Register IoT domain services and actions in the service container.
     */
    public function register(): void
    {
        $this->app->singleton(DeviceProvisioningService::class);
        $this->app->singleton(DeviceHeartbeatService::class);
        $this->app->singleton(IotAttendanceIngestionService::class);
        $this->app->singleton(BindRfidCardAction::class);
    }
}
