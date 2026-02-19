<?php

namespace App\Filament\Resources\GecikmeRaporuResource\Pages;

use App\Filament\Resources\GecikmeRaporuResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGecikmeRaporu extends EditRecord
{
    protected static string $resource = GecikmeRaporuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
