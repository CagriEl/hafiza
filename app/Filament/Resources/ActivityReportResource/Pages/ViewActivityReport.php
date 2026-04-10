<?php

namespace App\Filament\Resources\ActivityReportResource\Pages;

use App\Filament\Resources\ActivityReportResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewActivityReport extends ViewRecord
{
    protected static string $resource = ActivityReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn () => ActivityReportResource::canEdit($this->getRecord())),
        ];
    }
}
