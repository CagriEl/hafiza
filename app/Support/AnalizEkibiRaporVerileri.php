<?php

namespace App\Support;

use App\Filament\Resources\ControlTeamAuditNoteResource;
use App\Models\ControlTeamAuditNote;
use Filament\Forms\Get;

/**
 * Analiz notu içindeki yapılandırılmış örnek rapor verisi.
 */
final class AnalizEkibiRaporVerileri
{
    /**
     * @return array<string, mixed>
     */
    public static function emptyStructure(): array
    {
        return [
            'ozet' => [
                'yapilan_is' => null,
                'acikta_bekleyen' => null,
                'tamamlanma_orani' => null,
                'revize_karar' => null,
                'gecen_ay_fark' => null,
                'kritik_kalem_notu' => '',
            ],
            'kalem_analizi' => [],
            'aksiyonlar' => [],
            'olgunluk' => [
                'veri_kalitesi' => ['deger' => null, 'not' => ''],
                'zamaninda_kapanis' => ['deger' => null, 'not' => ''],
                'risk_yonetimi' => ['deger' => null, 'not' => ''],
                'aksiyon_kapanis' => ['deger' => null, 'not' => ''],
            ],
            'risk_haritasi' => [],
        ];
    }

    /**
     * @param  mixed  $raw
     * @return array<string, mixed>
     */
    public static function normalize(mixed $raw): array
    {
        $base = self::emptyStructure();
        if (! is_array($raw)) {
            return $base;
        }

        if (is_array($raw['ozet'] ?? null)) {
            $base['ozet'] = array_merge($base['ozet'], $raw['ozet']);
        }

        $base['kalem_analizi'] = self::normalizeList($raw['kalem_analizi'] ?? [], [
            'kalem', 'gerceklesen', 'acikta', 'durum', 'sapma_not', 'son_tarih',
        ]);

        $base['aksiyonlar'] = self::normalizeList($raw['aksiyonlar'] ?? [], [
            'oncelik', 'aksiyon', 'kalem', 'sorumlu', 'hedef_tarih', 'durum',
        ]);

        if (is_array($raw['olgunluk'] ?? null)) {
            foreach (array_keys($base['olgunluk']) as $key) {
                $item = $raw['olgunluk'][$key] ?? null;
                if (is_array($item)) {
                    $base['olgunluk'][$key] = [
                        'deger' => $item['deger'] ?? null,
                        'not' => (string) ($item['not'] ?? ''),
                    ];
                }
            }
        }

        $base['risk_haritasi'] = self::normalizeList($raw['risk_haritasi'] ?? [], [
            'kalem', 'seviye', 'aciklama',
        ]);

        return $base;
    }

    /**
     * Müdürlük + dönem seçildiğinde tüm faaliyetlerin özet + kalem analizi.
     *
     * @return array<string, mixed>
     */
    public static function buildMudurlukOzetPrefill(Get $get): array
    {
        $summary = ControlTeamAuditNoteResource::mudurlukPeriodSummary($get);
        $gerceklesen = (int) ($summary['gerceklesen'] ?? 0);
        $kalan = (int) ($summary['kalan'] ?? 0);
        $hedef = (int) ($summary['hedef'] ?? 0);
        $toplam = $hedef > 0 ? $hedef : ($gerceklesen + $kalan);
        $kalemler = ControlTeamAuditNoteResource::mudurlukPeriodKalemAnalizi($get);

        $data = self::emptyStructure();
        $data['ozet'] = [
            'yapilan_is' => $gerceklesen,
            'acikta_bekleyen' => $kalan,
            'tamamlanma_orani' => self::completionPercent($gerceklesen, $toplam),
            'revize_karar' => (int) ($summary['revize_karar'] ?? 0),
            'gecen_ay_fark' => ControlTeamAuditNoteResource::mudurlukGecenAyFark($get),
            'kritik_kalem_notu' => (string) ($summary['kritik_kalem_notu'] ?? self::computeKritikKalemNotu($kalemler)),
        ];
        $data['kalem_analizi'] = $kalemler;
        $data['olgunluk'] = self::computeOlgunluk($kalemler, null);
        $data['risk_haritasi'] = self::computeRiskHaritasi($kalemler, null);
        $data['aksiyonlar'] = self::suggestAksiyonlar($kalemler, null);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function buildPrefill(array $context): array
    {
        $summary = $context['summary'] ?? ['hedef' => 0, 'gerceklesen' => 0, 'kalan' => 0];
        $row = is_array($context['faaliyet_row'] ?? null) ? $context['faaliyet_row'] : null;
        $gecenAyFark = $context['gecen_ay_fark'] ?? null;

        $gerceklesen = (int) ($summary['gerceklesen'] ?? 0);
        $kalan = (int) ($summary['kalan'] ?? 0);
        $hedef = (int) ($summary['hedef'] ?? 0);
        $toplam = $hedef > 0 ? $hedef : ($gerceklesen + $kalan);

        $revizeKarar = 0;
        if ($row !== null) {
            if ((bool) ($row['gerekli_revize'] ?? false)) {
                $revizeKarar++;
            }
            if (filled($row['karar_ihtiyaci'] ?? null)) {
                $revizeKarar++;
            }
        }

        $kalemler = self::buildKalemAnalizi($row);
        $kritikNot = self::computeKritikKalemNotu($kalemler);

        $data = self::emptyStructure();
        $data['ozet'] = [
            'yapilan_is' => $gerceklesen,
            'acikta_bekleyen' => $kalan,
            'tamamlanma_orani' => self::completionPercent($gerceklesen, $toplam),
            'revize_karar' => $revizeKarar,
            'gecen_ay_fark' => $gecenAyFark,
            'kritik_kalem_notu' => $kritikNot,
        ];
        $data['kalem_analizi'] = $kalemler;
        $data['olgunluk'] = self::computeOlgunluk($kalemler, $row);
        $data['risk_haritasi'] = self::computeRiskHaritasi($kalemler, $row);
        $data['aksiyonlar'] = self::suggestAksiyonlar($kalemler, $row);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildPrefillFromForm(Get $get): array
    {
        // Analiz raporu müdürlük düzeyinde tutulur; faaliyet kodu seçilmez.
        return self::buildMudurlukOzetPrefill($get);
    }

    /**
     * @return array<string, string>
     */
    public static function metaFromNote(ControlTeamAuditNote $note): array
    {
        $note->loadMissing(['directorate', 'user', 'activityCatalog']);

        $ay = str_pad(preg_replace('/\D/', '', (string) ($note->ay ?? '')) ?: '', 2, '0', STR_PAD_LEFT);
        $donem = ($note->yil && strlen($ay) === 2)
            ? (string) $note->yil.'-'.$ay
            : '—';

        $hafta = ReportPeriodWeeks::normalizeReportHafta($note->hafta)
            ?? ReportPeriodWeeks::normalizeReportHafta($note->aylikFaaliyet?->hafta);
        $haftaLabel = ($note->yil && strlen($ay) === 2 && $hafta !== null)
            ? (ReportPeriodWeeks::periodLabelForRecord((int) $note->yil, $ay, $hafta) ?? $hafta)
            : '—';

        return [
            'donem' => $donem,
            'mudurluk' => trim((string) ($note->directorate?->name ?? '—')),
            'faaliyet_kodu' => 'Müdürlük geneli',
            'analiz_tarihi' => $note->audit_date?->format('d.m.Y') ?? '—',
            'analiz_eden' => trim((string) ($note->user?->name ?? '—')),
            'rapor_haftasi' => $haftaLabel,
        ];
    }

    public static function completionPercent(int $done, int $total): ?int
    {
        if ($total <= 0) {
            return null;
        }

        return (int) min(100, max(0, round(($done / $total) * 100)));
    }

    public static function suggestDurum(int $done, int $pending): string
    {
        if ($pending <= 0 && $done > 0) {
            return 'Tamamlandı';
        }
        if ($done <= 0 && $pending > 0) {
            return 'Riskli';
        }
        if ($pending > 0) {
            return 'Kısmi';
        }

        return 'Veri Eksik';
    }

    /**
     * @param  array<string, mixed>|null  $row
     * @return list<array<string, mixed>>
     */
    public static function buildKalemAnalizi(?array $row): array
    {
        if ($row === null) {
            return [];
        }

        $kalemler = [];
        $kv = is_array($row['kapsam_verileri'] ?? null) ? $row['kapsam_verileri'] : [];
        $sapma = trim((string) ($row['sapma_nedeni'] ?? ''));

        foreach ($kv as $line) {
            if (! is_array($line)) {
                continue;
            }
            $kalem = trim((string) ($line['kalem'] ?? ''));
            if ($kalem === '') {
                continue;
            }
            $done = (int) ($line['gerceklesen'] ?? 0);
            $pending = (int) (AylikFaaliyetRepeaterLock::kapsamSatirAciktaKalan($line) ?? max(0, (int) ($line['ongorulen'] ?? 0) - $done));
            $kalemler[] = [
                'kalem' => $kalem,
                'gerceklesen' => $done,
                'acikta' => $pending,
                'durum' => self::suggestDurum($done, $pending),
                'sapma_not' => $sapma,
                'son_tarih' => AylikFaaliyetWeeklyCarryover::formatDisplayDate($line['son_yapilma_tarihi'] ?? null) ?? '',
            ];
        }

        return $kalemler;
    }

    /**
     * @param  list<array<string, mixed>>  $kalemler
     */
    public static function computeKritikKalemNotu(array $kalemler): string
    {
        $worst = null;
        $worstPending = -1;

        foreach ($kalemler as $kalem) {
            $pending = (int) ($kalem['acikta'] ?? 0);
            $durum = (string) ($kalem['durum'] ?? '');
            $weight = $pending + ($durum === 'Riskli' ? 1000 : ($durum === 'Kısmi' ? 500 : 0));
            if ($weight > $worstPending) {
                $worstPending = $weight;
                $worst = $kalem;
            }
        }

        if ($worst === null || (int) ($worst['acikta'] ?? 0) <= 0) {
            return '';
        }

        return 'Kritik kalem: '.(string) ($worst['kalem'] ?? '').' (açıkta '.(int) ($worst['acikta'] ?? 0).' iş)';
    }

    /**
     * @param  list<array<string, mixed>>  $kalemler
     * @param  array<string, mixed>|null  $row
     * @return array<string, array{deger: ?int, not: string}>
     */
    public static function computeOlgunluk(array $kalemler, ?array $row): array
    {
        $n = count($kalemler);
        if ($n === 0) {
            return self::emptyStructure()['olgunluk'];
        }

        $qualityHits = 0;
        $closedHits = 0;
        $riskPenalty = 0;
        $progressHits = 0;
        $progressEligible = 0;

        foreach ($kalemler as $kalem) {
            $done = (int) ($kalem['gerceklesen'] ?? 0);
            $pending = (int) ($kalem['acikta'] ?? 0);
            $durum = (string) ($kalem['durum'] ?? '');

            if (filled($kalem['kalem'] ?? null) && ($done > 0 || $pending > 0)) {
                $qualityHits++;
            }
            if ($pending <= 0 && $done > 0) {
                $closedHits++;
            }
            if ($durum === 'Riskli') {
                $riskPenalty += 2;
            } elseif ($durum === 'Kısmi') {
                $riskPenalty += 1;
            }
            if ($done > 0) {
                $progressEligible++;
                if (filled($kalem['son_tarih'] ?? null)) {
                    $progressHits++;
                }
            }
        }

        $hasRevize = $row !== null && (bool) ($row['gerekli_revize'] ?? false);
        $hasKarar = $row !== null && filled($row['karar_ihtiyaci'] ?? null);
        if ($hasRevize) {
            $riskPenalty++;
        }
        if ($hasKarar) {
            $riskPenalty++;
        }

        $veriKalitesi = (int) round($qualityHits / $n * 100);
        $zamanindaKapanis = (int) round($closedHits / $n * 100);
        $riskYonetimi = (int) max(0, min(100, 100 - (int) round($riskPenalty / max(1, $n * 2) * 100)));
        $aksiyonKapanis = $progressEligible > 0
            ? (int) round($progressHits / $progressEligible * 100)
            : $zamanindaKapanis;

        return [
            'veri_kalitesi' => [
                'deger' => $veriKalitesi,
                'not' => "{$qualityHits}/{$n} kalemde anlamlı veri var.",
            ],
            'zamaninda_kapanis' => [
                'deger' => $zamanindaKapanis,
                'not' => "{$closedHits}/{$n} kalem tamamlandı.",
            ],
            'risk_yonetimi' => [
                'deger' => $riskYonetimi,
                'not' => self::riskYonetimiNotu($kalemler, $hasRevize, $hasKarar),
            ],
            'aksiyon_kapanis' => [
                'deger' => $aksiyonKapanis,
                'not' => $progressEligible > 0
                    ? "{$progressHits}/{$progressEligible} ilerleyen kalemde son tarih kayıtlı."
                    : 'İlerleme kaydı bekleniyor.',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $kalemler
     * @param  array<string, mixed>|null  $row
     * @return list<array<string, mixed>>
     */
    public static function computeRiskHaritasi(array $kalemler, ?array $row): array
    {
        $hasRevize = $row !== null && (bool) ($row['gerekli_revize'] ?? false);
        $karar = $row !== null ? trim((string) ($row['karar_ihtiyaci'] ?? '')) : '';

        $out = [];
        foreach ($kalemler as $kalem) {
            $name = trim((string) ($kalem['kalem'] ?? ''));
            if ($name === '') {
                continue;
            }
            $pending = (int) ($kalem['acikta'] ?? 0);
            $durum = (string) ($kalem['durum'] ?? '');

            if ($durum === 'Tamamlandı' && $pending <= 0) {
                $seviye = 'Düşük';
                $aciklama = 'Tüm kalemler zamanında tamamlandı.';
            } elseif ($durum === 'Riskli' || $pending >= 3) {
                $seviye = 'Yüksek';
                $aciklama = "Açıkta {$pending} iş";
                if ($hasRevize) {
                    $aciklama .= ', revize talebi var';
                }
                if ($karar !== '') {
                    $aciklama .= ', karar ihtiyacı bekliyor';
                }
                $aciklama .= '.';
            } else {
                $seviye = 'Orta';
                $aciklama = "Açıkta {$pending} iş";
                if ($karar !== '') {
                    $aciklama .= ', karar ihtiyacı açık';
                }
                $aciklama .= '.';
            }

            $out[] = [
                'kalem' => $name,
                'seviye' => $seviye,
                'aciklama' => $aciklama,
                '_sira' => $seviye === 'Yüksek' ? 0 : ($seviye === 'Orta' ? 1 : 2),
            ];
        }

        usort($out, fn (array $a, array $b): int => ($a['_sira'] ?? 9) <=> ($b['_sira'] ?? 9));

        return array_map(static function (array $item): array {
            unset($item['_sira']);

            return $item;
        }, $out);
    }

    /**
     * @param  list<array<string, mixed>>  $kalemler
     * @param  array<string, mixed>|null  $row
     * @return list<array<string, mixed>>
     */
    public static function suggestAksiyonlar(array $kalemler, ?array $row): array
    {
        $aksiyonlar = [];
        $karar = $row !== null ? trim((string) ($row['karar_ihtiyaci'] ?? '')) : '';

        foreach ($kalemler as $kalem) {
            $pending = (int) ($kalem['acikta'] ?? 0);
            $durum = (string) ($kalem['durum'] ?? '');
            $name = (string) ($kalem['kalem'] ?? '');
            if ($pending <= 0 || $name === '') {
                continue;
            }

            $oncelik = $durum === 'Riskli' ? 'Yüksek' : 'Orta';
            $aksiyon = $name.' için açıkta kalan '.$pending.' iş kapatılmalı.';
            if (filled($kalem['sapma_not'] ?? null)) {
                $aksiyon .= ' Sapma: '.(string) $kalem['sapma_not'];
            }

            $aksiyonlar[] = [
                'oncelik' => $oncelik,
                'aksiyon' => $aksiyon,
                'kalem' => $name,
                'sorumlu' => '',
                'hedef_tarih' => '',
                'durum' => 'Açık',
            ];
        }

        if ($karar !== '') {
            $aksiyonlar[] = [
                'oncelik' => 'Yüksek',
                'aksiyon' => 'Karar ihtiyacı: '.$karar,
                'kalem' => '',
                'sorumlu' => '',
                'hedef_tarih' => '',
                'durum' => 'Karar bekliyor',
            ];
        }

        return $aksiyonlar;
    }

    /**
     * @param  list<array<string, mixed>>  $kalemler
     */
    private static function riskYonetimiNotu(array $kalemler, bool $hasRevize, bool $hasKarar): string
    {
        $riskli = 0;
        foreach ($kalemler as $kalem) {
            if (($kalem['durum'] ?? '') === 'Riskli') {
                $riskli++;
            }
        }
        $parts = ["{$riskli} riskli kalem"];
        if ($hasRevize) {
            $parts[] = 'revize var';
        }
        if ($hasKarar) {
            $parts[] = 'karar ihtiyacı var';
        }

        return implode(', ', $parts).'.';
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private static function normalizeList(mixed $rows, array $keys): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $line = [];
            foreach ($keys as $key) {
                $line[$key] = $row[$key] ?? ($key === 'kalem' || str_contains($key, 'not') || str_contains($key, 'aciklama') ? '' : null);
            }
            $out[] = $line;
        }

        return $out;
    }
}
