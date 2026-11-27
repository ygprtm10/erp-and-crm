<?php

declare(strict_types=1);

namespace Modules\Accounting\Filament\Resources;

use App\Concerns\RequiresModule;
use App\Models\User;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\ExpenseStatus;
use Modules\Accounting\Filament\Resources\ExpenseResource\Pages\CreateExpense;
use Modules\Accounting\Filament\Resources\ExpenseResource\Pages\EditExpense;
use Modules\Accounting\Filament\Resources\ExpenseResource\Pages\ListExpenses;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Services\ExpenseService;
use UnitEnum;

/**
 * Tenant-scoped operational expenses resource gated by the accounting module subscription.
 */
class ExpenseResource extends Resource
{
    use RequiresModule;

    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = Expense::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    /**
     * The navigation label displayed in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Expenses';

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
     * Restrict direct editing to unpaid expense records only.
     */
    public static function canEdit(Model $record): bool
    {
        if (! $record instanceof Expense || $record->status !== ExpenseStatus::Unpaid) {
            return false;
        }

        return parent::canEdit($record);
    }

    /**
     * Restrict direct deletion to unpaid expense records only.
     */
    public static function canDelete(Model $record): bool
    {
        if (! $record instanceof Expense || $record->status !== ExpenseStatus::Unpaid) {
            return false;
        }

        return parent::canDelete($record);
    }

    /**
     * Define the create/edit form schema for Expense records.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Operational Expense')
                    ->columns(2)
                    ->schema([
                        TextInput::make('expense_number')
                            ->required()
                            ->scopedUnique(ignoreRecord: true)
                            ->default(static fn (): string => 'EXP-'.now()->format('YmdHis'))
                            ->maxLength(64),
                        Select::make('expense_account_id')
                            ->label('Expense Account')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->relationship(
                                name: 'expenseAccount',
                                titleAttribute: 'name',
                                modifyQueryUsing: static fn (Builder $query): Builder => $query->where('type', AccountType::Expense->value),
                            ),
                        Select::make('branch_id')
                            ->label('Branch')
                            ->nullable()
                            ->searchable()
                            ->preload()
                            ->relationship('branch', 'name'),
                        Select::make('contact_id')
                            ->label('Vendor')
                            ->nullable()
                            ->searchable()
                            ->preload()
                            ->relationship(
                                name: 'contact',
                                titleAttribute: 'name',
                                modifyQueryUsing: static fn (Builder $query): Builder => $query->where('is_vendor', true),
                            ),
                        TextInput::make('amount')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->prefix('IDR'),
                        DatePicker::make('expense_date')
                            ->required()
                            ->default(now()),
                        DatePicker::make('due_date')
                            ->nullable(),
                        FileUpload::make('receipt_path')
                            ->label('Receipt / Proof')
                            ->directory('expense-receipts')
                            ->nullable(),
                        Textarea::make('notes')
                            ->rows(2)
                            ->nullable()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Define the listing table schema and settlement actions for Expense records.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('expense_date', 'desc')
            ->columns([
                TextColumn::make('expense_number')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('expense_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('expenseAccount.name')
                    ->label('Expense Account')
                    ->searchable(),
                TextColumn::make('contact.name')
                    ->label('Vendor')
                    ->placeholder('-'),
                TextColumn::make('amount')
                    ->money('IDR')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(static fn (ExpenseStatus $state): string => ucfirst($state->value))
                    ->color(static fn (ExpenseStatus $state): string => $state === ExpenseStatus::Paid ? 'success' : 'warning'),
                TextColumn::make('paymentAccount.name')
                    ->label('Paid From')
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        ExpenseStatus::Unpaid->value => 'Unpaid',
                        ExpenseStatus::Paid->value => 'Paid',
                    ]),
            ])
            ->recordActions([
                Action::make('markAsPaid')
                    ->label('Tandai Dibayar')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(static fn (Expense $record): bool => $record->status === ExpenseStatus::Unpaid)
                    ->requiresConfirmation()
                    ->schema([
                        Select::make('payment_account_id')
                            ->label('Payment Account (Asset)')
                            ->required()
                            ->options(static fn (): array => Account::query()
                                ->where('type', AccountType::Asset->value)
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(static fn (Account $account): array => [
                                    $account->getKey() => "{$account->code} - {$account->name}",
                                ])
                                ->all()),
                    ])
                    ->action(function (Expense $record, array $data, Action $action): void {
                        $paymentAccount = Account::query()
                            ->where('type', AccountType::Asset->value)
                            ->find((int) ($data['payment_account_id'] ?? 0));

                        if (! $paymentAccount instanceof Account) {
                            Notification::make()
                                ->title('Invalid asset payment account selected.')
                                ->danger()
                                ->send();

                            $action->halt();

                            return;
                        }

                        /** @var User|null $user */
                        $user = Auth::user();

                        try {
                            app(ExpenseService::class)->payExpense($record, $paymentAccount, $user);

                            Notification::make()
                                ->title('Expense marked as paid and journal posted.')
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
                EditAction::make()
                    ->visible(static fn (Expense $record): bool => static::canEdit($record)),
                DeleteAction::make()
                    ->visible(static fn (Expense $record): bool => static::canDelete($record)),
            ]);
    }

    /**
     * Register pages belonging to the Expense resource.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }
}
