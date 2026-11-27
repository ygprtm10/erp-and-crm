<?php

declare(strict_types=1);

namespace Modules\Transaction\Filament\Resources;

use App\Concerns\RequiresModule;
use App\Models\Company;
use App\Models\User;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Modules\Core\Models\Branch;
use Modules\Transaction\Enums\PosShiftStatus;
use Modules\Transaction\Filament\Resources\PosShiftResource\Pages\ListPosShifts;
use Modules\Transaction\Models\PosShift;
use Modules\Transaction\Services\PosShiftService;
use UnitEnum;

/**
 * Tenant-scoped cashier shift resource gated by the pos module subscription.
 */
class PosShiftResource extends Resource
{
    use RequiresModule;

    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = PosShift::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    /**
     * The navigation label displayed in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Shift Kasir';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Transaksi & POS';

    /**
     * Return the required module slug controlling access to this resource.
     */
    public static function requiredModuleSlug(): string
    {
        return 'pos';
    }

    /**
     * Shifts must be opened exclusively via PosShiftService header actions.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Prevent direct editing of shift financial reconciliation records.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Prevent deleting shift audit records.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Define the listing table schema with cash variance column and Close Shift action.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('branch.name')
                    ->label('Cabang')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('Kasir')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('opened_at')
                    ->label('Dibuka')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('closed_at')
                    ->label('Ditutup')
                    ->dateTime()
                    ->placeholder('-'),
                TextColumn::make('opening_cash')
                    ->label('Modal Awal')
                    ->money('IDR'),
                TextColumn::make('expected_cash')
                    ->label('Kas Ekspektasi')
                    ->money('IDR'),
                TextColumn::make('actual_cash')
                    ->label('Kas Aktual')
                    ->money('IDR')
                    ->placeholder('-'),
                TextColumn::make('cash_variance')
                    ->label('Selisih Kas')
                    ->state(static function (PosShift $record): ?string {
                        if ($record->actual_cash === null) {
                            return null;
                        }

                        return bcsub((string) $record->actual_cash, (string) $record->expected_cash, 4);
                    })
                    ->money('IDR')
                    ->color(static function (?string $state): string {
                        if ($state === null) {
                            return 'gray';
                        }

                        return match (bccomp($state, '0.0000', 4)) {
                            0 => 'success',
                            1 => 'info',
                            default => 'danger',
                        };
                    })
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn (PosShiftStatus $state): string => ucfirst($state->value))
                    ->color(static fn (PosShiftStatus $state): string => $state === PosShiftStatus::Open ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        PosShiftStatus::Open->value => 'Open',
                        PosShiftStatus::Closed->value => 'Closed',
                    ]),
            ])
            ->recordActions([
                Action::make('closeShift')
                    ->label('Tutup Shift')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->color('warning')
                    ->visible(static fn (PosShift $record): bool => $record->status === PosShiftStatus::Open)
                    ->requiresConfirmation()
                    ->modalHeading('Tutup Shift Kasir')
                    ->modalDescription('Masukkan total uang tunai fisik di laci kasir untuk rekonsiliasi.')
                    ->schema([
                        TextInput::make('actual_cash')
                            ->label('Kas Aktual di Laci')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->prefix('IDR'),
                    ])
                    ->action(function (PosShift $record, array $data, Action $action): void {
                        try {
                            app(PosShiftService::class)->closeShift(
                                shift: $record,
                                actualCash: (string) $data['actual_cash'],
                            );

                            Notification::make()
                                ->title('Shift kasir berhasil ditutup.')
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
            ]);
    }

    /**
     * Build the Open Shift header action delegating to PosShiftService.
     */
    public static function makeOpenShiftHeaderAction(): Action
    {
        return Action::make('openShift')
            ->label('Buka Shift')
            ->icon(Heroicon::OutlinedPlay)
            ->color('primary')
            ->schema([
                Select::make('branch_id')
                    ->label('Cabang')
                    ->required()
                    ->default(static fn (): ?int => Branch::query()->orderByDesc('is_main')->value('id'))
                    ->options(static fn (): array => Branch::query()
                        ->orderByDesc('is_main')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
                TextInput::make('opening_cash')
                    ->label('Modal Kas Awal')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->default('0')
                    ->prefix('IDR'),
            ])
            ->action(function (array $data, Action $action): void {
                $tenant = Filament::getTenant();
                /** @var User|null $user */
                $user = Auth::user();
                $branch = Branch::query()->find((int) ($data['branch_id'] ?? 0));

                if (! $tenant instanceof Company || ! $user instanceof User || ! $branch instanceof Branch) {
                    Notification::make()
                        ->title('Cabang atau pengguna shift tidak valid.')
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                try {
                    app(PosShiftService::class)->openShift(
                        company: $tenant,
                        branch: $branch,
                        user: $user,
                        openingCash: (string) ($data['opening_cash'] ?? '0'),
                    );

                    Notification::make()
                        ->title('Shift kasir berhasil dibuka.')
                        ->success()
                        ->send();
                } catch (DomainException|InvalidArgumentException $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();

                    $action->halt();
                }
            });
    }

    /**
     * Register pages belonging to the PosShift resource.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPosShifts::route('/'),
        ];
    }
}
