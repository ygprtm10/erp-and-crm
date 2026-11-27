<?php

declare(strict_types=1);

namespace Modules\Transaction\Filament\Resources\PosTableResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\Transaction\Filament\Resources\PosTableResource;

/**
 * Edits an existing outlet table configuration.
 */
class EditPosTable extends EditRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = PosTableResource::class;
}
