<?php

namespace App\Filament\Resources\AnalizEkibiResource\Pages;

use App\Filament\Resources\AnalizEkibiResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateAnalizEkibi extends CreateRecord
{
    protected static string $resource = AnalizEkibiResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['role'] = User::ROLE_ANALIZ_EKIBI;
        $data['include_in_performance_charts'] = false;
        $data['vice_mayor_id'] = null;

        return $data;
    }
}
