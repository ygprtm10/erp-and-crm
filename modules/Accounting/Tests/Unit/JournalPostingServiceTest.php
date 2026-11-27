<?php

declare(strict_types=1);

namespace Modules\Accounting\Tests\Unit;

use App\Models\Company;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Actions\SeedDefaultAccountsAction;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalItem;
use Modules\Accounting\Services\JournalPostingService;
use Tests\TestCase;

/**
 * Unit tests verifying double-entry balance validation, immutability guards, and void reversals.
 */
class JournalPostingServiceTest extends TestCase
{
    use RefreshDatabase;

    private JournalPostingService $service;

    /**
     * Resolve JournalPostingService before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(JournalPostingService::class);
    }

    /**
     * Ensure balanced draft journals post cleanly with 4-decimal bcmath precision and voiding generates a reversal.
     */
    public function test_posts_balanced_journal_and_generates_balanced_reversal_on_void(): void
    {
        /** Arrange a company with seeded COA accounts. */
        $company = Company::factory()->create();
        $accounts = app(SeedDefaultAccountsAction::class)->execute($company)->keyBy('code');

        /** @var Account $bank */
        $bank = $accounts->get('1102');
        /** @var Account $revenue */
        $revenue = $accounts->get('4101');

        /** Act 1: Create draft and post balanced journal. */
        $draft = $this->service->createDraftJournal(
            company: $company,
            journalDate: '2026-11-27',
            description: 'Cash sale settlement',
            items: [
                ['account_id' => $bank->getKey(), 'debit' => '150000.3456', 'credit' => '0.0000', 'memo' => 'Bank in'],
                ['account_id' => $revenue->getKey(), 'debit' => '0.0000', 'credit' => '150000.3456', 'memo' => 'Sales rev'],
            ],
        );

        $this->assertSame(JournalStatus::Draft, $draft->status);

        $posted = $this->service->postJournal($draft);
        $this->assertSame(JournalStatus::Posted, $posted->status);

        /** Act 2: Void the posted journal and inspect reversing entry. */
        $reversal = $this->service->voidJournal($posted, reason: 'Duplicate entry');

        $this->assertSame(JournalStatus::Void, $posted->refresh()->status);
        $this->assertSame(JournalStatus::Posted, $reversal->status);
        $this->assertSame(Journal::class, $reversal->reference_type);
        $this->assertSame($posted->getKey(), $reversal->reference_id);

        $reversalItems = $reversal->items->keyBy('account_id');
        $this->assertSame('150000.3456', $reversalItems->get($bank->getKey())->credit);
        $this->assertSame('150000.3456', $reversalItems->get($revenue->getKey())->debit);
    }

    /**
     * Ensure unbalanced, single-line, negative, both-sided, and cross-tenant journals are rejected.
     */
    public function test_rejects_unbalanced_negative_both_sided_and_cross_tenant_journals(): void
    {
        /** Arrange two companies with seeded COA accounts. */
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $accountsA = app(SeedDefaultAccountsAction::class)->execute($companyA)->keyBy('code');
        $accountsB = app(SeedDefaultAccountsAction::class)->execute($companyB)->keyBy('code');

        $bankA = $accountsA->get('1102');
        $revenueA = $accountsA->get('4101');
        $bankB = $accountsB->get('1102');

        /** Reject unbalanced debit vs credit. */
        $this->assertDomainException(fn () => $this->service->createDraftJournal(
            company: $companyA,
            journalDate: '2026-11-27',
            description: 'Unbalanced entry',
            items: [
                ['account_id' => $bankA->getKey(), 'debit' => '100000.0000'],
                ['account_id' => $revenueA->getKey(), 'credit' => '99999.9999'],
            ],
        ), 'unbalanced');

        /** Reject fewer than two items. */
        $this->assertDomainException(fn () => $this->service->createDraftJournal(
            company: $companyA,
            journalDate: '2026-11-27',
            description: 'Single line',
            items: [
                ['account_id' => $bankA->getKey(), 'debit' => '10000.0000'],
            ],
        ), 'at least two');

        /** Reject negative debit/credit values. */
        $this->assertDomainException(fn () => $this->service->createDraftJournal(
            company: $companyA,
            journalDate: '2026-11-27',
            description: 'Negative line',
            items: [
                ['account_id' => $bankA->getKey(), 'debit' => '-100.0000'],
                ['account_id' => $revenueA->getKey(), 'credit' => '-100.0000'],
            ],
        ), 'cannot be negative');

        /** Reject single line filling both debit and credit simultaneously. */
        $this->assertDomainException(fn () => $this->service->createDraftJournal(
            company: $companyA,
            journalDate: '2026-11-27',
            description: 'Both sides',
            items: [
                ['account_id' => $bankA->getKey(), 'debit' => '500.0000', 'credit' => '500.0000'],
                ['account_id' => $revenueA->getKey(), 'credit' => '500.0000'],
            ],
        ), 'both debit and credit');

        /** Reject cross-tenant account IDs. */
        $this->assertDomainException(fn () => $this->service->createDraftJournal(
            company: $companyA,
            journalDate: '2026-11-27',
            description: 'Cross tenant account',
            items: [
                ['account_id' => $bankA->getKey(), 'debit' => '500.0000'],
                ['account_id' => $bankB->getKey(), 'credit' => '500.0000'],
            ],
        ), 'target company');
    }

    /**
     * Ensure posted and voided journals and their line items cannot be mutated or deleted directly.
     */
    public function test_enforces_immutability_on_posted_and_void_journals_and_items(): void
    {
        /** Arrange a posted journal. */
        $company = Company::factory()->create();
        $accounts = app(SeedDefaultAccountsAction::class)->execute($company)->keyBy('code');

        $posted = $this->service->createAndPostJournal(
            company: $company,
            journalDate: '2026-11-27',
            description: 'Posted capital',
            items: [
                ['account_id' => $accounts->get('1102')->getKey(), 'debit' => '1000000.0000'],
                ['account_id' => $accounts->get('3101')->getKey(), 'credit' => '1000000.0000'],
            ],
        );

        /** Reject direct updates or deletion of posted journals and their items. */
        $this->assertDomainException(fn () => $posted->update(['description' => 'Tampered']), 'cannot be modified');
        $this->assertDomainException(fn () => $posted->delete(), 'only draft journals');
        $this->assertDomainException(fn () => JournalItem::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'journal_id' => $posted->getKey(),
            'account_id' => $accounts->get('1101')->getKey(),
            'debit' => '100.0000',
            'credit' => '0.0000',
        ]), 'immutable');

        /** Void once, then confirm double-voiding is blocked. */
        $this->service->voidJournal($posted);
        $this->assertDomainException(fn () => $this->service->voidJournal($posted->refresh()), 'only posted');
    }

    /**
     * Assert that running the callback throws a DomainException containing the expected text fragment.
     */
    private function assertDomainException(callable $callback, string $fragment): void
    {
        try {
            $callback();
            $this->fail("Expected DomainException containing '{$fragment}'.");
        } catch (DomainException $exception) {
            $this->assertStringContainsString($fragment, strtolower($exception->getMessage()));
        }
    }
}
