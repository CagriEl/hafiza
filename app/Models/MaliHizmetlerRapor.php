<?php

namespace App\Models;

use App\Support\MaliHizmetlerOdemeTalep;
use App\Support\ReportPeriodWeeks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaliHizmetlerRapor extends Model
{
    public const DURUM_BEKLIYOR = 'bekliyor';

    public const DURUM_ONAYLANDI = 'onaylandi';

    public const DURUM_ODENDI = 'odendi';

    public const DURUM_REDDEDILDI = 'reddedildi';

    protected $table = 'mali_hizmetler_raporlari';

    protected $fillable = [
        'user_id',
        'yil',
        'ay',
        'hafta',
        'kasa_tutari',
        'haftalik_odeme_toplam',
        'odeme_talepleri',
    ];

    protected function casts(): array
    {
        return [
            'yil' => 'integer',
            'hafta' => 'integer',
            'kasa_tutari' => 'decimal:2',
            'haftalik_odeme_toplam' => 'decimal:2',
            'odeme_talepleri' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    public static function odemeTalepDurumlari(): array
    {
        return [
            self::DURUM_BEKLIYOR => 'Bekliyor',
            self::DURUM_ONAYLANDI => 'Onaylandı',
            self::DURUM_ODENDI => 'Ödendi',
            self::DURUM_REDDEDILDI => 'Reddedildi',
        ];
    }

    public function donemLabel(): string
    {
        $ay = str_pad(trim((string) $this->ay), 2, '0', STR_PAD_LEFT);
        $weekLabel = ReportPeriodWeeks::weekLabelForRecord((int) $this->yil, (int) $ay, (int) $this->hafta);

        return (string) $this->yil.' / '.$ay.' · '.($weekLabel ?? ('Hafta '.$this->hafta));
    }

    public function odemeTalepleriToplam(): float
    {
        $items = is_array($this->odeme_talepleri) ? $this->odeme_talepleri : [];
        $sum = 0.0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $tutar = $item['tutar'] ?? null;
            if (is_numeric($tutar)) {
                $sum += (float) $tutar;
            }
        }

        return $sum;
    }

    public function bekleyenTalepSayisi(): int
    {
        $items = is_array($this->odeme_talepleri) ? $this->odeme_talepleri : [];
        $count = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (MaliHizmetlerOdemeTalep::isBekleyen($item)) {
                $count++;
            }
        }

        return $count;
    }

    public function bekleyenTalepToplam(): float
    {
        $items = is_array($this->odeme_talepleri) ? $this->odeme_talepleri : [];
        $sum = 0.0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (! MaliHizmetlerOdemeTalep::isBekleyen($item)) {
                continue;
            }
            $tutar = $item['tutar'] ?? null;
            if (is_numeric($tutar)) {
                $sum += (float) $tutar;
            }
        }

        return $sum;
    }
}
