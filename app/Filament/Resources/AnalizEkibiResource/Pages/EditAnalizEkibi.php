<?php

namespace App\Filament\Resources\AnalizEkibiResource\Pages;

use App\Filament\Resources\AnalizEkibiResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAnalizEkibi extends EditRecord
{
    protected static string $resource = AnalizEkibiResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['role'] = User::ROLE_ANALIZ_EKIBI;
        $data['include_in_performance_charts'] = false;
        $data['vice_mayor_id'] = null;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
