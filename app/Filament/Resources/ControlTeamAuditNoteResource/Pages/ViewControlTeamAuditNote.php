<?php

namespace App\Filament\Resources\ControlTeamAuditNoteResource\Pages;

use App\Filament\Resources\ControlTeamAuditNoteResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewControlTeamAuditNote extends ViewRecord
{
    protected static string $resource = ControlTeamAuditNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('geri')
                ->label('Listeye Dön')
                ->url(static::getResource()::getUrl('index'))
                ->color('gray'),
        ];
    }
}
