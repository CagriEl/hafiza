<?php

namespace App\Filament\Resources\AnalizEkibiResource\Pages;

use App\Filament\Resources\AnalizEkibiResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAnalizEkibis extends ListRecords
{
    protected static string $resource = AnalizEkibiResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
