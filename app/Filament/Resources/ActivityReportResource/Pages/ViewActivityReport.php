<?php

namespace App\Filament\Resources\ActivityReportResource\Pages;

use App\Filament\Resources\ActivityReportResource;
use App\Filament\Resources\AylikFaaliyetResource;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewActivityReport extends ViewRecord
{
    protected static string $resource = ActivityReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdfIndir')
                ->label('PDF İndir')
                ->color('success')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function () {
                    $record = $this->getRecord();
                    $pdf = Pdf::loadHTML(AylikFaaliyetResource::reportPdfHtml($record))
                        ->setPaper('a4', 'portrait')
                        ->setWarnings(false);
                    $fileName = 'faaliyet_raporu_'
                        .(string) ($record->yil ?? now()->year).'_'
                        .str_pad((string) ($record->ay ?? now()->format('m')), 2, '0', STR_PAD_LEFT)
                        .'_'.now()->format('d_m_Y').'.pdf';

                    return response()->streamDownload(function () use ($pdf) {
                        echo $pdf->output();
                    }, $fileName);
                }),
            Actions\EditAction::make()
                ->visible(fn () => ActivityReportResource::canEdit($this->getRecord())),
        ];
    }
}
