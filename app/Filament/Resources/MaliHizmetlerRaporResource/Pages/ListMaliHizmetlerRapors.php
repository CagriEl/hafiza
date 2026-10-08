<?php

namespace App\Filament\Resources\MaliHizmetlerRaporResource\Pages;

use App\Filament\Resources\MaliHizmetlerRaporResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMaliHizmetlerRapors extends ListRecords
{
    protected static string $resource = MaliHizmetlerRaporResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Yeni Haftalık Mali Rapor')
                ->visible(fn (): bool => MaliHizmetlerRaporResource::canCreate()),
        ];
    }
}
