<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HaftalikRapor extends Model
{
    use HasFactory;

    protected $guarded = []; // Tüm alanlara yazma izni ver

    // *** BU KISIM EKSİK OLDUĞU İÇİN VERİLER BOŞ GELİYORDU ***
    protected $casts = [
        'baslangic_tarihi' => 'date',
        'bitis_tarihi' => 'date',
        'faaliyetler' => 'array', // JSON verisini diziye çevir
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

    // Kullanıcı ilişkisi (Raporu kim hazırladı?)
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Tabloda rapor adını güzel göstermek için bir özellik
   public function getTamRaporAdiAttribute()
{
    $mudurluk = $this->user ? $this->user->name : 'Müdürlük';
    
    // Tarihler varsa formatla, yoksa uyarı ver
    $baslangic = $this->baslangic_tarihi ? \Carbon\Carbon::parse($this->baslangic_tarihi)->format('d.m.Y') : '?';
    $bitis = $this->bitis_tarihi ? \Carbon\Carbon::parse($this->bitis_tarihi)->format('d.m.Y') : '?';

    // ÖRNEK ÇIKTI: Bilgi İşlem Md. - (01.01.2026 - 08.01.2026)
    return "{$mudurluk} - ({$baslangic} / {$bitis})";
}

    protected function tamRaporAdi(): Attribute
{
    return Attribute::make(
        get: function () {
            // Eğer kullanıcı (Müdürlük) silinmişse veya yoksa hata vermesin
            $mudurlukAdi = $this->user ? $this->user->name : 'Bilinmeyen Müdürlük';
            
            // Tarihler yoksa boş dönmesin
            if (!$this->baslangic_tarihi) return $mudurlukAdi . ' - Taslak Rapor';

            // FORMAT: "Bilgi İşlem Müdürlüğü - 05.01.2026 / 08.01.2026 Haftası Raporu"
            return $mudurlukAdi . ' - ' . 
                   $this->baslangic_tarihi->format('d.m.Y') . ' / ' . 
                   $this->bitis_tarihi->format('d.m.Y') . ' Haftası Raporu';
        }
    );
}
}