<?php

declare(strict_types=1);

namespace Modules\Accounting\Listeners;

use App\Models\Company;
use Modules\Accounting\Actions\SeedDefaultAccountsAction;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Platform\Events\WithdrawalCompleted;
use Modules\Platform\Models\SaasWithdrawal;

/**
 * Automatically records bank transfer settlement journals when a tenant withdrawal completes.
 */
class PostWithdrawalJournalListener
{
    /**
     * Inject accounting services required for automated withdrawal journaling.
     */
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly SeedDefaultAccountsAction $seedDefaultAccountsAction,
    ) {}

    /**
     * Post Debit Bank (+ fee if any) and Credit Platform Wallet Balance when accounting is active.
     */
    public function handle(WithdrawalCompleted $event): void
    {
        $withdrawal = $event->withdrawal;
        $company = Company::query()->find($withdrawal->company_id);

        if (! $company instanceof Company || ! $company->hasActiveModule('accounting')) {
            return;
        }

        $alreadyPosted = Journal::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('reference_type', SaasWithdrawal::class)
            ->where('reference_id', $withdrawal->getKey())
            ->exists();

        if ($alreadyPosted) {
            return;
        }

        $accounts = $this->resolveSystemAccounts($company);
        $bankAccount = $accounts['1102'];
        $platformWalletAccount = $accounts['1103'];
        $operationalFeeAccount = $accounts['6102'];

        $items = [
            [
                'account_id' => $bankAccount->getKey(),
                'debit' => (string) $withdrawal->net_transfer_amount,
                'credit' => '0.0000',
                'memo' => 'Bank payout received for '.$withdrawal->withdrawal_number,
            ],
        ];

        if (bccomp((string) $withdrawal->admin_fee, '0.0000', 4) > 0) {
            $items[] = [
                'account_id' => $operationalFeeAccount->getKey(),
                'debit' => (string) $withdrawal->admin_fee,
                'credit' => '0.0000',
                'memo' => 'Withdrawal transfer fee for '.$withdrawal->withdrawal_number,
            ];
        }

        $items[] = [
            'account_id' => $platformWalletAccount->getKey(),
            'debit' => '0.0000',
            'credit' => (string) $withdrawal->amount,
            'memo' => 'Settlement from Platform Wallet '.$withdrawal->withdrawal_number,
        ];

        $this->journalPostingService->createAndPostJournal(
            company: $company,
            journalDate: ($withdrawal->processed_at ?? now())->toDateString(),
            description: 'Escrow withdrawal settlement '.$withdrawal->withdrawal_number,
            items: $items,
            referenceType: SaasWithdrawal::class,
            referenceId: (int) $withdrawal->getKey(),
        );
    }

    /**
     * Resolve required system COA accounts, provisioning defaults first if absent.
     *
     * @return array<string, Account>
     */
    private function resolveSystemAccounts(Company $company): array
    {
        $requiredCodes = ['1102', '1103', '6102'];

        $accounts = Account::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->whereIn('code', $requiredCodes)
            ->get()
            ->keyBy('code');

        if ($accounts->count() < count($requiredCodes)) {
            $this->seedDefaultAccountsAction->execute($company);

            $accounts = Account::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $company->getKey())
                ->whereIn('code', $requiredCodes)
                ->get()
                ->keyBy('code');
        }

        return [
            '1102' => $accounts->get('1102'),
            '1103' => $accounts->get('1103'),
            '6102' => $accounts->get('6102'),
        ];
    }
}
