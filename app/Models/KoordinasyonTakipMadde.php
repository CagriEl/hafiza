<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KoordinasyonTakipMadde extends Model
{
    public const DURUM_TEYIT = 'teyit';

    public const DURUM_SUPHE = 'suphe';

    public const DURUM_DUZELTME = 'duzeltme';

    protected $table = 'koordinasyon_takip_maddeleri';

    protected $fillable = [
        'yil',
        'ay',
        'hafta',
        'analiz_user_id',
        'directorate_user_id',
        'baslik',
        'durum',
        'saha_kontrolu',
        'notlar',
        'kapanis_at',
        'created_by',
    ];

    protected $casts = [
        'yil' => 'integer',
        'ay' => 'integer',
        'hafta' => 'integer',
        'saha_kontrolu' => 'boolean',
        'kapanis_at' => 'datetime',
    ];

    /**
     * @return array<string, string>
     */
    public static function durumOptions(): array
    {
        return [
            self::DURUM_TEYIT => 'Yeşil — Teyitli',
            self::DURUM_SUPHE => 'Sarı — Şüphe',
            self::DURUM_DUZELTME => 'Kırmızı — Düzeltme',
        ];
    }

    public function analizUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analiz_user_id');
    }

    public function directorate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'directorate_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
