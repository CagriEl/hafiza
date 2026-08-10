<?php

namespace App\Filament\Concerns;

use App\Models\ActivityCatalog;
use App\Support\ActivityCatalogReportSync;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

trait SyncsCatalogToReports
{
    protected function catalogSyncToReportsAction(string $name = 'syncToReports'): Action
    {
        return Action::make($name)
            ->label('Raporlara yansıt')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->modalHeading(function (): string {
                $record = $this->getCatalogRecordForSync();

                return 'Raporlara kalıcı yansıt: '.($record?->faaliyet_kodu ?? '');
            })
            ->modalDescription('Önizlemeyi kontrol edin, ardından onaylayın. Bu işlem bu faaliyet koduna ait TÜM raporları kalıcı günceller; her raporu tek tek açmanız gerekmez. Sayısal veriler korunur.')
            ->modalSubmitActionLabel('Kalıcı uygula')
            ->form(function (): array {
                $record = $this->getCatalogRecordForSync();
                if (! $record instanceof ActivityCatalog) {
                    return [
                        Forms\Components\Placeholder::make('empty')
                            ->content('Katalog kaydı bulunamadı.'),
                    ];
                }

                $preview = ActivityCatalogReportSync::previewForCatalog($record);

                return [
                    Forms\Components\Placeholder::make('preview')
                        ->label('Önizleme (uygulamadan önce)')
                        ->content(new HtmlString(ActivityCatalogReportSync::previewToHtml($preview))),
                    Forms\Components\Checkbox::make('confirm')
                        ->label('Tüm ilgili raporlara kalıcı uygulamayı onaylıyorum')
                        ->accepted()
                        ->required()
                        ->visible(fn (): bool => (int) ($preview['summary']['reports'] ?? 0) > 0),
                ];
            })
            ->action(function (): void {
                $record = $this->getCatalogRecordForSync();
                if (! $record instanceof ActivityCatalog) {
                    return;
                }

                $stats = ActivityCatalogReportSync::applyForCatalog($record);
                Notification::make()
                    ->title($stats['reports'] > 0 ? 'Raporlara kalıcı uygulandı' : 'Uygulanacak değişiklik yok')
                    ->body($stats['reports'] > 0
                        ? sprintf(
                            '%d raporda %d satır kalıcı güncellendi (%d alan). Bundan sonra her raporu tek tek değiştirmeniz gerekmez.',
                            $stats['reports'],
                            $stats['rows'],
                            $stats['change_fields']
                        )
                        : 'Bu kayıt için raporlarda fark bulunamadı.')
                    ->color($stats['reports'] > 0 ? 'success' : 'gray')
                    ->persistent()
                    ->send();
            });
    }

    protected function offerCatalogReportSyncAfterSave(): void
    {
        $record = $this->getCatalogRecordForSync();
        if (! $record instanceof ActivityCatalog) {
            return;
        }

        $preview = ActivityCatalogReportSync::previewForCatalog($record);
        $count = (int) ($preview['summary']['reports'] ?? 0);

        if ($count <= 0) {
            Notification::make()
                ->title('Katalog kaydedildi')
                ->body('Mevcut raporlarla uyumlu; ek yansıtma gerekmiyor.')
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Katalog kaydedildi')
            ->body("{$count} raporda kalıcı yansıtılacak değişiklik var. Açılan pencerede önizleyip uygulayın; her raporu tek tek açmanız gerekmez.")
            ->warning()
            ->persistent()
            ->send();

        $this->mountAction('syncToReports');
    }

    protected function getCatalogRecordForSync(): ?ActivityCatalog
    {
        $record = $this->getRecord();

        return $record instanceof ActivityCatalog ? $record : null;
    }
}
