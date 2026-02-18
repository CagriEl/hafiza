<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SwotAnaliz extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'yil',
        'baslik',
        'guclu_yonler',
        'zayif_yonler',
        'firsatlar',
        'tehditler',
        'islem_adi', // <--- Bunu da garanti olsun diye ekleyelim
    ];

    // --- BURAYI EKLEYİN ---
    protected static function booted()
    {
        static::creating(function ($model) {
            // Eğer islem_adi boşsa, varsayılan bir değer ata
            if (empty($model->islem_adi)) {
                $model->islem_adi = $model->baslik ?? 'SWOT Analizi';
            }
        });
    }
    // ----------------------
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}