<?php

namespace App\Filament\Resources\RoutineWorkWindowResource\Pages;

use App\Filament\Resources\RoutineWorkWindowResource;
use Filament\Resources\Pages\EditRecord;

class EditRoutineWorkWindow extends EditRecord
{
    protected static string $resource = RoutineWorkWindowResource::class;

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Rutin işler dönem ayarı güncellendi.';
    }
}
