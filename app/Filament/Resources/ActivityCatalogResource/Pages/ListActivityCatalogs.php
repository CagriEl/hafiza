<?php

namespace App\Filament\Resources\ActivityCatalogResource\Pages;

use App\Filament\Resources\ActivityCatalogResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListActivityCatalogs extends ListRecords
{
    protected static string $resource = ActivityCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
