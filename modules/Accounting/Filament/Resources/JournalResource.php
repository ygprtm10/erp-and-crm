<?php

declare(strict_types=1);

namespace Modules\Accounting\Filament\Resources;

use App\Concerns\RequiresModule;
use App\Models\User;
use BackedEnum;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Filament\Resources\JournalResource\Pages\CreateJournal;
use Modules\Accounting\Filament\Resources\JournalResource\Pages\EditJournal;
use Modules\Accounting\Filament\Resources\JournalResource\Pages\ListJournals;
use Modules\Accounting\Filament\Resources\JournalResource\Pages\ViewJournal;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Services\JournalPostingService;
use UnitEnum;

/**
 * Tenant-scoped general ledger journal resource enforcing balanced items and post/void workflows.
 */
class JournalResource extends Resource
{
    use RequiresModule;

    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = Journal::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    /**
     * The navigation label displayed in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Journals';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    /**
     * Return the required module slug controlling access to this resource.
     */
    public static function requiredModuleSlug(): string
    {
        return 'accounting';
    }

    /**
     * Allow editing only when the journal is still in draft status.
     */
    public static function canEdit(Model $record): bool
    {
        if (! $record instanceof Journal || $record->status !== JournalStatus::Draft) {
            return false;
        }

        return parent::canEdit($record);
    }

    /**
     * Allow deleting only when the journal is still in draft status.
     */
    public static function canDelete(Model $record): bool
    {
        if (! $record instanceof Journal || $record->status !== JournalStatus::Draft) {
            return false;
        }

        return parent::canDelete($record);
    }

    /**
     * Define the create/edit form schema with balanced Repeater double-entry validation.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Journal Header')
                    ->columns(3)
                    ->schema([
                        TextInput::make('journal_number')
                            ->required()
                            ->scopedUnique(ignoreRecord: true)
                            ->default(static fn (): string => 'JRN-'.now()->format('YmdHis'))
                            ->maxLength(64),
                        DatePicker::make('journal_date')
                            ->required()
                            ->default(now()),
                        Select::make('branch_id')
                            ->label('Branch')
                            ->nullable()
                            ->searchable()
                            ->preload()
                            ->relationship('branch', 'name'),
                        TextInput::make('description')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
                Section::make('Double-Entry Items')
                    ->schema([
                        Repeater::make('items')
                            ->relationship('items')
                            ->minItems(2)
                            ->defaultItems(2)
                            ->columns(4)
                            ->rule(static function (): Closure {
                                return static function (string $attribute, mixed $value, Closure $fail): void {
                                    static::validateBalancedItems($value, $fail);
                                };
                            })
                            ->schema([
                                Select::make('account_id')
                                    ->label('Account')
                                    ->required()
                                    ->searchable()
                                    ->options(static fn (): array => Account::query()
                                        ->orderBy('code')
                                        ->get()
                                        ->mapWithKeys(static fn (Account $account): array => [
                                            $account->getKey() => "{$account->code} - {$account->name}",
                                        ])
                                        ->all()),
                                TextInput::make('debit')
                                    ->numeric()
                                    ->default('0.0000')
                                    ->minValue(0)
                                    ->required(),
                                TextInput::make('credit')
                                    ->numeric()
                                    ->default('0.0000')
                                    ->minValue(0)
                                    ->required(),
                                TextInput::make('memo')
                                    ->nullable()
                                    ->maxLength(255),
                            ]),
                    ]),
            ]);
    }

    /**
     * Define the read-only detail infolist schema for viewing posted or voided journals.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Journal Summary')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('journal_number')->badge(),
                        TextEntry::make('journal_date')->date(),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(static fn (JournalStatus $state): string => ucfirst($state->value))
                            ->color(static fn (JournalStatus $state): string => static::statusColor($state)),
                        TextEntry::make('description')->columnSpanFull(),
                    ]),
                Section::make('Journal Lines')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->columns(4)
                            ->schema([
                                TextEntry::make('account.code')->label('Code')->badge(),
                                TextEntry::make('account.name')->label('Account'),
                                TextEntry::make('debit')->money('IDR'),
                                TextEntry::make('credit')->money('IDR'),
                            ]),
                    ]),
            ]);
    }

    /**
     * Define the listing table schema and Post/Void workflow actions.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('journal_date', 'desc')
            ->columns([
                TextColumn::make('journal_number')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('journal_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('description')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('total_debit')
                    ->label('Total Debit')
                    ->state(static fn (Journal $record): string => static::sumItemColumn($record, 'debit'))
                    ->money('IDR'),
                TextColumn::make('total_credit')
                    ->label('Total Credit')
                    ->state(static fn (Journal $record): string => static::sumItemColumn($record, 'credit'))
                    ->money('IDR'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(static fn (JournalStatus $state): string => ucfirst($state->value))
                    ->color(static fn (JournalStatus $state): string => static::statusColor($state)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        JournalStatus::Draft->value => 'Draft',
                        JournalStatus::Posted->value => 'Posted',
                        JournalStatus::Void->value => 'Void',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                ...static::getWorkflowActions(),
                EditAction::make()
                    ->visible(static fn (Journal $record): bool => static::canEdit($record)),
                DeleteAction::make()
                    ->visible(static fn (Journal $record): bool => static::canDelete($record)),
            ]);
    }

    /**
     * Build shared Post Jurnal and Void Jurnal actions delegating to JournalPostingService.
     *
     * @return array<int, Action>
     */
    public static function getWorkflowActions(): array
    {
        return [
            Action::make('postJournal')
                ->label('Post Jurnal')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->visible(static fn (Journal $record): bool => $record->status === JournalStatus::Draft)
                ->requiresConfirmation()
                ->action(function (Journal $record, Action $action): void {
                    try {
                        app(JournalPostingService::class)->postJournal($record);

                        Notification::make()
                            ->title('Journal posted successfully.')
                            ->success()
                            ->send();
                    } catch (DomainException|InvalidArgumentException $exception) {
                        Notification::make()
                            ->title($exception->getMessage())
                            ->danger()
                            ->send();

                        $action->halt();
                    }
                }),
            Action::make('voidJournal')
                ->label('Void Jurnal')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->visible(static fn (Journal $record): bool => $record->status === JournalStatus::Posted)
                ->requiresConfirmation()
                ->modalHeading('Void Posted Journal')
                ->modalDescription('This will mark the journal as void and automatically create a reversing entry.')
                ->schema([
                    Textarea::make('reason')
                        ->label('Void Reason')
                        ->rows(2)
                        ->nullable(),
                ])
                ->action(function (Journal $record, array $data, Action $action): void {
                    /** @var User|null $user */
                    $user = Auth::user();

                    try {
                        app(JournalPostingService::class)->voidJournal(
                            journal: $record,
                            voidedBy: $user,
                            reason: $data['reason'] ?? null,
                        );

                        Notification::make()
                            ->title('Journal voided and reversing entry posted.')
                            ->warning()
                            ->send();
                    } catch (DomainException|InvalidArgumentException $exception) {
                        Notification::make()
                            ->title($exception->getMessage())
                            ->danger()
                            ->send();

                        $action->halt();
                    }
                }),
        ];
    }

    /**
     * Register pages belonging to the Journal resource.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListJournals::route('/'),
            'create' => CreateJournal::route('/create'),
            'view' => ViewJournal::route('/{record}'),
            'edit' => EditJournal::route('/{record}/edit'),
        ];
    }

    /**
     * Validate repeater line items to enforce double-entry balance before persistence.
     */
    private static function validateBalancedItems(mixed $items, Closure $fail): void
    {
        if (! is_array($items) || count($items) < 2) {
            $fail('A journal entry requires at least two line items.');

            return;
        }

        $totalDebit = '0.0000';
        $totalCredit = '0.0000';

        foreach ($items as $item) {
            if (! is_array($item)) {
                $fail('Invalid journal line item format.');

                return;
            }

            $debitRaw = trim((string) ($item['debit'] ?? '0'));
            $creditRaw = trim((string) ($item['credit'] ?? '0'));

            if (! is_numeric($debitRaw) || ! is_numeric($creditRaw)) {
                $fail('Debit and credit values must be numeric.');

                return;
            }

            $debit = bcadd($debitRaw, '0', 4);
            $credit = bcadd($creditRaw, '0', 4);

            $hasDebit = bccomp($debit, '0.0000', 4) > 0;
            $hasCredit = bccomp($credit, '0.0000', 4) > 0;

            if ($hasDebit && $hasCredit) {
                $fail('A single journal line cannot contain both debit and credit values.');

                return;
            }

            if (! $hasDebit && ! $hasCredit) {
                $fail('Each journal line must specify either a positive debit or credit amount.');

                return;
            }

            $totalDebit = bcadd($totalDebit, $debit, 4);
            $totalCredit = bcadd($totalCredit, $credit, 4);
        }

        if (bccomp($totalDebit, $totalCredit, 4) !== 0) {
            $fail("Total debit ({$totalDebit}) must equal total credit ({$totalCredit}).");
        }
    }

    /**
     * Sum debit or credit amounts across journal items using bcmath precision.
     */
    private static function sumItemColumn(Journal $record, string $column): string
    {
        $total = '0.0000';

        foreach ($record->items as $item) {
            $total = bcadd($total, (string) $item->{$column}, 4);
        }

        return $total;
    }

    /**
     * Map JournalStatus enum values to Filament semantic badge colors.
     */
    private static function statusColor(JournalStatus $status): string
    {
        return match ($status) {
            JournalStatus::Draft => 'warning',
            JournalStatus::Posted => 'success',
            JournalStatus::Void => 'danger',
        };
    }
}
