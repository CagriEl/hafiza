<?php

namespace App\Filament\Resources\SwotAnalizResource\Pages;

use App\Filament\Resources\SwotAnalizResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSwotAnalizs extends ListRecords
{
    protected static string $resource = SwotAnalizResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
