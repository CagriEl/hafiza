<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KoordinasyonHafta extends Model
{
    protected $table = 'koordinasyon_haftalar';

    protected $fillable = [
        'yil',
        'ay',
        'hafta',
        'checklist',
        'ozet_not',
        'updated_by',
    ];

    protected $casts = [
        'yil' => 'integer',
        'ay' => 'integer',
        'hafta' => 'integer',
        'checklist' => 'array',
    ];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return array{pazartesi_sync: bool, saha_turu: bool, cuma_kapanis: bool, ust_yonetime_ozet: bool}
     */
    public static function defaultChecklist(): array
    {
        return [
            'pazartesi_sync' => false,
            'saha_turu' => false,
            'cuma_kapanis' => false,
            'ust_yonetime_ozet' => false,
        ];
    }
}
