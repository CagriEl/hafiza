<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HaftalikRapor extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'baslangic_tarihi' => 'date',
        'bitis_tarihi' => 'date',
        'faaliyetler' => 'array',
        'basit_planlanan_faaliyetler' => 'array',
        'detayli_planlanan_faaliyetler' => 'array',
        'ihaleler' => 'array',
        'memur_sayisi' => 'integer',
        'sozlesmeli_memur_sayisi' => 'integer',
        'kadrolu_isci_sayisi' => 'integer',
        'sirket_personeli_sayisi' => 'integer',
        'cimer_sayisi' => 'integer',
        'acikkapi_sayisi' => 'integer',
        'belediye_sayisi' => 'integer',
        'toplam_sikayet' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function tamRaporAdi(): Attribute
    {
        return Attribute::make(
            get: function () {
                $mudurlukAdi = $this->user ? $this->user->name : 'Bilinmeyen Müdürlük';

                if (! $this->baslangic_tarihi) {
                    return $mudurlukAdi.' - Taslak Rapor';
                }

                return $mudurlukAdi.' - '.
                    $this->baslangic_tarihi->format('d.m.Y').' / '.
                    $this->bitis_tarihi->format('d.m.Y').' Haftası Raporu';
            }
        );
    }
}
