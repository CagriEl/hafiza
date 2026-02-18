<?php

namespace App\Filament\Resources\AylikFaaliyetResource\Pages;

use App\Filament\Resources\AylikFaaliyetResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAylikFaaliyet extends EditRecord
{
    protected static string $resource = AylikFaaliyetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
