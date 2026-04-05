<?php

namespace App\Filament\Resources\AylikFaaliyetResource\Pages;

use App\Filament\Resources\AylikFaaliyetResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewAylikFaaliyet extends ViewRecord
{
    protected static string $resource = AylikFaaliyetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn () => AylikFaaliyetResource::canEdit($this->getRecord())),
        ];
    }
}
