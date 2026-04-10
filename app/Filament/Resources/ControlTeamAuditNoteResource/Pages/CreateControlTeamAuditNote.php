<?php

namespace App\Filament\Resources\ControlTeamAuditNoteResource\Pages;

use App\Filament\Resources\ControlTeamAuditNoteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateControlTeamAuditNote extends CreateRecord
{
    protected static string $resource = ControlTeamAuditNoteResource::class;

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }
}
