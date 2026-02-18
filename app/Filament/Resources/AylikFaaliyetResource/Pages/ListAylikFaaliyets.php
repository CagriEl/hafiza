<?php

namespace App\Filament\Resources\AylikFaaliyetResource\Pages;

use App\Filament\Resources\AylikFaaliyetResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAylikFaaliyets extends ListRecords
{
    protected static string $resource = AylikFaaliyetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
