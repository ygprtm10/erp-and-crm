<?php

declare(strict_types=1);

namespace Modules\Accounting\Filament\Resources;

use App\Concerns\RequiresModule;
use App\Models\Company;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
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
use Modules\Accounting\Actions\SeedDefaultAccountsAction;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\NormalBalance;
use Modules\Accounting\Filament\Resources\AccountResource\Pages\CreateAccount;
use Modules\Accounting\Filament\Resources\AccountResource\Pages\EditAccount;
use Modules\Accounting\Filament\Resources\AccountResource\Pages\ListAccounts;
use Modules\Accounting\Models\Account;
use UnitEnum;

/**
 * Tenant-scoped Chart of Accounts resource gated by the accounting module subscription.
 */
class AccountResource extends Resource
{
    use RequiresModule;

    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = Account::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    /**
     * The navigation label displayed in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Chart of Accounts';

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
     * Prevent deleting system accounts or accounts already referenced by journal items.
     */
    public static function canDelete(Model $record): bool
    {
        if (! $record instanceof Account) {
            return false;
        }

        if ($record->is_system || $record->journalItems()->withoutGlobalScope('company')->exists()) {
            return false;
        }

        return parent::canDelete($record);
    }

    /**
     * Define the create/edit form schema for Chart of Accounts records.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->scopedUnique(ignoreRecord: true)
                            ->disabled(static fn (?Account $record): bool => (bool) $record?->is_system)
                            ->maxLength(32),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('type')
                            ->required()
                            ->disabled(static fn (?Account $record): bool => (bool) $record?->is_system)
                            ->options([
                                AccountType::Asset->value => 'Asset',
                                AccountType::Liability->value => 'Liability',
                                AccountType::Equity->value => 'Equity',
                                AccountType::Revenue->value => 'Revenue',
                                AccountType::Expense->value => 'Expense',
                            ]),
                        Select::make('normal_balance')
                            ->required()
                            ->disabled(static fn (?Account $record): bool => (bool) $record?->is_system)
                            ->options([
                                NormalBalance::Debit->value => 'Debit',
                                NormalBalance::Credit->value => 'Credit',
                            ]),
                        Select::make('parent_id')
                            ->label('Parent Account')
                            ->nullable()
                            ->searchable()
                            ->preload()
                            ->relationship(
                                name: 'parent',
                                titleAttribute: 'name',
                                modifyQueryUsing: static fn (Builder $query, ?Account $record): Builder => $query->when(
                                    $record !== null,
                                    static fn (Builder $scoped): Builder => $scoped->whereKeyNot($record->getKey()),
                                ),
                            )
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Define the listing table schema for Chart of Accounts records.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('parent.name')
                    ->label('Parent')
                    ->placeholder('-'),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(static fn (AccountType $state): string => ucfirst($state->value)),
                TextColumn::make('normal_balance')
                    ->label('Normal')
                    ->badge()
                    ->formatStateUsing(static fn (NormalBalance $state): string => ucfirst($state->value)),
                TextColumn::make('is_system')
                    ->label('Origin')
                    ->badge()
                    ->formatStateUsing(static fn (bool $state): string => $state ? 'System' : 'Custom')
                    ->color(static fn (bool $state): string => $state ? 'info' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        AccountType::Asset->value => 'Asset',
                        AccountType::Liability->value => 'Liability',
                        AccountType::Equity->value => 'Equity',
                        AccountType::Revenue->value => 'Revenue',
                        AccountType::Expense->value => 'Expense',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(static fn (Account $record): bool => static::canDelete($record)),
            ]);
    }

    /**
     * Build the header action that idempotently loads standard Indonesian UMKM COA accounts.
     */
    public static function makeSeedDefaultAccountsAction(): Action
    {
        return Action::make('seedDefaultAccounts')
            ->label('Muat COA Standar')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->requiresConfirmation()
            ->action(function (): void {
                $tenant = Filament::getTenant();

                if (! $tenant instanceof Company) {
                    return;
                }

                app(SeedDefaultAccountsAction::class)->execute($tenant);

                Notification::make()
                    ->title('Standard Chart of Accounts loaded successfully.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Register pages belonging to the Account resource.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAccounts::route('/'),
            'create' => CreateAccount::route('/create'),
            'edit' => EditAccount::route('/{record}/edit'),
        ];
    }
}
