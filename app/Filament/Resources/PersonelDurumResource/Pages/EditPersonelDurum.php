<?php

namespace App\Filament\Resources\PersonelDurumResource\Pages;

use App\Filament\Resources\PersonelDurumResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPersonelDurum extends EditRecord
{
    protected static string $resource = PersonelDurumResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
