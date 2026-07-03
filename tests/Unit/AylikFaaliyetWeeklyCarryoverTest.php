<?php

namespace Tests\Unit;

use App\Support\AylikFaaliyetWeeklyCarryover;
use App\Support\ReportPeriodWeeks;
use Carbon\Carbon;
use Tests\TestCase;

class AylikFaaliyetWeeklyCarryoverTest extends TestCase
{
    public function test_kapsam_pending_amount_from_plan_minus_done(): void
    {
        $pending = AylikFaaliyetWeeklyCarryover::kapsamPendingAmount([
            'ongorulen' => 100,
            'gerceklesen' => 30,
        ]);

        $this->assertSame(70.0, $pending);
    }

    public function test_apply_weekly_entries_records_progress_with_auto_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15', 'Europe/Istanbul'));

        $data = [
            'yil' => 2026,
            'ay' => '06',
            'faaliyetler' => [
                [
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'İş 1',
                            'ongorulen' => 50,
                            'gerceklesen' => 10,
                            'bu_hafta_tamamlanan' => 5,
                            'bu_hafta_aciklama' => 'Devam edildi',
                        ],
                    ],
                ],
            ],
        ];

        $result = AylikFaaliyetWeeklyCarryover::applyWeeklyEntries($data);
        $kapsam = $result['faaliyetler'][0]['kapsam_verileri'][0];

        $this->assertSame(15.0, (float) $kapsam['gerceklesen']);
        $this->assertSame(35.0, (float) $kapsam['acikta_kalan']);
        $this->assertSame('2026-06-15', $kapsam['son_yapilma_tarihi']);
        $this->assertCount(1, $kapsam['haftalik_kayitlar']);
        $this->assertSame('Devam edildi', $kapsam['haftalik_kayitlar'][0]['aciklama']);
        $this->assertArrayNotHasKey('bu_hafta_tamamlanan', $kapsam);
    }

    public function test_consolidate_merges_duplicate_catalog_rows(): void
    {
        $data = [
            'faaliyetler' => [
                [
                    'activity_catalog_id' => 12,
                    'faaliyet_kodu' => 'F-01',
                    'kapsam_verileri' => [
                        ['kalem' => 'A', 'ongorulen' => 10, 'gerceklesen' => 4],
                    ],
                ],
                [
                    'activity_catalog_id' => 12,
                    'faaliyet_kodu' => 'F-01',
                    'kapsam_verileri' => [
                        ['kalem' => 'A', 'ongorulen' => 10, 'gerceklesen' => 3],
                    ],
                ],
            ],
        ];

        $result = AylikFaaliyetWeeklyCarryover::consolidateFaaliyetRowsByCatalog($data);

        $this->assertCount(1, $result['faaliyetler']);
        $this->assertSame(7.0, (float) $result['faaliyetler'][0]['kapsam_verileri'][0]['gerceklesen']);
    }

    public function test_kapsam_shows_follow_up_only_after_first_week_with_pending(): void
    {
        $row = ['ongorulen' => 20, 'gerceklesen' => 5];

        $this->assertFalse(AylikFaaliyetWeeklyCarryover::kapsamShowsFollowUpOnly($row, 1));
        $this->assertTrue(AylikFaaliyetWeeklyCarryover::kapsamShowsFollowUpOnly($row, 2));
    }

    public function test_current_week_uses_faaliyet_row_hafta(): void
    {
        $week = AylikFaaliyetWeeklyCarryover::resolveWeekForFaaliyetRow(
            ['hafta' => 3],
            ['yil' => 2026, 'ay' => '07']
        );

        $this->assertSame(3, $week);
    }

    public function test_kapsam_pending_amount_ignores_stale_acikta_kalan_when_done_matches_plan(): void
    {
        $pending = AylikFaaliyetWeeklyCarryover::kapsamPendingAmount([
            'ongorulen' => 50,
            'gerceklesen' => 50,
            'acikta_kalan' => 50,
        ]);

        $this->assertSame(0.0, $pending);
    }

    public function test_apply_acikta_kapatma_closes_pending_on_last_week_with_note(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27', 'Europe/Istanbul'));

        $data = [
            'yil' => 2026,
            'ay' => '06',
            'faaliyetler' => [
                [
                    'hafta' => ReportPeriodWeeks::WEEK_COUNT,
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'İş 1',
                            'ongorulen' => 50,
                            'gerceklesen' => 30,
                            'acikta_is_kapatiliyor' => true,
                            'acikta_kapatma_notu' => 'Bütçe yetersizliği nedeniyle dönem sonunda kapatıldı.',
                        ],
                    ],
                ],
            ],
        ];

        $result = AylikFaaliyetWeeklyCarryover::applyAciktaKapatma($data);
        $kapsam = $result['faaliyetler'][0]['kapsam_verileri'][0];

        $this->assertTrue((bool) $kapsam['acikta_kapatildi']);
        $this->assertSame(0.0, (float) $kapsam['acikta_kalan']);
        $this->assertSame('Bütçe yetersizliği nedeniyle dönem sonunda kapatıldı.', $kapsam['acikta_kapatma_notu']);
        $this->assertCount(1, $kapsam['haftalik_kayitlar']);
        $this->assertSame('kapatma', $kapsam['haftalik_kayitlar'][0]['tip']);
        $this->assertStringContainsString('Dönem sonu kapanış:', $kapsam['haftalik_kayitlar'][0]['aciklama']);
    }

    public function test_apply_acikta_kapatma_skipped_before_last_week(): void
    {
        $data = [
            'yil' => 2026,
            'ay' => '06',
            'faaliyetler' => [
                [
                    'hafta' => 3,
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'İş 1',
                            'ongorulen' => 50,
                            'gerceklesen' => 30,
                            'acikta_is_kapatiliyor' => true,
                            'acikta_kapatma_notu' => 'Erken kapanış denemesi',
                        ],
                    ],
                ],
            ],
        ];

        $result = AylikFaaliyetWeeklyCarryover::applyAciktaKapatma($data);
        $kapsam = $result['faaliyetler'][0]['kapsam_verileri'][0];

        $this->assertFalse((bool) ($kapsam['acikta_kapatildi'] ?? false));
        $this->assertSame(20.0, AylikFaaliyetWeeklyCarryover::kapsamPendingAmount($kapsam));
    }

    public function test_monthly_row_resolves_to_zero_week(): void
    {
        $week = AylikFaaliyetWeeklyCarryover::resolveWeekForFaaliyetRow(
            ['hafta' => ReportPeriodWeeks::MONTHLY_VALUE],
            ['yil' => 2026, 'ay' => '07']
        );

        $this->assertSame(0, $week);
    }
}
