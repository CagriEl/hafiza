<?php

namespace App\Filament\Resources\RoutineWorkWindowResource\Pages;

use App\Filament\Resources\RoutineWorkWindowResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRoutineWorkWindow extends CreateRecord
{
    protected static string $resource = RoutineWorkWindowResource::class;

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Rutin işler dönem ayarı oluşturuldu.';
    }
}
