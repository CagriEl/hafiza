<?php

namespace App\Filament\Resources\RoutineWorkWindowResource\Pages;

use App\Filament\Resources\RoutineWorkWindowResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRoutineWorkWindows extends ListRecords
{
    protected static string $resource = RoutineWorkWindowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Dönem Ekle'),
        ];
    }
}
