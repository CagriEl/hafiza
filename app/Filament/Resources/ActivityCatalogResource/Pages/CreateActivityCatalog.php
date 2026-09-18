<?php

namespace App\Filament\Resources\ActivityCatalogResource\Pages;

use App\Filament\Concerns\SyncsCatalogToReports;
use App\Filament\Resources\ActivityCatalogResource;
use Filament\Resources\Pages\CreateRecord;

class CreateActivityCatalog extends CreateRecord
{
    use SyncsCatalogToReports;

    protected static string $resource = ActivityCatalogResource::class;

    protected function afterCreate(): void
    {
        $this->offerCatalogReportSyncAfterSave();
    }

    protected function getCreatedNotification(): ?\Filament\Notifications\Notification
    {
        return null;
    }
}
