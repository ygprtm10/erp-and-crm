<?php

declare(strict_types=1);

namespace Modules\Accounting\Filament\Resources\JournalResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Modules\Accounting\Filament\Resources\JournalResource;

/**
 * Displays a read-only general ledger journal with Post and Void workflow actions.
 */
class ViewJournal extends ViewRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = JournalResource::class;

    /**
     * Expose Post Jurnal and Void Jurnal workflow actions in the view page header.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return JournalResource::getWorkflowActions();
    }
}
