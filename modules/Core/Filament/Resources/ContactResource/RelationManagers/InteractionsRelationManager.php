<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\ContactResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Enums\InteractionChannel;

/**
 * Manages CRM follow-up interactions logged against a contact record.
 */
class InteractionsRelationManager extends RelationManager
{
    /**
     * The Eloquent relationship name on the parent Contact model.
     */
    protected static string $relationship = 'interactions';

    /**
     * Define the create/edit form schema for CRM interaction logs.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('channel')
                    ->required()
                    ->options([
                        InteractionChannel::Wa->value => 'WhatsApp',
                        InteractionChannel::Call->value => 'Phone Call',
                        InteractionChannel::Visit->value => 'Visit',
                        InteractionChannel::Email->value => 'Email',
                    ]),
                DateTimePicker::make('next_follow_up_at')
                    ->label('Next Follow-Up')
                    ->nullable(),
                Textarea::make('notes')
                    ->required()
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Define the listing table schema for CRM interactions.
     */
    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('channel')
                    ->badge()
                    ->formatStateUsing(static fn (InteractionChannel $state): string => strtoupper($state->value)),
                TextColumn::make('user.name')
                    ->label('Staff')
                    ->placeholder('-'),
                TextColumn::make('notes')
                    ->wrap()
                    ->limit(80),
                TextColumn::make('next_follow_up_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(static function (array $data): array {
                        $data['user_id'] = Auth::id();

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
