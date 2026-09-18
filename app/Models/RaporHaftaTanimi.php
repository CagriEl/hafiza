<?php

namespace App\Models;

use App\Support\ReportPeriodWeeks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaporHaftaTanimi extends Model
{
    protected $table = 'rapor_hafta_tanimlari';

    protected $fillable = [
        'yil',
        'ay',
        'hafta',
        'baslangic',
        'bitis',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'yil' => 'integer',
            'hafta' => 'integer',
            'baslangic' => 'date',
            'bitis' => 'date',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function ayPadded(): string
    {
        return str_pad(trim((string) $this->ay), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<int, array{hafta: int, baslangic: \Carbon\Carbon, bitis: \Carbon\Carbon}>
     */
    public static function weeksForPeriod(int $yil, int $ay): array
    {
        $ayPadded = str_pad((string) $ay, 2, '0', STR_PAD_LEFT);

        return self::query()
            ->where('yil', $yil)
            ->where('ay', $ayPadded)
            ->whereBetween('hafta', [1, ReportPeriodWeeks::WEEK_COUNT])
            ->orderBy('hafta')
            ->get()
            ->map(fn (self $row): array => [
                'hafta' => (int) $row->hafta,
                'baslangic' => $row->baslangic->copy()->startOfDay(),
                'bitis' => $row->bitis->copy()->startOfDay(),
            ])
            ->all();
    }

    public static function hasDefinitionsForPeriod(int $yil, int $ay): bool
    {
        $ayPadded = str_pad((string) $ay, 2, '0', STR_PAD_LEFT);

        return self::query()
            ->where('yil', $yil)
            ->where('ay', $ayPadded)
            ->exists();
    }
}
