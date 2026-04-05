<?php

namespace App\Filament\Resources\ViceMayorResource\Pages;

use App\Filament\Resources\ViceMayorResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListViceMayors extends ListRecords
{
    protected static string $resource = ViceMayorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
