<?php

declare(strict_types=1);

namespace Modules\Accounting\Filament\Resources\JournalResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Filament\Resources\JournalResource;

/**
 * Handles creation of a balanced draft journal for the active tenant company.
 */
class CreateJournal extends CreateRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = JournalResource::class;

    /**
     * Force draft status and record the creator user ID before saving.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = JournalStatus::Draft->value;
        $data['created_by'] = Auth::id();

        return $data;
    }
}
