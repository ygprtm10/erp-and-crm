<?php

declare(strict_types=1);

namespace Modules\Accounting\Filament\Resources\ExpenseResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Accounting\Enums\ExpenseStatus;
use Modules\Accounting\Filament\Resources\ExpenseResource;

/**
 * Handles creation of an unpaid operational expense for the active tenant company.
 */
class CreateExpense extends CreateRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = ExpenseResource::class;

    /**
     * Force initial expense status to unpaid on creation.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = ExpenseStatus::Unpaid->value;

        return $data;
    }
}
