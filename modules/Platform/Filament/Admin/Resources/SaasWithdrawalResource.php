<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Admin\Resources;

use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Modules\Platform\Enums\WithdrawalStatus;
use Modules\Platform\Filament\Admin\Resources\SaasWithdrawalResource\Pages\ListSaasWithdrawals;
use Modules\Platform\Filament\Admin\Resources\SaasWithdrawalResource\Pages\ViewSaasWithdrawal;
use Modules\Platform\Models\SaasWithdrawal;
use Modules\Platform\Services\WalletLedgerService;
use Throwable;
use UnitEnum;

/**
 * Super Admin resource for reviewing, completing, or rejecting tenant withdrawal requests.
 */
class SaasWithdrawalResource extends Resource
{
    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = SaasWithdrawal::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    /**
     * The navigation group in the Super Admin sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Escrow & Payouts';

    /**
     * Prevent arbitrary withdrawal creation from the Super Admin panel.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Define the read-only detail infolist schema for a withdrawal request.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Withdrawal Summary')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('withdrawal_number')
                            ->label('Withdrawal Number'),
                        TextEntry::make('company.name')
                            ->label('Tenant Company'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(static fn (WithdrawalStatus $state): string => $state->label())
                            ->color(static fn (WithdrawalStatus $state): string => $state->color()),
                        TextEntry::make('amount')
                            ->money('IDR'),
                        TextEntry::make('admin_fee')
                            ->money('IDR'),
                        TextEntry::make('net_transfer_amount')
                            ->money('IDR'),
                        TextEntry::make('bank_snapshot.bank_name')
                            ->label('Destination Bank'),
                        TextEntry::make('bank_snapshot.account_number')
                            ->label('Account Number'),
                        TextEntry::make('bank_snapshot.account_holder_name')
                            ->label('Account Holder'),
                        TextEntry::make('rejection_reason')
                            ->visible(static fn (SaasWithdrawal $record): bool => filled($record->rejection_reason)),
                    ]),
            ]);
    }

    /**
     * Define the listing table schema and approval actions for withdrawal requests.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('withdrawal_number')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('company.name')
                    ->label('Company')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('amount')
                    ->money('IDR')
                    ->sortable(),
                TextColumn::make('admin_fee')
                    ->money('IDR'),
                TextColumn::make('net_transfer_amount')
                    ->money('IDR')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(static fn (WithdrawalStatus $state): string => $state->label())
                    ->color(static fn (WithdrawalStatus $state): string => $state->color()),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        WithdrawalStatus::Pending->value => WithdrawalStatus::Pending->label(),
                        WithdrawalStatus::Approved->value => WithdrawalStatus::Approved->label(),
                        WithdrawalStatus::Processing->value => WithdrawalStatus::Processing->label(),
                        WithdrawalStatus::Completed->value => WithdrawalStatus::Completed->label(),
                        WithdrawalStatus::Rejected->value => WithdrawalStatus::Rejected->label(),
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                ...static::getApprovalActions(),
            ]);
    }

    /**
     * Build shared Complete and Reject Filament actions delegating to WalletLedgerService.
     *
     * @return array<int, Action>
     */
    public static function getApprovalActions(): array
    {
        return [
            Action::make('complete')
                ->label('Complete Payout')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(static fn (SaasWithdrawal $record): bool => static::isActionable($record))
                ->requiresConfirmation()
                ->schema([
                    FileUpload::make('transfer_proof_path')
                        ->label('Transfer Proof')
                        ->directory('withdrawal-proofs')
                        ->nullable(),
                    Textarea::make('notes')
                        ->label('Settlement Notes')
                        ->rows(3),
                ])
                ->action(function (SaasWithdrawal $record, array $data): void {
                    /** @var User $processor */
                    $processor = Auth::user();

                    try {
                        app(WalletLedgerService::class)->completeWithdrawal(
                            withdrawal: $record,
                            processor: $processor,
                            transferProofPath: $data['transfer_proof_path'] ?? null,
                            notes: $data['notes'] ?? null,
                        );

                        Notification::make()
                            ->title('Withdrawal completed and wallet debited.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('reject')
                ->label('Reject')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(static fn (SaasWithdrawal $record): bool => static::isActionable($record))
                ->requiresConfirmation()
                ->schema([
                    Textarea::make('rejection_reason')
                        ->label('Rejection Reason')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (SaasWithdrawal $record, array $data): void {
                    /** @var User $processor */
                    $processor = Auth::user();

                    try {
                        app(WalletLedgerService::class)->rejectWithdrawal(
                            withdrawal: $record,
                            processor: $processor,
                            rejectionReason: (string) $data['rejection_reason'],
                        );

                        Notification::make()
                            ->title('Withdrawal rejected and funds returned to available balance.')
                            ->warning()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    /**
     * Determine whether a withdrawal request can still be completed or rejected.
     */
    private static function isActionable(SaasWithdrawal $record): bool
    {
        return in_array($record->status, [
            WithdrawalStatus::Pending,
            WithdrawalStatus::Approved,
            WithdrawalStatus::Processing,
        ], true);
    }

    /**
     * Register pages belonging to the SaasWithdrawal resource.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSaasWithdrawals::route('/'),
            'view' => ViewSaasWithdrawal::route('/{record}'),
        ];
    }
}
