<?php

namespace App\Filament\Resources\HaftalikRaporResource\Pages;

use App\Filament\Resources\HaftalikRaporResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditHaftalikRapor extends EditRecord
{
    protected static string $resource = HaftalikRaporResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
