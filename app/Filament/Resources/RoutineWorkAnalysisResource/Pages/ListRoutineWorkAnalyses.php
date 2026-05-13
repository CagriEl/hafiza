<?php

namespace App\Filament\Resources\RoutineWorkAnalysisResource\Pages;

use App\Filament\Resources\RoutineWorkAnalysisResource;
use Filament\Resources\Pages\ListRecords;

class ListRoutineWorkAnalyses extends ListRecords
{
    protected static string $resource = RoutineWorkAnalysisResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
