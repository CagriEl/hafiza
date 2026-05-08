<?php

namespace App\Filament\Resources\ExtraordinarySituationResource\Pages;

use App\Filament\Resources\ExtraordinarySituationResource;
use Filament\Resources\Pages\ListRecords;

class ListExtraordinarySituations extends ListRecords
{
    protected static string $resource = ExtraordinarySituationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
