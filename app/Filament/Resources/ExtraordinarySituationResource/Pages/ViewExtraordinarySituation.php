<?php

namespace App\Filament\Resources\ExtraordinarySituationResource\Pages;

use App\Filament\Resources\ExtraordinarySituationResource;
use Filament\Resources\Pages\ViewRecord;

class ViewExtraordinarySituation extends ViewRecord
{
    protected static string $resource = ExtraordinarySituationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
