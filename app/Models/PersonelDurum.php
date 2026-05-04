<?php

namespace App\Models;

use App\Support\NonNegativeInput;
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

        static::saving(function (PersonelDurum $model): void {
            foreach (['memur', 'sozlesmeli_memur', 'kadrolu_isci', 'sirket_personeli', 'gecici_isci'] as $col) {
                $model->setAttribute($col, NonNegativeInput::normalizeScalar($model->getAttribute($col)) ?? 0);
            }
        });
    }
    // ----------------------

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
