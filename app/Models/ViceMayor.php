<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class ViceMayor extends Model
{
    protected $fillable = ['ad_soyad', 'unvan', 'user_id'];

    public function user() { return $this->belongsTo(User::class); }
    public function users() { return $this->hasMany(User::class); }

    // Model kaydedildiğinde (oluşturma veya güncelleme) otomatik çalışır
    protected static function booted()
    {
        static::deleting(function ($viceMayor) {
            if ($viceMayor->user) {
                $viceMayor->user->delete(); // Başkan yardımcısı silinirse kullanıcısı da silinsin
            }
        });
    }
}