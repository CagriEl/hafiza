<?php

namespace App\Filament\Resources\MaliHizmetlerRaporResource\Pages;

use App\Filament\Resources\MaliHizmetlerRaporResource;
use App\Support\MaliHizmetlerPeriod;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMaliHizmetlerRapor extends EditRecord
{
    protected static string $resource = MaliHizmetlerRaporResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (): bool => MaliHizmetlerRaporResource::canDelete($this->getRecord())),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return MaliHizmetlerPeriod::normalizeOdemeTalepleri($data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
