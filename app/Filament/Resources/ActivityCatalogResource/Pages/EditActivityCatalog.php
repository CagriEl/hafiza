<?php

namespace App\Filament\Resources\ActivityCatalogResource\Pages;

use App\Filament\Concerns\SyncsCatalogToReports;
use App\Filament\Resources\ActivityCatalogResource;
use App\Services\ActivityCatalogSyncService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditActivityCatalog extends EditRecord
{
    use SyncsCatalogToReports;

    protected static string $resource = ActivityCatalogResource::class;

    public ?string $pendingDeletedCatalogCode = null;

    protected function getHeaderActions(): array
    {
        return [
            $this->catalogSyncToReportsAction(),
            Actions\DeleteAction::make()
                ->before(function (): void {
                    $this->pendingDeletedCatalogCode = (string) ($this->getCatalogRecordForSync()?->faaliyet_kodu ?? '');
                })
                ->after(function (): void {
                    $code = trim((string) ($this->pendingDeletedCatalogCode ?? ''));
                    if ($code !== '') {
                        app(ActivityCatalogSyncService::class)->removeAdminCatalogChange($code);
                    }
                }),
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
