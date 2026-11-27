<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Tenant\Resources;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Platform\Filament\Tenant\Resources\TenantBankAccountResource\Pages\CreateTenantBankAccount;
use Modules\Platform\Filament\Tenant\Resources\TenantBankAccountResource\Pages\EditTenantBankAccount;
use Modules\Platform\Filament\Tenant\Resources\TenantBankAccountResource\Pages\ListTenantBankAccounts;
use Modules\Platform\Models\SaasTenantBankAccount;
use UnitEnum;

/**
 * Tenant-scoped resource for managing destination bank accounts used for escrow payouts.
 */
class TenantBankAccountResource extends Resource
{
    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = SaasTenantBankAccount::class;

    /**
     * Explicit relationship name on App\Models\Company for Filament tenant scoping.
     */
    protected static ?string $tenantRelationshipName = 'bankAccounts';

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    /**
     * The navigation label displayed in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Bank Accounts';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Billing & Payouts';

    /**
     * Define the create/edit form schema for tenant bank accounts.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Bank Account Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('bank_code')
                            ->label('Bank Code')
                            ->required()
                            ->maxLength(20),
                        TextInput::make('bank_name')
                            ->label('Bank Name')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('account_number')
                            ->label('Account Number')
                            ->required()
                            ->maxLength(50),
                        TextInput::make('account_holder_name')
                            ->label('Account Holder Name')
                            ->required()
                            ->maxLength(150),
                        Toggle::make('is_primary')
                            ->label('Primary Payout Account')
                            ->default(false),
                    ]),
            ]);
    }

    /**
     * Define the listing table schema for tenant bank accounts.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('bank_name')
                    ->label('Bank')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('bank_code')
                    ->label('Code')
                    ->badge(),
                TextColumn::make('account_number')
                    ->label('Account Number')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('account_holder_name')
                    ->label('Account Holder')
                    ->searchable(),
                IconColumn::make('is_primary')
                    ->label('Primary')
                    ->boolean(),
                IconColumn::make('is_verified')
                    ->label('Verified')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Register pages belonging to the TenantBankAccount resource.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListTenantBankAccounts::route('/'),
            'create' => CreateTenantBankAccount::route('/create'),
            'edit' => EditTenantBankAccount::route('/{record}/edit'),
        ];
    }
}
