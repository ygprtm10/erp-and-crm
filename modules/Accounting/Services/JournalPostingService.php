<?php

declare(strict_types=1);

namespace Modules\Accounting\Services;

use App\Models\Company;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalItem;
use Modules\Core\Models\Branch;

/**
 * Enforces double-entry validation, bcmath balance equality, posting immutability, and void reversals.
 */
class JournalPostingService
{
    private const MONEY_SCALE = 4;

    /**
     * Create a validated balanced draft journal with line items inside a transaction.
     *
     * @param  list<array{account_id: int, debit?: string|int|float, credit?: string|int|float, memo?: string|null}>  $items
     */
    public function createDraftJournal(
        Company $company,
        CarbonInterface|string $journalDate,
        string $description,
        array $items,
        ?Branch $branch = null,
        ?User $createdBy = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $journalNumber = null,
    ): Journal {
        if ($branch instanceof Branch && (int) $branch->company_id !== (int) $company->getKey()) {
            throw new InvalidArgumentException('Branch does not belong to the specified company.');
        }

        $trimmedDescription = trim($description);
        if ($trimmedDescription === '') {
            throw new InvalidArgumentException('Journal description is required.');
        }

        $date = $journalDate instanceof CarbonInterface
            ? $journalDate->toDateString()
            : Carbon::parse($journalDate)->toDateString();

        return DB::transaction(function () use (
            $company,
            $branch,
            $createdBy,
            $date,
            $trimmedDescription,
            $items,
            $referenceType,
            $referenceId,
            $journalNumber,
        ): Journal {
            $normalizedItems = $this->validateAndNormalizeItems((int) $company->getKey(), $items);

            $journal = Journal::query()
                ->withoutGlobalScope('company')
                ->create([
                    'company_id' => $company->getKey(),
                    'branch_id' => $branch?->getKey(),
                    'journal_number' => $journalNumber ?? $this->generateJournalNumber(),
                    'journal_date' => $date,
                    'description' => $trimmedDescription,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'status' => JournalStatus::Draft,
                    'created_by' => $createdBy?->getKey(),
                ]);

            foreach ($normalizedItems as $line) {
                JournalItem::query()
                    ->withoutGlobalScope('company')
                    ->create([
                        'company_id' => $company->getKey(),
                        'journal_id' => $journal->getKey(),
                        'account_id' => $line['account_id'],
                        'debit' => $line['debit'],
                        'credit' => $line['credit'],
                        'memo' => $line['memo'],
                    ]);
            }

            return $journal->load('items');
        });
    }

    /**
     * Validate and transition a draft journal to posted status atomically.
     */
    public function postJournal(Journal $journal): Journal
    {
        return DB::transaction(function () use ($journal): Journal {
            $lockedJournal = Journal::query()
                ->withoutGlobalScope('company')
                ->whereKey($journal->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedJournal instanceof Journal) {
                throw new DomainException('Journal entry not found.');
            }

            if ($lockedJournal->status !== JournalStatus::Draft) {
                throw new DomainException('Only draft journals can be posted.');
            }

            $rawItems = JournalItem::query()
                ->withoutGlobalScope('company')
                ->where('journal_id', $lockedJournal->getKey())
                ->get()
                ->map(static fn (JournalItem $item): array => [
                    'account_id' => (int) $item->account_id,
                    'debit' => (string) $item->debit,
                    'credit' => (string) $item->credit,
                    'memo' => $item->memo,
                ])
                ->all();

            $this->validateAndNormalizeItems((int) $lockedJournal->company_id, $rawItems);

            $lockedJournal->update([
                'status' => JournalStatus::Posted,
            ]);

            return $lockedJournal->refresh()->load('items');
        });
    }

    /**
     * Create a journal entry and immediately post it inside a single database transaction.
     *
     * @param  list<array{account_id: int, debit?: string|int|float, credit?: string|int|float, memo?: string|null}>  $items
     */
    public function createAndPostJournal(
        Company $company,
        CarbonInterface|string $journalDate,
        string $description,
        array $items,
        ?Branch $branch = null,
        ?User $createdBy = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $journalNumber = null,
    ): Journal {
        return DB::transaction(function () use (
            $company,
            $journalDate,
            $description,
            $items,
            $branch,
            $createdBy,
            $referenceType,
            $referenceId,
            $journalNumber,
        ): Journal {
            $draft = $this->createDraftJournal(
                company: $company,
                journalDate: $journalDate,
                description: $description,
                items: $items,
                branch: $branch,
                createdBy: $createdBy,
                referenceType: $referenceType,
                referenceId: $referenceId,
                journalNumber: $journalNumber,
            );

            return $this->postJournal($draft);
        });
    }

    /**
     * Void a posted journal and automatically post a balanced reversing entry for auditability.
     */
    public function voidJournal(Journal $journal, ?User $voidedBy = null, ?string $reason = null): Journal
    {
        return DB::transaction(function () use ($journal, $voidedBy, $reason): Journal {
            $lockedJournal = Journal::query()
                ->withoutGlobalScope('company')
                ->whereKey($journal->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedJournal instanceof Journal) {
                throw new DomainException('Journal entry not found.');
            }

            if ($lockedJournal->status !== JournalStatus::Posted) {
                throw new DomainException('Only posted journals can be voided.');
            }

            $originalItems = JournalItem::query()
                ->withoutGlobalScope('company')
                ->where('journal_id', $lockedJournal->getKey())
                ->get();

            $lockedJournal->update([
                'status' => JournalStatus::Void,
            ]);

            /** Swap debit and credit on each line to build an exact reversing entry. */
            $reversingItems = $originalItems
                ->map(static fn (JournalItem $item): array => [
                    'account_id' => (int) $item->account_id,
                    'debit' => (string) $item->credit,
                    'credit' => (string) $item->debit,
                    'memo' => $item->memo !== null ? 'Reversal: '.$item->memo : 'Reversal entry',
                ])
                ->values()
                ->all();

            $company = Company::query()->findOrFail($lockedJournal->company_id);
            $branch = $lockedJournal->branch_id !== null
                ? Branch::query()->withoutGlobalScope('company')->find($lockedJournal->branch_id)
                : null;

            $descriptionSuffix = $reason !== null && trim($reason) !== '' ? ' ('.trim($reason).')' : '';

            return $this->createAndPostJournal(
                company: $company,
                journalDate: now()->toDateString(),
                description: 'Reversal of '.$lockedJournal->journal_number.$descriptionSuffix,
                items: $reversingItems,
                branch: $branch,
                createdBy: $voidedBy,
                referenceType: Journal::class,
                referenceId: $lockedJournal->getKey(),
            );
        });
    }

    /**
     * Validate line count, tenant ownership, single-sided debit/credit rules, and exact bcmath equality.
     *
     * @param  list<array{account_id: int, debit?: string|int|float, credit?: string|int|float, memo?: string|null}>  $items
     * @return list<array{account_id: int, debit: string, credit: string, memo: string|null}>
     */
    private function validateAndNormalizeItems(int $companyId, array $items): array
    {
        if (count($items) < 2) {
            throw new DomainException('A journal entry requires at least two line items.');
        }

        $accountIds = array_values(array_unique(array_map(
            static fn (array $item): int => (int) ($item['account_id'] ?? 0),
            $items,
        )));

        $validAccountsCount = Account::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->whereIn('id', $accountIds)
            ->count();

        if ($validAccountsCount !== count($accountIds)) {
            throw new DomainException('All journal item accounts must exist and belong to the target company.');
        }

        $totalDebit = '0.0000';
        $totalCredit = '0.0000';
        $normalized = [];

        foreach ($items as $item) {
            $debit = $this->normalizeNonNegativeAmount((string) ($item['debit'] ?? '0.0000'), 'debit');
            $credit = $this->normalizeNonNegativeAmount((string) ($item['credit'] ?? '0.0000'), 'credit');

            $hasDebit = bccomp($debit, '0.0000', self::MONEY_SCALE) > 0;
            $hasCredit = bccomp($credit, '0.0000', self::MONEY_SCALE) > 0;

            if ($hasDebit && $hasCredit) {
                throw new DomainException('A single journal item cannot contain both debit and credit amounts.');
            }

            if (! $hasDebit && ! $hasCredit) {
                throw new DomainException('Each journal item must have either a positive debit or credit amount.');
            }

            $totalDebit = bcadd($totalDebit, $debit, self::MONEY_SCALE);
            $totalCredit = bcadd($totalCredit, $credit, self::MONEY_SCALE);

            $normalized[] = [
                'account_id' => (int) $item['account_id'],
                'debit' => $debit,
                'credit' => $credit,
                'memo' => isset($item['memo']) ? trim((string) $item['memo']) : null,
            ];
        }

        if (bccomp($totalDebit, '0.0000', self::MONEY_SCALE) <= 0) {
            throw new DomainException('Journal total amount must be greater than zero.');
        }

        /** Enforce exact double-entry equilibrium using bcmath scale 4. */
        if (bccomp($totalDebit, $totalCredit, self::MONEY_SCALE) !== 0) {
            throw new DomainException("Unbalanced journal entry: total debit ({$totalDebit}) does not equal total credit ({$totalCredit}).");
        }

        return $normalized;
    }

    /**
     * Validate numeric decimal syntax and reject negative debit or credit values.
     */
    private function normalizeNonNegativeAmount(string $value, string $field): string
    {
        $trimmed = trim($value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw new InvalidArgumentException("Invalid decimal format for {$field}.");
        }

        $normalized = bcadd($trimmed, '0', self::MONEY_SCALE);

        if (bccomp($normalized, '0.0000', self::MONEY_SCALE) < 0) {
            throw new DomainException("Journal item {$field} cannot be negative.");
        }

        return $normalized;
    }

    /**
     * Generate a unique journal reference number prefixed with JRN-.
     */
    private function generateJournalNumber(): string
    {
        return 'JRN-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
    }
}
