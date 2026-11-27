<?php

declare(strict_types=1);

namespace Modules\Transaction\Filament\Resources\PosTableResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Transaction\Filament\Resources\PosTableResource;

/**
 * Creates a new outlet table with an automatically generated QR token.
 */
class CreatePosTable extends CreateRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = PosTableResource::class;
}
