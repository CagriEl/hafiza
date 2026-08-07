<?php

namespace Tests\Unit;

use App\Support\AylikFaaliyetRepeaterLock;
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

    public function test_kapsam_shows_follow_up_only_is_disabled_for_separate_weekly_reports(): void
    {
        $row = ['ongorulen' => 20, 'gerceklesen' => 5];

        $this->assertFalse(AylikFaaliyetWeeklyCarryover::kapsamShowsFollowUpOnly($row, 1));
        $this->assertFalse(AylikFaaliyetWeeklyCarryover::kapsamShowsFollowUpOnly($row, 2));
        $this->assertTrue(AylikFaaliyetWeeklyCarryover::kapsamVisibleInCurrentWeek($row, 2));
    }

    public function test_current_week_prefers_report_hafta_over_row(): void
    {
        $week = AylikFaaliyetWeeklyCarryover::resolveWeekForFaaliyetRow(
            ['hafta' => 1],
            ['yil' => 2026, 'ay' => '07', 'hafta' => '3']
        );

        $this->assertSame(3, $week);
    }

    public function test_restrict_faaliyetler_keeps_only_report_week(): void
    {
        $data = [
            'hafta' => '2',
            'faaliyetler' => [
                [
                    'hafta' => 1,
                    'faaliyet_kodu' => 'A-01',
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'X',
                            'haftalik_kayitlar' => [
                                ['hafta' => 1, 'miktar' => 5],
                                ['hafta' => 2, 'miktar' => 3],
                            ],
                        ],
                    ],
                ],
                [
                    'hafta' => 2,
                    'faaliyet_kodu' => 'B-01',
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'Y',
                            'haftalik_kayitlar' => [
                                ['hafta' => 2, 'miktar' => 7],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = AylikFaaliyetWeeklyCarryover::restrictFaaliyetlerToReportHafta($data);

        $this->assertCount(1, $result['faaliyetler']);
        $this->assertSame(2, $result['faaliyetler'][0]['hafta']);
        $this->assertSame('B-01', $result['faaliyetler'][0]['faaliyet_kodu']);
        $this->assertCount(1, $result['faaliyetler'][0]['kapsam_verileri'][0]['haftalik_kayitlar']);
        $this->assertSame(2, $result['faaliyetler'][0]['kapsam_verileri'][0]['haftalik_kayitlar'][0]['hafta']);
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

    public function test_apply_weekly_entries_uses_custom_completion_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20', 'Europe/Istanbul'));

        $data = [
            'yil' => 2026,
            'ay' => '06',
            'faaliyetler' => [
                [
                    'hafta' => 3,
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'İş 1',
                            'ongorulen' => 10,
                            'gerceklesen' => 4,
                            'bu_hafta_tamamlanan' => 3,
                            'bu_hafta_aciklama' => 'Tamamlandı',
                            'bu_hafta_yapilma_tarihi' => '2026-06-18',
                        ],
                    ],
                ],
            ],
        ];

        $result = AylikFaaliyetWeeklyCarryover::applyWeeklyEntries($data);
        $kapsam = $result['faaliyetler'][0]['kapsam_verileri'][0];

        $this->assertSame(7.0, (float) $kapsam['gerceklesen']);
        $this->assertSame('2026-06-18', $kapsam['son_yapilma_tarihi']);
        $this->assertSame('2026-06-18', $kapsam['haftalik_kayitlar'][0]['yapilma_tarihi']);
    }

    public function test_apply_acikta_kapatma_accepts_actual_last_week_when_month_has_four_weeks(): void
    {
        // Şubat 2021 Pazartesi başlar ve 28 gün → son hafta 4.
        Carbon::setTestNow(Carbon::parse('2021-02-28', 'Europe/Istanbul'));

        $this->assertSame(4, ReportPeriodWeeks::lastWeekNumberForMonth(2021, 2));

        $data = [
            'yil' => 2021,
            'ay' => '02',
            'faaliyetler' => [
                [
                    'hafta' => 4,
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'İş 1',
                            'ongorulen' => 10,
                            'gerceklesen' => 5,
                            'acikta_is_kapatiliyor' => true,
                            'acikta_kapatma_notu' => 'Dönem sonu',
                        ],
                    ],
                ],
            ],
        ];

        $result = AylikFaaliyetWeeklyCarryover::applyAciktaKapatma($data);
        $this->assertTrue((bool) $result['faaliyetler'][0]['kapsam_verileri'][0]['acikta_kapatildi']);
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
        $this->assertStringContainsString('Açık iş kapanışı:', $kapsam['haftalik_kayitlar'][0]['aciklama']);
    }

    public function test_apply_acikta_kapatma_works_before_last_week(): void
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
                            'acikta_kapatma_notu' => 'Erken kapanış',
                        ],
                    ],
                ],
            ],
        ];

        $result = AylikFaaliyetWeeklyCarryover::applyAciktaKapatma($data);
        $kapsam = $result['faaliyetler'][0]['kapsam_verileri'][0];

        $this->assertTrue((bool) ($kapsam['acikta_kapatildi'] ?? false));
        $this->assertSame(0.0, (float) $kapsam['acikta_kalan']);
    }

    public function test_kalan_acik_tamamla_marks_pending_as_done(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10', 'Europe/Istanbul'));

        $data = [
            'yil' => 2026,
            'ay' => '07',
            'faaliyetler' => [
                [
                    'hafta' => 2,
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'Larvasit',
                            'ongorulen' => 1,
                            'gerceklesen' => null,
                            'kalan_acik_tamamla' => true,
                        ],
                    ],
                ],
            ],
        ];

        $result = AylikFaaliyetWeeklyCarryover::applyAciktaKapatma($data);
        $kapsam = $result['faaliyetler'][0]['kapsam_verileri'][0];

        $this->assertSame(1.0, (float) $kapsam['gerceklesen']);
        $this->assertSame(0.0, (float) $kapsam['acikta_kalan']);
        $this->assertFalse((bool) ($kapsam['acikta_kapatildi'] ?? false));
        $this->assertSame('kalan_tamamlama', $kapsam['haftalik_kayitlar'][0]['tip']);
        $this->assertArrayNotHasKey('kalan_acik_tamamla', $kapsam);
    }

    public function test_apply_bulk_pending_completion_marks_all_open_items_done(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-30', 'Europe/Istanbul'));

        $data = [
            'yil' => 2026,
            'ay' => '06',
            'faaliyetler' => [
                [
                    'hafta' => ReportPeriodWeeks::WEEK_COUNT,
                    'faaliyet_kodu' => 'ZBM-03',
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'Pazar düzeni',
                            'ongorulen' => 3,
                            'gerceklesen' => 0,
                            'acikta_kalan' => 3,
                            'haftalik_kayitlar' => [],
                        ],
                        [
                            'kalem' => 'terminal kontrolleri',
                            'ongorulen' => 7,
                            'gerceklesen' => 0,
                            'acikta_kalan' => 7,
                            'haftalik_kayitlar' => [],
                        ],
                    ],
                ],
                [
                    'hafta' => ReportPeriodWeeks::WEEK_COUNT,
                    'faaliyet_kodu' => 'ZBM-05',
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'Ölçü',
                            'ongorulen' => 0,
                            'gerceklesen' => 0,
                        ],
                    ],
                ],
            ],
        ];

        $result = AylikFaaliyetWeeklyCarryover::applyBulkPendingCompletion($data, 'Dönem sonu toplu giriş');
        $zbm03 = $result['faaliyetler'][0]['kapsam_verileri'];

        $this->assertSame(0, AylikFaaliyetWeeklyCarryover::countPendingKapsamItems($result));
        $this->assertSame(3.0, (float) $zbm03[0]['gerceklesen']);
        $this->assertSame(0.0, (float) $zbm03[0]['acikta_kalan']);
        $this->assertSame(7.0, (float) $zbm03[1]['gerceklesen']);
        $this->assertSame('toplu_tamamlama', $zbm03[0]['haftalik_kayitlar'][0]['tip']);
        $this->assertSame('Dönem sonu toplu giriş', $zbm03[0]['haftalik_kayitlar'][0]['aciklama']);
    }

    public function test_count_pending_kapsam_items_ignores_zero_plan_rows(): void
    {
        $data = [
            'faaliyetler' => [
                [
                    'kapsam_verileri' => [
                        ['kalem' => 'A', 'ongorulen' => 0, 'gerceklesen' => 0],
                        ['kalem' => 'B', 'ongorulen' => 5, 'gerceklesen' => 2],
                    ],
                ],
            ],
        ];

        $this->assertSame(1, AylikFaaliyetWeeklyCarryover::countPendingKapsamItems($data));
    }

    public function test_monthly_row_resolves_to_zero_week(): void
    {
        $week = AylikFaaliyetWeeklyCarryover::resolveWeekForFaaliyetRow(
            ['hafta' => ReportPeriodWeeks::MONTHLY_VALUE],
            ['yil' => 2026, 'ay' => '07']
        );

        $this->assertSame(0, $week);
    }

    public function test_backfill_empty_gerceklesen_fills_null_and_zero_but_skips_partial(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15', 'Europe/Istanbul'));

        $data = [
            'yil' => 2026,
            'ay' => '07',
            'hafta' => 3,
            'faaliyetler' => [
                [
                    'faaliyet_kodu' => 'TEM-05',
                    'hafta' => 3,
                    'kapsam_verileri' => [
                        ['kalem' => 'Boş', 'ongorulen' => 10, 'gerceklesen' => null],
                        ['kalem' => 'Sıfır', 'ongorulen' => 4, 'gerceklesen' => 0],
                        ['kalem' => 'Kısmi', 'ongorulen' => 100, 'gerceklesen' => 95],
                    ],
                ],
            ],
        ];

        [$result, $changes] = AylikFaaliyetWeeklyCarryover::backfillEmptyGerceklesen($data);

        $this->assertCount(2, $changes);
        $kv = $result['faaliyetler'][0]['kapsam_verileri'];
        $this->assertSame(10.0, (float) $kv[0]['gerceklesen']);
        $this->assertSame(4.0, (float) $kv[1]['gerceklesen']);
        $this->assertSame(95.0, (float) $kv[2]['gerceklesen']);
        $this->assertSame('kalan_tamamlama', $kv[0]['haftalik_kayitlar'][0]['tip']);
    }

    public function test_kapsam_pending_amount_is_zero_when_acikta_kapatildi(): void
    {
        $pending = AylikFaaliyetWeeklyCarryover::kapsamPendingAmount([
            'ongorulen' => 50,
            'gerceklesen' => 20,
            'acikta_kapatildi' => true,
            'acikta_kalan' => 30,
        ]);

        $this->assertSame(0.0, $pending);
    }

    public function test_note_close_stays_closed_after_weekly_entries_and_sync(): void
    {
        $data = [
            'yil' => 2026,
            'ay' => '06',
            'faaliyetler' => [
                [
                    'hafta' => 3,
                    'ay_sonu_performans_kilitli' => true,
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'İş 1',
                            'ongorulen' => 50,
                            'gerceklesen' => 30,
                            'acikta_is_kapatiliyor' => true,
                            'acikta_kapatma_notu' => 'Yapılamadı',
                        ],
                    ],
                ],
            ],
        ];

        $result = AylikFaaliyetWeeklyCarryover::applyAciktaKapatma($data);
        $result = AylikFaaliyetWeeklyCarryover::applyWeeklyEntries($result);
        $result = AylikFaaliyetRepeaterLock::syncRowAySonuTotalsFromKapsamVerileri($result);
        $kapsam = $result['faaliyetler'][0]['kapsam_verileri'][0];

        $this->assertTrue((bool) $kapsam['acikta_kapatildi']);
        $this->assertSame(0.0, (float) $kapsam['acikta_kalan']);
        $this->assertSame(0.0, (float) $result['faaliyetler'][0]['bekleyen_is']);
        $this->assertSame(0.0, AylikFaaliyetWeeklyCarryover::kapsamPendingAmount($kapsam));
    }

    public function test_kapanis_miktar_closes_pending_when_amount_covers_remaining(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-12', 'Europe/Istanbul'));

        $data = [
            'yil' => 2026,
            'ay' => '07',
            'faaliyetler' => [
                [
                    'hafta' => 2,
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'İş 1',
                            'ongorulen' => 10,
                            'gerceklesen' => 7,
                            'acikta_kapanis_miktar' => 3,
                        ],
                    ],
                ],
            ],
        ];

        $result = AylikFaaliyetWeeklyCarryover::applyAciktaKapatma($data);
        $result = AylikFaaliyetRepeaterLock::syncRowAySonuTotalsFromKapsamVerileri($result);
        $kapsam = $result['faaliyetler'][0]['kapsam_verileri'][0];

        $this->assertSame(10.0, (float) $kapsam['gerceklesen']);
        $this->assertSame(0.0, (float) $kapsam['acikta_kalan']);
        $this->assertSame(0.0, (float) $result['faaliyetler'][0]['bekleyen_is']);
        $this->assertSame('kapanis_miktar', $kapsam['haftalik_kayitlar'][0]['tip']);
        $this->assertArrayNotHasKey('acikta_kapanis_miktar', $kapsam);
    }

    public function test_relax_performans_kilit_while_pending(): void
    {
        $data = [
            'faaliyetler' => [
                [
                    'ay_sonu_performans_kilitli' => true,
                    'gerceklesen' => 30,
                    'bekleyen_is' => 20,
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'İş',
                            'ongorulen' => 50,
                            'gerceklesen' => 30,
                        ],
                    ],
                ],
            ],
        ];

        $result = AylikFaaliyetRepeaterLock::relaxPerformansKilitWhilePending($data);

        $this->assertFalse((bool) $result['faaliyetler'][0]['ay_sonu_performans_kilitli']);
    }
}
