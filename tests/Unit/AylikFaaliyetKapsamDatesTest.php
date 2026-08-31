<?php

namespace Tests\Unit;

use App\Filament\Resources\AylikFaaliyetResource;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AylikFaaliyetKapsamDatesTest extends TestCase
{
    public function test_enforce_kapsam_date_ranges_requires_dates_when_quantity_entered(): void
    {
        $this->expectException(ValidationException::class);

        AylikFaaliyetResource::enforceKapsamDateRanges([
            'faaliyetler' => [
                [
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'Test kalem',
                            'ongorulen' => 5,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_enforce_kapsam_date_ranges_normalizes_valid_dates(): void
    {
        $data = AylikFaaliyetResource::enforceKapsamDateRanges([
            'faaliyetler' => [
                [
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'Test kalem',
                            'ongorulen' => 5,
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
    }

    public function test_format_kapsam_date_range(): void
    {
        $this->assertSame(
            '01.08.2026 – 05.08.2026',
            AylikFaaliyetResource::formatKapsamDateRange('2026-08-01', '2026-08-05')
        );
    }
}
