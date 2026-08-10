<?php

namespace App\Filament\Resources\ActivityCatalogResource\Pages;

use App\Filament\Resources\ActivityCatalogResource;
use App\Models\ActivityCatalog;
use App\Support\ActivityCatalogReportSync;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;

class EditActivityCatalog extends EditRecord
{
    protected static string $resource = ActivityCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('syncToReports')
                ->label('Raporlara yansıt')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->modalHeading(fn (): string => 'Raporlara yansıt: '.$this->getRecord()->faaliyet_kodu)
                ->modalDescription('Önce değişiklikleri görün, ardından onaylayıp uygulayın. Mevcut sayısal veriler korunur.')
                ->form(function (): array {
                    /** @var ActivityCatalog $record */
                    $record = $this->getRecord();
                    $preview = ActivityCatalogReportSync::previewForCatalog($record);

                    return [
                        Forms\Components\Placeholder::make('preview')
                            ->label('Önizleme')
                            ->content(new HtmlString(ActivityCatalogReportSync::previewToHtml($preview))),
                        Forms\Components\Checkbox::make('confirm')
                            ->label('Değişiklikleri raporlara uygulamayı onaylıyorum')
                            ->accepted()
                            ->required()
                            ->visible(fn (): bool => (int) ($preview['summary']['reports'] ?? 0) > 0),
                    ];
                })
                ->action(function (): void {
                    /** @var ActivityCatalog $record */
                    $record = $this->getRecord();
                    $stats = ActivityCatalogReportSync::applyForCatalog($record);
                    Notification::make()
                        ->title($stats['reports'] > 0 ? 'Raporlar güncellendi' : 'Uygulanacak değişiklik yok')
                        ->body($stats['reports'] > 0
                            ? sprintf('%d raporda %d satır uygulandı (%d alan).', $stats['reports'], $stats['rows'], $stats['change_fields'])
                            : 'Bu kayıt için raporlarda fark bulunamadı.')
                        ->color($stats['reports'] > 0 ? 'success' : 'gray')
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
