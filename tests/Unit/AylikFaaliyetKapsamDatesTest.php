<?php

namespace Tests\Unit;

use App\Filament\Resources\AylikFaaliyetResource;
use App\Support\KapsamIslemTuru;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AylikFaaliyetKapsamDatesTest extends TestCase
{
    public function test_enforce_kapsam_date_ranges_requires_islem_turu_for_every_kalem(): void
    {
        $this->expectException(ValidationException::class);

        AylikFaaliyetResource::enforceKapsamDateRanges([
            'faaliyetler' => [
                [
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'Test kalem',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_enforce_kapsam_date_ranges_allows_anlik_without_dates(): void
    {
        $data = AylikFaaliyetResource::enforceKapsamDateRanges([
            'faaliyetler' => [
                [
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'Test kalem',
                            'islem_turu' => KapsamIslemTuru::ANLIK,
                            'kalem_notu' => 'Anlık işlem',
                        ],
                    ],
                ],
            ],
        ]);

        $line = $data['faaliyetler'][0]['kapsam_verileri'][0];
        $this->assertSame(KapsamIslemTuru::ANLIK, $line['islem_turu']);
        $this->assertSame('Anlık işlem', $line['kalem_notu']);
        $this->assertNull($line['baslangic_tarihi']);
        $this->assertNull($line['bitis_tarihi']);
    }

    public function test_enforce_kapsam_date_ranges_requires_dates_for_surec_without_quantity(): void
    {
        $this->expectException(ValidationException::class);

        AylikFaaliyetResource::enforceKapsamDateRanges([
            'faaliyetler' => [
                [
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'Test kalem',
                            'islem_turu' => KapsamIslemTuru::SUREC,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_enforce_kapsam_date_ranges_normalizes_valid_process_dates(): void
    {
        $data = AylikFaaliyetResource::enforceKapsamDateRanges([
            'faaliyetler' => [
                [
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'Test kalem',
                            'islem_turu' => KapsamIslemTuru::SUREC,
                            'baslangic_tarihi' => '2026-08-01',
                            'bitis_tarihi' => '2026-08-05',
                        ],
                    ],
                ],
            ],
        ]);

        $line = $data['faaliyetler'][0]['kapsam_verileri'][0];
        $this->assertSame('2026-08-01', $line['baslangic_tarihi']);
        $this->assertSame('2026-08-05', $line['bitis_tarihi']);
        $this->assertSame(KapsamIslemTuru::SUREC, $line['islem_turu']);
    }

    public function test_enforce_kapsam_date_ranges_requires_single_date_for_gunluk(): void
    {
        $data = AylikFaaliyetResource::enforceKapsamDateRanges([
            'faaliyetler' => [
                [
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'Test kalem',
                            'islem_turu' => KapsamIslemTuru::GUNLUK,
                            'baslangic_tarihi' => '2026-08-03',
                        ],
                    ],
                ],
            ],
        ]);

        $line = $data['faaliyetler'][0]['kapsam_verileri'][0];
        $this->assertSame('2026-08-03', $line['baslangic_tarihi']);
        $this->assertSame('2026-08-03', $line['bitis_tarihi']);
    }

    public function test_format_kapsam_date_range(): void
    {
        $this->assertSame(
            '01.08.2026 – 05.08.2026',
            AylikFaaliyetResource::formatKapsamDateRange('2026-08-01', '2026-08-05')
        );
    }
}
