<?php

namespace App\Filament\Resources\ActivityCatalogResource\Pages;

use App\Filament\Pages\ActivityCatalogRaporSync;
use App\Filament\Resources\ActivityCatalogResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListActivityCatalogs extends ListRecords
{
    protected static string $resource = ActivityCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('openSyncPage')
                ->label('Katalog → Rapor Sync')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->url(ActivityCatalogRaporSync::getUrl()),
            Actions\CreateAction::make(),
        ];
    }
}
