<?php

namespace App\Filament\Resources\RoutineWorkAnalysisResource\Pages;

use App\Filament\Resources\RoutineWorkAnalysisResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewRoutineWorkAnalysis extends ViewRecord
{
    protected static string $resource = RoutineWorkAnalysisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('geri')
                ->label('Listeye Dön')
                ->url(static::getResource()::getUrl('index'))
                ->color('gray'),
        ];
    }
}
