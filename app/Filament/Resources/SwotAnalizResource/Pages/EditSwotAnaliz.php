<?php

namespace App\Filament\Resources\SwotAnalizResource\Pages;

use App\Filament\Resources\SwotAnalizResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSwotAnaliz extends EditRecord
{
    protected static string $resource = SwotAnalizResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
