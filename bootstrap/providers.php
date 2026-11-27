<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;
use Modules\Core\Providers\CoreServiceProvider;
use Modules\Platform\Providers\PlatformServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    AppPanelProvider::class,

    /** Wave 1: Fundamental system modules. */
    PlatformServiceProvider::class,
    CoreServiceProvider::class,
];
