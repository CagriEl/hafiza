<?php

namespace App\Filament\Resources\ControlTeamAuditNoteResource\Pages;

use App\Filament\Concerns\ProvidesAnalizEkibiOrnekRaporSablonDownload;
use App\Filament\Resources\ControlTeamAuditNoteResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListControlTeamAuditNotes extends ListRecords
{
    use ProvidesAnalizEkibiOrnekRaporSablonDownload;

    protected static string $resource = ControlTeamAuditNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->analizEkibiOrnekRaporSablonDownloadAction(),
            Actions\CreateAction::make(),
        ];
    }
}
