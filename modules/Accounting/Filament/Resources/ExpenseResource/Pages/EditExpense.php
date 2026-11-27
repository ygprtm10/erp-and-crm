<?php

declare(strict_types=1);

namespace Modules\Accounting\Filament\Resources\ExpenseResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Accounting\Filament\Resources\ExpenseResource;

/**
 * Handles editing of an unpaid operational expense record.
 */
class EditExpense extends EditRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = ExpenseResource::class;

    /**
     * Define header actions shown on the edit page.
     *
     * @return array<int, DeleteAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => ExpenseResource::canDelete($this->getRecord())),
        ];
    }
}
