<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * En az bir satırda faaliyet_turu = Koordinasyon (MySQL JSON).
     */
    public function scopeWhereHasCoordinationLine(Builder $query): Builder
    {
        return $query->whereRaw("JSON_SEARCH(faaliyetler, 'one', 'Koordinasyon', NULL, '$[*].faaliyet_turu') IS NOT NULL");
    }

    /**
     * Hiç Koordinasyon satırı yok (veya JSON boş).
     */
    public function scopeWhereHasNoCoordinationLine(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('faaliyetler')
                ->orWhereRaw("JSON_TYPE(faaliyetler) = 'NULL'")
                ->orWhereRaw("JSON_SEARCH(faaliyetler, 'one', 'Koordinasyon', NULL, '$[*].faaliyet_turu') IS NULL");
        });
    }
}
