<?php

namespace App\Filament\Resources\ActivityCatalogResource\Pages;

use App\Filament\Resources\ActivityCatalogResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditActivityCatalog extends EditRecord
{
    protected static string $resource = ActivityCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
