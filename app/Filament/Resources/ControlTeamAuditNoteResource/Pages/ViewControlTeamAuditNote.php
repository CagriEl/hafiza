<?php

namespace App\Filament\Resources\ControlTeamAuditNoteResource\Pages;

use App\Filament\Concerns\ProvidesAnalizEkibiOrnekRaporSablonDownload;
use App\Filament\Resources\ControlTeamAuditNoteResource;
use App\Support\AnalizEkibiHaftalikFaaliyetPdf;
use App\Support\AnalizEkibiOrnekRaporExcel;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewControlTeamAuditNote extends ViewRecord
{
    use ProvidesAnalizEkibiOrnekRaporSablonDownload;

    protected static string $resource = ControlTeamAuditNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('pdfIndir')
                ->label('PDF İndir')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(fn () => AnalizEkibiHaftalikFaaliyetPdf::downloadForNote($this->getRecord())),
            Actions\Action::make('excelIndir')
                ->label('Excel İndir')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(fn () => AnalizEkibiOrnekRaporExcel::exportNoteDownloadResponse($this->getRecord())),
            Actions\Action::make('geri')
                ->label('Listeye Dön')
                ->url(static::getResource()::getUrl('index'))
                ->color('gray'),
        ];
    }
}
