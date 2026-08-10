<?php

namespace App\Filament\Resources\ActivityCatalogResource\Pages;

use App\Filament\Concerns\SyncsCatalogToReports;
use App\Filament\Resources\ActivityCatalogResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditActivityCatalog extends EditRecord
{
    use SyncsCatalogToReports;

    protected static string $resource = ActivityCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->catalogSyncToReportsAction(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $this->offerCatalogReportSyncAfterSave();
    }

    protected function getSavedNotification(): ?\Filament\Notifications\Notification
    {
        // afterSave içinde özel bildirim gösteriyoruz.
        return null;
    }
}
