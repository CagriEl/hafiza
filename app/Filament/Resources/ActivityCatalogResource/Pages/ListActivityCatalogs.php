<?php

namespace App\Filament\Resources\ActivityCatalogResource\Pages;

use App\Filament\Pages\ActivityCatalogRaporSync;
use App\Filament\Resources\ActivityCatalogResource;
use App\Support\CatalogKalemRevisions;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListActivityCatalogs extends ListRecords
{
    protected static string $resource = ActivityCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('applyKalemRevisions')
                ->label('Kalem revizyonlarını şimdi uygula')
                ->icon('heroicon-o-bolt')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('ULM / SKM / MKM kalem revizyonları')
                ->modalDescription('Katalog + tüm ilgili raporlar güncellenir. Sayısal veriler korunur. Yeni rapor giriş ekranlarına da yansır.')
                ->modalSubmitActionLabel('Şimdi uygula')
                ->action(function (): void {
                    CatalogKalemRevisions::resetEnsureState();
                    $stats = CatalogKalemRevisions::apply(false);
                    \Illuminate\Support\Facades\Cache::forever(
                        'catalog_kalem_revisions_applied',
                        CatalogKalemRevisions::VERSION
                    );

                    Notification::make()
                        ->title('Kalem revizyonları uygulandı')
                        ->body(sprintf(
                            'Katalog: %d · Rapor taşıma: %d · Rapor sync: %d · Ulaşım 3. hafta kapanan: %d',
                            $stats['catalogs'],
                            $stats['reports_renamed'],
                            $stats['reports_synced'],
                            $stats['ulasim_week3_closed']
                        ))
                        ->success()
                        ->persistent()
                        ->send();
                }),
            Actions\Action::make('openSyncPage')
                ->label('Katalog → Rapor Sync')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->url(ActivityCatalogRaporSync::getUrl()),
            Actions\CreateAction::make(),
        ];
    }
}
