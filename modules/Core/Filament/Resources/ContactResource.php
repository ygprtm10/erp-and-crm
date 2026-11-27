<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\Core\Filament\Resources\ContactResource\Pages\CreateContact;
use Modules\Core\Filament\Resources\ContactResource\Pages\EditContact;
use Modules\Core\Filament\Resources\ContactResource\Pages\ListContacts;
use Modules\Core\Filament\Resources\ContactResource\RelationManagers\InteractionsRelationManager;
use Modules\Core\Models\Contact;
use UnitEnum;

/**
 * Tenant-scoped CRM resource for managing unified customer, vendor, and member contacts.
 */
class ContactResource extends Resource
{
    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = Contact::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'CRM';

    /**
     * Define the create/edit form schema for Contact records.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Contact Profile')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->scopedUnique(ignoreRecord: true)
                            ->maxLength(32),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->email()
                            ->nullable()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->tel()
                            ->nullable()
                            ->maxLength(30),
                        Textarea::make('address')
                            ->nullable()
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
                Section::make('CRM Classification & Loyalty')
                    ->columns(3)
                    ->schema([
                        Toggle::make('is_customer')
                            ->label('Customer')
                            ->default(true),
                        Toggle::make('is_vendor')
                            ->label('Vendor')
                            ->default(false),
                        Toggle::make('is_member')
                            ->label('Member')
                            ->default(false),
                        Select::make('lead_stage')
                            ->nullable()
                            ->options([
                                'new' => 'New Lead',
                                'contacted' => 'Contacted',
                                'qualified' => 'Qualified',
                                'won' => 'Won',
                                'lost' => 'Lost',
                            ]),
                        TextInput::make('points_balance')
                            ->numeric()
                            ->default('0.00')
                            ->minValue(0),
                    ]),
            ]);
    }

    /**
     * Define the listing table schema and filters for Contact records.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->searchable(),
                IconColumn::make('is_customer')
                    ->label('Customer')
                    ->boolean(),
                IconColumn::make('is_vendor')
                    ->label('Vendor')
                    ->boolean(),
                IconColumn::make('is_member')
                    ->label('Member')
                    ->boolean(),
                TextColumn::make('lead_stage')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('points_balance')
                    ->numeric(2)
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_customer')->label('Customer'),
                TernaryFilter::make('is_vendor')->label('Vendor'),
                TernaryFilter::make('is_member')->label('Member'),
                SelectFilter::make('lead_stage')
                    ->options([
                        'new' => 'New Lead',
                        'contacted' => 'Contacted',
                        'qualified' => 'Qualified',
                        'won' => 'Won',
                        'lost' => 'Lost',
                    ]),
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
     * Register relation managers for CRM contact follow-ups.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            InteractionsRelationManager::class,
        ];
    }

    /**
     * Register pages belonging to the Contact resource.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListContacts::route('/'),
            'create' => CreateContact::route('/create'),
            'edit' => EditContact::route('/{record}/edit'),
        ];
    }
}
