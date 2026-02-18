<?php

namespace App\Filament\Resources\HaftalikRaporResource\Pages;

use App\Filament\Resources\HaftalikRaporResource;
use Filament\Resources\Pages\CreateRecord;

class CreateHaftalikRapor extends CreateRecord
{
    protected static string $resource = HaftalikRaporResource::class;

    // Veritabanına kaydetmeden önce müdürlük ID'sini ekle
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        return $data;
    }
}