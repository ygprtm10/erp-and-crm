<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;
use Modules\Accounting\Providers\AccountingServiceProvider;
use Modules\Core\Providers\CoreServiceProvider;
use Modules\Hris\Providers\HrisServiceProvider;
use Modules\Iot\Providers\IotServiceProvider;
use Modules\Platform\Providers\PlatformServiceProvider;
use Modules\Transaction\Providers\TransactionServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    AppPanelProvider::class,

    /** Wave 1: Fundamental system modules. */
    PlatformServiceProvider::class,
    CoreServiceProvider::class,
    AccountingServiceProvider::class,
    TransactionServiceProvider::class,

    /** Wave 2: HRIS, Payroll, and operational expansion modules. */
    HrisServiceProvider::class,
    IotServiceProvider::class,
];
