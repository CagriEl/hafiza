<?php

namespace App\Filament\Resources\SwotAnalizResource\Pages;

use App\Filament\Resources\SwotAnalizResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateSwotAnaliz extends CreateRecord
{
    protected static string $resource = SwotAnalizResource::class;

    // Form verisi veritabanına gitmeden önce araya giriyoruz
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id(); // Giriş yapan müdürlüğün ID'si
        return $data;
    }
}