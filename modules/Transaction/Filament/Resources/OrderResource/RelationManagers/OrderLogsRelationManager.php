<?php

declare(strict_types=1);

namespace Modules\Transaction\Filament\Resources\OrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only relation manager displaying immutable order audit and webhook logs on ViewOrder.
 */
class OrderLogsRelationManager extends RelationManager
{
    /**
     * The Eloquent relationship name on the parent Order model.
     */
    protected static string $relationship = 'logs';

    /**
     * The title displayed above the relation table.
     */
    protected static ?string $title = 'Log Aktivitas & Webhook';

    /**
     * Prevent creating audit log rows manually.
     */
    public function canCreate(): bool
    {
        return false;
    }

    /**
     * Prevent editing immutable audit log rows.
     */
    public function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Prevent deleting immutable audit log rows.
     */
    public function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Define the read-only order audit logs table schema.
     */
    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('event')
                    ->label('Event')
                    ->badge(),
                TextColumn::make('creator.name')
                    ->label('Aktor')
                    ->placeholder('System / Webhook'),
                TextColumn::make('payload')
                    ->label('Detail Payload')
                    ->formatStateUsing(static fn (mixed $state): string => is_array($state) ? (string) json_encode($state) : (string) $state)
                    ->wrap(),
            ]);
    }
}
