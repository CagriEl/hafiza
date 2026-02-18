<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PersonelDurum extends Model
{
    use HasFactory;

    protected $guarded = [];

    // --- BURAYI EKLEYİN ---
    protected static function booted()
    {
        static::creating(function ($model) {
            // Kayıt oluşturulurken otomatik olarak giriş yapan kullanıcının ID'sini ver
            if (auth()->check()) {
                $model->user_id = auth()->id();
            }
        });
    }
    // ----------------------

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}