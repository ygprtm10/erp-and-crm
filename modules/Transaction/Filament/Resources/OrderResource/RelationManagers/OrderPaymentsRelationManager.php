<?php

declare(strict_types=1);

namespace Modules\Transaction\Filament\Resources\OrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Transaction\Enums\PaymentGatewayMode;
use Modules\Transaction\Enums\PaymentMethod;
use Modules\Transaction\Enums\PaymentTransactionStatus;

/**
 * Read-only relation manager displaying settled and pending payments for an order.
 */
class OrderPaymentsRelationManager extends RelationManager
{
    /**
     * The Eloquent relationship name on the parent Order model.
     */
    protected static string $relationship = 'payments';

    /**
     * The title displayed above the relation table.
     */
    protected static ?string $title = 'Riwayat Pembayaran';

    /**
     * Prevent creating payments directly outside OrderPaymentService.
     */
    public function canCreate(): bool
    {
        return false;
    }

    /**
     * Prevent editing immutable payment settlement rows.
     */
    public function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Prevent deleting payment settlement rows.
     */
    public function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Define the read-only order payments table schema.
     */
    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('paid_at')
                    ->label('Waktu Bayar')
                    ->dateTime()
                    ->placeholder('-'),
                TextColumn::make('payment_method')
                    ->label('Metode')
                    ->badge()
                    ->formatStateUsing(static fn (PaymentMethod $state): string => strtoupper($state->value)),
                TextColumn::make('gateway_mode')
                    ->label('Mode Gateway')
                    ->badge()
                    ->formatStateUsing(static fn (PaymentGatewayMode $state): string => $state->value),
                TextColumn::make('amount')
                    ->label('Nominal')
                    ->money('IDR'),
                TextColumn::make('net_amount')
                    ->label('Netto')
                    ->money('IDR'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn (PaymentTransactionStatus $state): string => ucfirst($state->value))
                    ->color(static fn (PaymentTransactionStatus $state): string => match ($state) {
                        PaymentTransactionStatus::Paid => 'success',
                        PaymentTransactionStatus::Pending => 'warning',
                        PaymentTransactionStatus::Failed => 'danger',
                        PaymentTransactionStatus::Refunded => 'gray',
                    }),
                TextColumn::make('gateway_reference')
                    ->label('Referensi')
                    ->placeholder('-'),
            ]);
    }
}
