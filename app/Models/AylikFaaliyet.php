<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth; // Auth sınıfını kullanmak için

class AylikFaaliyet extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'faaliyetler' => 'array',
    ];

    // --- BURAYI EKLEYİN ---
    protected static function booted()
    {
        static::creating(function ($model) {
            // Eğer kullanıcı giriş yapmışsa ID'sini modele ekle
            if (Auth::check()) {
                $model->user_id = Auth::id();
            }
        });
    }
    // ----------------------

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}