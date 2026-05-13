<?php

namespace App\Filament\Resources\RoutineWorkItemResource\Pages;

use App\Filament\Resources\RoutineWorkItemResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRoutineWorkItems extends ListRecords
{
    protected static string $resource = RoutineWorkItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Rutin İş Ekle'),
        ];
    }
}
