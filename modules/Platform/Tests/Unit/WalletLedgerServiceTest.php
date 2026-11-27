<?php

declare(strict_types=1);

namespace Modules\Platform\Tests\Unit;

use App\Models\Company;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Modules\Platform\Enums\WalletTransactionType;
use Modules\Platform\Enums\WithdrawalStatus;
use Modules\Platform\Events\WithdrawalCompleted;
use Modules\Platform\Models\SaasTenantBankAccount;
use Modules\Platform\Models\SaasTenantWallet;
use Modules\Platform\Models\SaasWalletTransaction;
use Modules\Platform\Services\WalletLedgerService;
use Tests\TestCase;

/**
 * Unit tests verifying precision, locking guards, and ledger integrity in WalletLedgerService.
 */
class WalletLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletLedgerService $service;

    /**
     * Resolve the WalletLedgerService instance before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WalletLedgerService::class);
    }

    /**
     * Ensure crediting sales updates wallet balances with bcmath precision and writes append-only ledger entries.
     */
    public function test_credit_sale_adds_precise_balances_and_records_ledger_transactions(): void
    {
        /** Arrange a tenant company. */
        $company = Company::factory()->create();

        /** Act by crediting two sales with 4-decimal fractional values. */
        $firstTx = $this->service->creditSale($company, '150000.1234', 'trx_payments', 101, 'Order TRX-101');
        $secondTx = $this->service->creditSale($company, '25000.8766', 'trx_payments', 102, 'Order TRX-102');

        $wallet = SaasTenantWallet::query()->where('company_id', $company->getKey())->firstOrFail();

        /** Assert exact bcmath arithmetic and append-only audit rows. */
        $this->assertSame('0.0000', $firstTx->balance_before);
        $this->assertSame('150000.1234', $firstTx->balance_after);
        $this->assertSame(WalletTransactionType::CreditSale, $firstTx->type);

        $this->assertSame('150000.1234', $secondTx->balance_before);
        $this->assertSame('175001.0000', $secondTx->balance_after);

        $this->assertSame('175001.0000', $wallet->available_balance);
        $this->assertSame('0.0000', $wallet->pending_balance);
        $this->assertSame('175001.0000', $wallet->total_earned);
        $this->assertSame('0.0000', $wallet->total_withdrawn);
    }

    /**
     * Ensure requesting and completing a withdrawal holds funds, writes debit_withdraw, and emits WithdrawalCompleted.
     */
    public function test_request_and_complete_withdrawal_lifecycle_updates_balances_and_dispatches_event(): void
    {
        /** Arrange faked events, company, users, bank account, and funded wallet. */
        Event::fake([WithdrawalCompleted::class]);

        $company = Company::factory()->create();
        $tenantUser = User::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $bankAccount = SaasTenantBankAccount::query()->create([
            'company_id' => $company->getKey(),
            'bank_code' => '014',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'PT Maju Jaya',
            'is_primary' => true,
            'is_verified' => true,
        ]);

        $this->service->creditSale($company, '500000.0000', 'trx_payments', 1);

        /** Act by requesting a full withdrawal and then completing it as Super Admin. */
        $withdrawal = $this->service->requestWithdrawal(
            company: $company,
            bankAccount: $bankAccount,
            requestedBy: $tenantUser,
            amount: '500000.0000',
            adminFee: '6500.0000',
            notes: 'Monthly settlement payout',
        );

        $walletAfterRequest = SaasTenantWallet::query()->where('company_id', $company->getKey())->firstOrFail();

        $this->assertSame('0.0000', $walletAfterRequest->available_balance);
        $this->assertSame('500000.0000', $walletAfterRequest->pending_balance);
        $this->assertSame('493500.0000', $withdrawal->net_transfer_amount);
        $this->assertSame(WithdrawalStatus::Pending, $withdrawal->status);
        $this->assertSame('BCA', $withdrawal->bank_snapshot['bank_name']);
        $this->assertStringStartsWith('WD-', $withdrawal->withdrawal_number);

        $completed = $this->service->completeWithdrawal(
            withdrawal: $withdrawal,
            processor: $superAdmin,
            transferProofPath: 'withdrawal-proofs/bca-001.jpg',
        );

        $walletAfterComplete = $walletAfterRequest->refresh();
        $debitTx = SaasWalletTransaction::query()
            ->where('wallet_id', $walletAfterComplete->getKey())
            ->where('type', WalletTransactionType::DebitWithdraw)
            ->firstOrFail();

        /** Assert pending balance is cleared, total_withdrawn updated, debit logged, and event dispatched. */
        $this->assertSame(WithdrawalStatus::Completed, $completed->status);
        $this->assertSame($superAdmin->getKey(), $completed->processed_by);
        $this->assertSame('withdrawal-proofs/bca-001.jpg', $completed->transfer_proof_path);
        $this->assertSame('0.0000', $walletAfterComplete->available_balance);
        $this->assertSame('0.0000', $walletAfterComplete->pending_balance);
        $this->assertSame('500000.0000', $walletAfterComplete->total_withdrawn);
        $this->assertSame('500000.0000', $debitTx->balance_before);
        $this->assertSame('0.0000', $debitTx->balance_after);

        Event::assertDispatched(WithdrawalCompleted::class, static fn (WithdrawalCompleted $event): bool => $event->withdrawal->is($completed));
    }

    /**
     * Ensure rejecting a withdrawal restores held funds back to available_balance without emitting WithdrawalCompleted.
     */
    public function test_reject_withdrawal_restores_available_balance(): void
    {
        /** Arrange a pending withdrawal on a funded wallet. */
        Event::fake([WithdrawalCompleted::class]);

        $company = Company::factory()->create();
        $tenantUser = User::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $bankAccount = SaasTenantBankAccount::query()->create([
            'company_id' => $company->getKey(),
            'bank_code' => '008',
            'bank_name' => 'Mandiri',
            'account_number' => '9876543210',
            'account_holder_name' => 'CV Berkah',
        ]);

        $this->service->creditSale($company, '250000.0000', 'trx_payments', 10);
        $withdrawal = $this->service->requestWithdrawal($company, $bankAccount, $tenantUser, '100000.0000');

        /** Act by rejecting the withdrawal with a clear reason. */
        $rejected = $this->service->rejectWithdrawal($withdrawal, $superAdmin, 'Account name mismatch');

        $wallet = SaasTenantWallet::query()->where('company_id', $company->getKey())->firstOrFail();

        /** Assert pending funds returned to available_balance and no completion event emitted. */
        $this->assertSame(WithdrawalStatus::Rejected, $rejected->status);
        $this->assertSame('Account name mismatch', $rejected->rejection_reason);
        $this->assertSame('250000.0000', $wallet->available_balance);
        $this->assertSame('0.0000', $wallet->pending_balance);
        $this->assertSame('0.0000', $wallet->total_withdrawn);

        Event::assertNotDispatched(WithdrawalCompleted::class);
    }

    /**
     * Ensure requesting a withdrawal exceeding available balance fails and preserves wallet state.
     */
    public function test_request_withdrawal_rejects_insufficient_balance(): void
    {
        /** Arrange a wallet with 100,000 available balance. */
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $bankAccount = SaasTenantBankAccount::query()->create([
            'company_id' => $company->getKey(),
            'bank_code' => '014',
            'bank_name' => 'BCA',
            'account_number' => '111222333',
            'account_holder_name' => 'Owner',
        ]);

        $this->service->creditSale($company, '100000.0000', 'trx_payments', 1);

        /** Expect DomainException when requesting 100,000.0001. */
        $this->expectException(DomainException::class);

        $this->service->requestWithdrawal($company, $bankAccount, $user, '100000.0001');
    }

    /**
     * Ensure completing an already completed or rejected withdrawal is blocked against race conditions.
     */
    public function test_double_processing_a_finalized_withdrawal_is_blocked(): void
    {
        /** Arrange a completed withdrawal. */
        Event::fake([WithdrawalCompleted::class]);

        $company = Company::factory()->create();
        $user = User::factory()->create();
        $admin = User::factory()->superAdmin()->create();
        $bankAccount = SaasTenantBankAccount::query()->create([
            'company_id' => $company->getKey(),
            'bank_code' => '014',
            'bank_name' => 'BCA',
            'account_number' => '111222333',
            'account_holder_name' => 'Owner',
        ]);

        $this->service->creditSale($company, '200000.0000', 'trx_payments', 1);
        $withdrawal = $this->service->requestWithdrawal($company, $bankAccount, $user, '100000.0000');
        $this->service->completeWithdrawal($withdrawal, $admin);

        /** Expect DomainException when attempting to complete the same withdrawal a second time. */
        $this->expectException(DomainException::class);

        $this->service->completeWithdrawal($withdrawal, $admin);
    }

    /**
     * Ensure cross-tenant bank accounts, non-positive amounts, fees >= amount, and blank rejection reasons fail validation.
     */
    public function test_validates_cross_tenant_bank_accounts_and_invalid_inputs(): void
    {
        /** Arrange two separate companies and a bank account owned by Company B. */
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $user = User::factory()->create();
        $bankB = SaasTenantBankAccount::query()->create([
            'company_id' => $companyB->getKey(),
            'bank_code' => '009',
            'bank_name' => 'BNI',
            'account_number' => '444555666',
            'account_holder_name' => 'Company B',
        ]);

        $this->service->creditSale($companyA, '100000.0000', 'trx_payments', 1);

        /** Assert zero credit throws InvalidArgumentException. */
        try {
            $this->service->creditSale($companyA, '0.0000', 'trx_payments', 2);
            $this->fail('Expected InvalidArgumentException for zero credit amount.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('greater than zero', $exception->getMessage());
        }

        /** Assert cross-tenant bank account throws InvalidArgumentException. */
        try {
            $this->service->requestWithdrawal($companyA, $bankB, $user, '50000.0000');
            $this->fail('Expected InvalidArgumentException for cross-tenant bank account.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('does not belong', $exception->getMessage());
        }

        /** Assert admin fee >= withdrawal amount throws InvalidArgumentException. */
        $bankA = SaasTenantBankAccount::query()->create([
            'company_id' => $companyA->getKey(),
            'bank_code' => '014',
            'bank_name' => 'BCA',
            'account_number' => '777888999',
            'account_holder_name' => 'Company A',
        ]);

        try {
            $this->service->requestWithdrawal($companyA, $bankA, $user, '5000.0000', '5000.0000');
            $this->fail('Expected InvalidArgumentException when admin fee equals amount.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('greater than the admin fee', $exception->getMessage());
        }

        /** Assert blank rejection reason throws InvalidArgumentException. */
        $withdrawal = $this->service->requestWithdrawal($companyA, $bankA, $user, '25000.0000');

        $this->expectException(InvalidArgumentException::class);
        $this->service->rejectWithdrawal($withdrawal, $user, '   ');
    }
}
