<?php

declare(strict_types=1);

namespace Modules\Accounting\Filament\Resources\JournalResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Accounting\Filament\Resources\JournalResource;

/**
 * Handles editing of a draft general ledger journal entry.
 */
class EditJournal extends EditRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = JournalResource::class;

    /**
     * Define header actions shown on the edit page.
     *
     * @return array<int, DeleteAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => JournalResource::canDelete($this->getRecord())),
        ];
    }
}
