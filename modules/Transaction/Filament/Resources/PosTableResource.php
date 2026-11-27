<?php

declare(strict_types=1);

namespace Modules\Transaction\Filament\Resources;

use App\Concerns\RequiresModule;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use Modules\Core\Models\Branch;
use Modules\Transaction\Enums\PosTableStatus;
use Modules\Transaction\Filament\Resources\PosTableResource\Pages\CreatePosTable;
use Modules\Transaction\Filament\Resources\PosTableResource\Pages\EditPosTable;
use Modules\Transaction\Filament\Resources\PosTableResource\Pages\ListPosTables;
use Modules\Transaction\Models\PosTable;
use UnitEnum;

/**
 * Tenant-scoped outlet POS table resource gated by the qr_order module subscription.
 */
class PosTableResource extends Resource
{
    use RequiresModule;

    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = PosTable::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    /**
     * The navigation label displayed in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Meja & QR Order';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Transaksi & POS';

    /**
     * Return the required module slug controlling access to this resource.
     */
    public static function requiredModuleSlug(): string
    {
        return 'qr_order';
    }

    /**
     * Define the create/edit form schema with automatic QR token generation.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Konfigurasi Meja Outlet')
                    ->columns(2)
                    ->schema([
                        Select::make('branch_id')
                            ->label('Cabang')
                            ->required()
                            ->default(static fn (): ?int => Branch::query()->orderByDesc('is_main')->value('id'))
                            ->relationship('branch', 'name')
                            ->searchable()
                            ->preload(),
                        TextInput::make('table_number')
                            ->label('Nomor / Nama Meja')
                            ->required()
                            ->maxLength(32)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: static fn (Unique $rule, Get $get): Unique => $rule
                                    ->where('company_id', Filament::getTenant()?->getKey())
                                    ->where('branch_id', $get('branch_id')),
                            ),
                        TextInput::make('capacity')
                            ->label('Kapasitas Kursi')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->default(4)
                            ->required(),
                        Select::make('status')
                            ->label('Status Meja')
                            ->required()
                            ->default(PosTableStatus::Available->value)
                            ->options([
                                PosTableStatus::Available->value => 'Available',
                                PosTableStatus::Occupied->value => 'Occupied',
                            ]),
                        TextInput::make('qr_token')
                            ->label('Token QR Meja')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->default(static fn (): string => static::generateQrToken())
                            ->maxLength(64)
                            ->suffixAction(
                                Action::make('regenerateToken')
                                    ->icon(Heroicon::OutlinedArrowPath)
                                    ->tooltip('Generate token QR baru')
                                    ->action(static function (Set $set): void {
                                        $set('qr_token', static::generateQrToken());
                                    }),
                            )
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Define the listing table schema displaying copyable QR tokens and QR order URLs.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('table_number')
            ->columns([
                TextColumn::make('branch.name')
                    ->label('Cabang')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('table_number')
                    ->label('Meja')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('capacity')
                    ->label('Kapasitas')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn (PosTableStatus $state): string => ucfirst($state->value))
                    ->color(static fn (PosTableStatus $state): string => $state === PosTableStatus::Available ? 'success' : 'warning'),
                TextColumn::make('qr_token')
                    ->label('QR Token')
                    ->badge()
                    ->copyable()
                    ->searchable(),
                TextColumn::make('qr_url')
                    ->label('URL QR Order')
                    ->state(static fn (PosTable $record): string => url('/qr/'.$record->qr_token))
                    ->copyable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        PosTableStatus::Available->value => 'Available',
                        PosTableStatus::Occupied->value => 'Occupied',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * Register pages belonging to the PosTable resource.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPosTables::route('/'),
            'create' => CreatePosTable::route('/create'),
            'edit' => EditPosTable::route('/{record}/edit'),
        ];
    }

    /**
     * Generate a unique uppercase QR token prefixed with QR-.
     */
    private static function generateQrToken(): string
    {
        return 'QR-'.Str::upper(Str::random(12));
    }
}
