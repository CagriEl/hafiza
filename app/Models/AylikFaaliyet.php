<?php

namespace App\Models;

use App\Support\AylikFaaliyetRepeaterLock;
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

        static::saving(function (AylikFaaliyet $model): void {
            if (! is_array($model->faaliyetler)) {
                return;
            }
            $out = AylikFaaliyetRepeaterLock::clampNonNegativeNumericFaaliyetler(['faaliyetler' => $model->faaliyetler]);
            $model->faaliyetler = $out['faaliyetler'];
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

    public static function existsForUserPeriod(int $userId, int $yil, string $ay, ?int $exceptId = null): bool
    {
        if ($userId <= 0 || $yil <= 0 || trim($ay) === '') {
            return false;
        }

        $query = static::query()
            ->where('user_id', $userId)
            ->where('yil', $yil)
            ->where('ay', trim($ay));

        if ($exceptId !== null && $exceptId > 0) {
            $query->whereKeyNot($exceptId);
        }

        return $query->exists();
    }
}
