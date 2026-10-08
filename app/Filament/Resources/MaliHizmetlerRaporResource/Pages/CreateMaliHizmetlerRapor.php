<?php

namespace App\Filament\Resources\MaliHizmetlerRaporResource\Pages;

use App\Filament\Resources\MaliHizmetlerRaporResource;
use App\Models\MaliHizmetlerRapor;
use App\Models\User;
use App\Support\MaliHizmetlerPeriod;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateMaliHizmetlerRapor extends CreateRecord
{
    protected static string $resource = MaliHizmetlerRaporResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        if ($user instanceof User && $user->isMaliHizmetlerAccount() && ! $user->isReportingSuperAdmin()) {
            $data['user_id'] = $user->id;
            $period = MaliHizmetlerPeriod::currentWeekAttributes();
            $data['yil'] = $period['yil'];
            $data['ay'] = $period['ay'];
            $data['hafta'] = $period['hafta'];
        }

        $userId = (int) ($data['user_id'] ?? 0);
        $yil = (int) ($data['yil'] ?? 0);
        $ay = str_pad(trim((string) ($data['ay'] ?? '')), 2, '0', STR_PAD_LEFT);
        $hafta = (int) ($data['hafta'] ?? 0);

        if ($userId > 0 && $yil > 0 && $ay !== '' && $hafta >= 1) {
            $exists = MaliHizmetlerRapor::query()
                ->where('user_id', $userId)
                ->where('yil', $yil)
                ->where('ay', $ay)
                ->where('hafta', $hafta)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'hafta' => 'Bu dönem için rapor zaten mevcut. Listeden düzenleyebilirsiniz.',
                ]);
            }
        }

        return MaliHizmetlerPeriod::normalizeOdemeTalepleri($data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
