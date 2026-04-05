<?php

namespace App\Filament\Resources\ViceMayorResource\Pages;

use App\Filament\Resources\ViceMayorResource; // Bu satır kritik
use Filament\Resources\Pages\CreateRecord;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class CreateViceMayor extends CreateRecord
{
    // HATA BURADAN KAYNAKLANIYOR OLABİLİR:
    protected static string $resource = ViceMayorResource::class;

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        // Önce User oluştur
        $user = User::create([
            'name' => $data['ad_soyad'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        // Sonra ViceMayor oluştur
        return static::getModel()::create([
            'ad_soyad' => $data['ad_soyad'],
            'unvan' => $data['unvan'],
            'user_id' => $user->id,
        ]);
    }
}