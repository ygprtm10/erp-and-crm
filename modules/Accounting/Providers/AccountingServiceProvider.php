<?php

declare(strict_types=1);

namespace Modules\Accounting\Providers;

use App\Support\BaseModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Modules\Accounting\Actions\SeedDefaultAccountsAction;
use Modules\Accounting\Events\ExpensePaid;
use Modules\Accounting\Listeners\PostExpenseJournalListener;
use Modules\Accounting\Listeners\PostOrderRevenueJournalListener;
use Modules\Accounting\Listeners\PostPayrollJournalListener;
use Modules\Accounting\Listeners\PostWithdrawalJournalListener;
use Modules\Accounting\Services\ExpenseService;
use Modules\Accounting\Services\FinancialReportService;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Hris\Events\PayrollPaid;
use Modules\Platform\Events\WithdrawalCompleted;
use Modules\Transaction\Events\OrderPaid;

/**
 * Service provider for the Accounting double-entry bookkeeping domain module.
 */
class AccountingServiceProvider extends BaseModuleServiceProvider
{
    /**
     * Return the module directory name.
     */
    protected function moduleName(): string
    {
        return 'Accounting';
    }

    /**
     * Register Accounting actions and services in the container.
     */
    public function register(): void
    {
        $this->app->singleton(SeedDefaultAccountsAction::class);
        $this->app->singleton(JournalPostingService::class);
        $this->app->singleton(ExpenseService::class);
        $this->app->singleton(FinancialReportService::class);
    }

    /**
     * Subscribe automated double-entry journal listeners to domain events.
     */
    protected function bootListeners(): void
    {
        Event::listen(ExpensePaid::class, PostExpenseJournalListener::class);
        Event::listen(WithdrawalCompleted::class, PostWithdrawalJournalListener::class);
        Event::listen(OrderPaid::class, PostOrderRevenueJournalListener::class);
        Event::listen(PayrollPaid::class, PostPayrollJournalListener::class);
    }
}
