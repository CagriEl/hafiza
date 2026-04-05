<?php

namespace App\Filament\Resources\ViceMayorResource\Pages;

use App\Filament\Resources\ViceMayorResource; // Bu satır kritik
use Filament\Resources\Pages\EditRecord;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class EditViceMayor extends EditRecord
{
    // HATA BURADAN KAYNAKLANIYOR OLABİLİR:
    protected static string $resource = ViceMayorResource::class;

    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        $userUpdate = [
            'name' => $data['ad_soyad'],
            'email' => $data['email'],
        ];

        if (!empty($data['password'])) {
            $userUpdate['password'] = Hash::make($data['password']);
        }

        if ($record->user) {
            $record->user->update($userUpdate);
        } else {
            $user = User::create([
                'name' => $data['ad_soyad'],
                'email' => $data['email'],
                'password' => Hash::make($data['password'] ?? '12345678'),
            ]);
            $record->user_id = $user->id;
            $record->save();
        }

        $record->update([
            'ad_soyad' => $data['ad_soyad'],
            'unvan' => $data['unvan'],
        ]);

        return $record;
    }
}