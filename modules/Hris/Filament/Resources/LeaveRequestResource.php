<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources;

use App\Concerns\RequiresModule;
use App\Models\Company;
use App\Models\User;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Modules\Hris\Enums\LeaveStatus;
use Modules\Hris\Enums\LeaveType;
use Modules\Hris\Filament\Resources\LeaveRequestResource\Pages\ListLeaveRequests;
use Modules\Hris\Models\Employee;
use Modules\Hris\Models\LeaveRequest;
use Modules\Hris\Services\LeaveRequestService;
use UnitEnum;

/**
 * Tenant-scoped Filament resource for employee leave requests with approval and rejection actions.
 */
class LeaveRequestResource extends Resource
{
    use RequiresModule;

    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = LeaveRequest::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    /**
     * The navigation label displayed in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Cuti & Izin';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'HRIS & Payroll';

    /**
     * Sort order inside the HRIS & Payroll navigation group.
     */
    protected static ?int $navigationSort = 6;

    /**
     * Return the required module slug controlling access to this resource.
     */
    public static function requiredModuleSlug(): string
    {
        return 'hris';
    }

    /**
     * Enforce leave submission via LeaveRequestService header action.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Prevent raw editing outside LeaveRequestService approval workflows.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Prevent deleting leave audit history.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Define the leave request listing table schema, filters, and approval actions.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('employee.employee_code')
                    ->label('NIP')
                    ->badge()
                    ->searchable(),
                TextColumn::make('employee.full_name')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(static fn (LeaveType $state): string => match ($state) {
                        LeaveType::Annual => 'Cuti Tahunan',
                        LeaveType::Sick => 'Sakit',
                        LeaveType::Permission => 'Izin',
                        LeaveType::Unpaid => 'Unpaid Leave',
                    }),
                TextColumn::make('start_date')
                    ->label('Mulai')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('end_date')
                    ->label('Selesai')
                    ->date('d M Y'),
                TextColumn::make('total_days')
                    ->label('Hari')
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn (LeaveStatus $state): string => ucfirst($state->value))
                    ->color(static fn (LeaveStatus $state): string => match ($state) {
                        LeaveStatus::Pending => 'warning',
                        LeaveStatus::Approved => 'success',
                        LeaveStatus::Rejected => 'danger',
                    }),
                TextColumn::make('approver.name')
                    ->label('Diproses Oleh')
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(LeaveStatus::cases())->mapWithKeys(
                        static fn (LeaveStatus $status): array => [$status->value => ucfirst($status->value)],
                    )->all()),
                SelectFilter::make('type')
                    ->label('Jenis')
                    ->options([
                        LeaveType::Annual->value => 'Cuti Tahunan',
                        LeaveType::Sick->value => 'Sakit',
                        LeaveType::Permission->value => 'Izin',
                        LeaveType::Unpaid->value => 'Unpaid Leave',
                    ]),
                SelectFilter::make('employee_id')
                    ->label('Karyawan')
                    ->relationship('employee', 'full_name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                static::makeApproveAction(),
                static::makeRejectAction(),
            ]);
    }

    /**
     * Build the Header Action for submitting a new leave request via LeaveRequestService.
     */
    public static function makeSubmitLeaveAction(): Action
    {
        return Action::make('submitLeave')
            ->label('Ajukan Cuti / Izin')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->color('primary')
            ->schema([
                Select::make('employee_id')
                    ->label('Karyawan')
                    ->required()
                    ->searchable()
                    ->options(static function (): array {
                        $tenant = Filament::getTenant();

                        if (! $tenant instanceof Company) {
                            return [];
                        }

                        return Employee::query()
                            ->where('company_id', $tenant->getKey())
                            ->orderBy('full_name')
                            ->pluck('full_name', 'id')
                            ->all();
                    }),
                Select::make('type')
                    ->label('Jenis Cuti / Izin')
                    ->required()
                    ->options([
                        LeaveType::Annual->value => 'Cuti Tahunan',
                        LeaveType::Sick->value => 'Sakit',
                        LeaveType::Permission->value => 'Izin',
                        LeaveType::Unpaid->value => 'Unpaid Leave',
                    ]),
                DatePicker::make('start_date')
                    ->label('Tanggal Mulai')
                    ->required()
                    ->default(now()),
                DatePicker::make('end_date')
                    ->label('Tanggal Selesai')
                    ->required()
                    ->default(now()),
                Textarea::make('reason')
                    ->label('Alasan')
                    ->required()
                    ->rows(3),
            ])
            ->action(static function (array $data): void {
                $tenant = Filament::getTenant();

                if (! $tenant instanceof Company) {
                    return;
                }

                $employee = Employee::query()
                    ->where('company_id', $tenant->getKey())
                    ->findOrFail((int) $data['employee_id']);

                try {
                    app(LeaveRequestService::class)->submitLeave(
                        employee: $employee,
                        type: LeaveType::from((string) $data['type']),
                        startDate: (string) $data['start_date'],
                        endDate: (string) $data['end_date'],
                        reason: (string) $data['reason'],
                    );

                    Notification::make()
                        ->title('Pengajuan cuti/izin berhasil dikirim.')
                        ->success()
                        ->send();
                } catch (DomainException|InvalidArgumentException $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Build the Table Record Action for approving a pending leave request.
     */
    public static function makeApproveAction(): Action
    {
        return Action::make('approve')
            ->label('Setujui')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->visible(static fn (LeaveRequest $record): bool => $record->status === LeaveStatus::Pending)
            ->action(static function (LeaveRequest $record): void {
                /** @var User|null $approver */
                $approver = Auth::user();

                if (! $approver instanceof User) {
                    return;
                }

                try {
                    app(LeaveRequestService::class)->approveLeave($record, $approver);

                    Notification::make()
                        ->title('Pengajuan cuti disetujui dan absensi disinkronkan.')
                        ->success()
                        ->send();
                } catch (DomainException|InvalidArgumentException $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Build the Table Record Action for rejecting a pending leave request with reason.
     */
    public static function makeRejectAction(): Action
    {
        return Action::make('reject')
            ->label('Tolak')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(static fn (LeaveRequest $record): bool => $record->status === LeaveStatus::Pending)
            ->schema([
                Textarea::make('rejection_reason')
                    ->label('Alasan Penolakan')
                    ->required()
                    ->rows(3),
            ])
            ->action(static function (LeaveRequest $record, array $data): void {
                /** @var User|null $approver */
                $approver = Auth::user();

                if (! $approver instanceof User) {
                    return;
                }

                try {
                    app(LeaveRequestService::class)->rejectLeave(
                        $record,
                        $approver,
                        (string) $data['rejection_reason'],
                    );

                    Notification::make()
                        ->title('Pengajuan cuti ditolak.')
                        ->success()
                        ->send();
                } catch (DomainException|InvalidArgumentException $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Register the pages belonging to this resource.
     *
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListLeaveRequests::route('/'),
        ];
    }
}
