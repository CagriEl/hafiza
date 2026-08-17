<?php

namespace App\Support;

use App\Models\ActivityCatalog;
use App\Models\AylikFaaliyet;
use App\Models\ControlTeamAuditNote;
use App\Models\User;
use Filament\Forms\Get;

/**
 * Müdürlük + yıl/ay/hafta için Analiz Notları altında gösterilen
 * birebir haftalık faaliyet raporu ekranı.
 */
final class AnalizEkibiHaftalikFaaliyetEkrani
{
    /**
     * @return array<string, mixed>
     */
    public static function fromForm(Get $get, ?User $viewer = null): array
    {
        $viewer ??= auth()->user() instanceof User ? auth()->user() : null;
        $mudurlukId = (int) ($get('directorate_user_id') ?? 0);
        $yil = (int) ($get('yil') ?? 0);
        $ay = (string) ($get('ay') ?? '');
        $hafta = $get('hafta');

        return self::build($viewer, $mudurlukId, $yil, $ay, $hafta);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromNote(ControlTeamAuditNote $note, ?User $viewer = null): array
    {
        $viewer ??= auth()->user() instanceof User ? auth()->user() : null;
        $note->loadMissing('directorate');

        $mudurlukId = (int) ($note->directorate_user_id ?? 0);
        $yil = (int) ($note->yil ?? 0);
        $ay = (string) ($note->ay ?? '');
        $hafta = $note->hafta;
        if ($hafta === null || $hafta === '') {
            $hafta = $note->aylikFaaliyet?->hafta;
        }
        if (($hafta === null || $hafta === '') && $note->aylik_faaliyet_id) {
            $note->loadMissing('aylikFaaliyet');
            $hafta = $note->aylikFaaliyet?->hafta;
        }

        return self::build($viewer, $mudurlukId, $yil, $ay, $hafta);
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(?User $viewer, int $mudurlukId, int $yil, string|int $ay, mixed $hafta): array
    {
        $ayPadded = AylikFaaliyetPeriodMerge::normalizeAy($ay);
        $haftaNorm = ReportPeriodWeeks::normalizeReportHafta($hafta);
        $donem = self::periodLabel($yil, $ayPadded, $haftaNorm ?? (string) $hafta);
        $empty = self::empty($mudurlukId, '', $yil, $ayPadded, $haftaNorm ?? '', $donem);

        if ($mudurlukId <= 0 || $yil <= 0 || $ayPadded === '' || $haftaNorm === null) {
            return $empty;
        }

        if ($viewer instanceof User && ! AnalizEkibiYoneticiRapor::userCanAccessMudurluk($viewer, $mudurlukId)) {
            $empty['durum'] = 'Yetki yok';
            $empty['tavsiye'] = self::tavsiye($empty);

            return $empty;
        }

        $mudurluk = User::query()->find($mudurlukId);
        $mudurlukAdi = trim((string) ($mudurluk?->name ?? ''));
        if ($mudurlukAdi === '') {
            $mudurlukAdi = '#'.$mudurlukId;
        }
        $empty['mudurluk_adi'] = $mudurlukAdi;
        $empty['donem_etiketi'] = $donem;

        $rapor = AnalizEkibiYoneticiRapor::findWeekReport($mudurlukId, $yil, $ayPadded, $haftaNorm);
        if (! $rapor instanceof AylikFaaliyet) {
            $empty['tavsiye'] = self::tavsiye($empty);

            return $empty;
        }

        $rows = self::hydratedFaaliyetRows($rapor, $mudurlukId, $mudurlukAdi);
        $catalogById = self::loadCatalogs($rows);

        $kalemSatirlari = [];
        $chartCategories = [];
        $chartValues = [];
        $chartPlans = [];
        $uyarilar = [];
        $toplamGerceklesen = 0.0;
        $aciktaKalemSayisi = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $kod = trim((string) ($row['faaliyet_kodu'] ?? ''));
            $catalog = self::catalogForRow($row, $catalogById);
            $aile = self::resolveAile($row, $catalog);
            $rowOlcu = self::resolveOlcu($row, null, $catalog);
            $kv = is_array($row['kapsam_verileri'] ?? null) ? $row['kapsam_verileri'] : [];
            $kodToplam = 0.0;
            $kodPlan = 0.0;
            $hadKalem = false;

            if ($kv !== []) {
                foreach ($kv as $line) {
                    if (! is_array($line)) {
                        continue;
                    }
                    $kalem = trim((string) ($line['kalem'] ?? $line['baslik'] ?? ''));
                    if ($kalem === '') {
                        continue;
                    }

                    $hadKalem = true;
                    $planRaw = $line['ongorulen'] ?? $line['deger'] ?? null;
                    $doneRaw = $line['gerceklesen'] ?? null;
                    $planFilled = self::isFilledNumber($planRaw);
                    $doneFilled = self::isFilledNumber($doneRaw);
                    $plan = self::toFloat($planRaw);
                    $done = self::toFloat($doneRaw);
                    $kapatildi = (bool) ($line['acikta_kapatildi'] ?? false);
                    $pending = $kapatildi ? 0.0 : AylikFaaliyetWeeklyCarryover::kapsamPendingAmount($line);
                    $olcu = self::resolveOlcu($row, $line, $catalog) ?: $rowOlcu;
                    $tone = self::tone($planFilled, $doneFilled, $plan, $done, $pending);
                    if ($pending > 0.0) {
                        $aciktaKalemSayisi++;
                    }
                    if ($tone === 'warning') {
                        $uyarilar[] = trim($kod.' · '.$kalem).($olcu !== '' ? ' ('.$olcu.')' : '').' henüz sayı girilmemiş.';
                    }
                    $toplamGerceklesen += $done;
                    $kodToplam += $done;
                    $kodPlan += $plan;

                    $kalemSatirlari[] = [
                        'kod' => $kod !== '' ? $kod : '—',
                        'aile' => $aile,
                        'kalem' => $kalem,
                        'olcu' => $olcu,
                        'ongorulen' => $planFilled ? self::formatNumber($plan) : '—',
                        'gerceklesen' => $doneFilled ? self::formatNumber($done) : '—',
                        'acikta' => (! $planFilled && ! $doneFilled)
                            ? '—'
                            : self::formatNumber($pending),
                        'ongorulen_sayi' => $planFilled ? $plan : null,
                        'gerceklesen_sayi' => $doneFilled ? $done : null,
                        'acikta_sayi' => $pending,
                        'tone' => $tone,
                    ];
                }
            }

            if (! $hadKalem) {
                $planRaw = $row['hedef'] ?? $row['ongorulen'] ?? null;
                $doneRaw = $row['gerceklesen'] ?? null;
                $planFilled = self::isFilledNumber($planRaw);
                $doneFilled = self::isFilledNumber($doneRaw);
                $plan = self::toFloat($planRaw);
                $done = self::toFloat($doneRaw);
                $bekleyen = self::toFloat($row['bekleyen_is'] ?? 0);
                if ($plan === 0.0 && $done > 0.0) {
                    $pending = max(0.0, $bekleyen);
                } else {
                    $pending = max(0.0, $plan - $done);
                    if ($bekleyen > 0.0) {
                        $pending = max($pending, $bekleyen);
                    }
                }
                $tone = self::tone($planFilled, $doneFilled, $plan, $done, $pending);
                if ($pending > 0.0) {
                    $aciktaKalemSayisi++;
                }
                $toplamGerceklesen += $done;
                $kodToplam += $done;
                $kodPlan += $plan;
                $kalemSatirlari[] = [
                    'kod' => $kod !== '' ? $kod : '—',
                    'aile' => $aile,
                    'kalem' => $aile !== '' ? $aile : 'Faaliyet',
                    'olcu' => $rowOlcu,
                    'ongorulen' => $planFilled ? self::formatNumber($plan) : '—',
                    'gerceklesen' => $doneFilled ? self::formatNumber($done) : '—',
                    'acikta' => (! $planFilled && ! $doneFilled)
                    ? '—'
                    : self::formatNumber($pending),
                    'ongorulen_sayi' => $planFilled ? $plan : null,
                    'gerceklesen_sayi' => $doneFilled ? $done : null,
                    'acikta_sayi' => $pending,
                    'tone' => $tone,
                ];
            }

            if ($kod !== '') {
                $chartCategories[] = $kod;
                $chartValues[] = $kodToplam;
                $chartPlans[] = $kodPlan;
            }
        }

        $durum = 'Kısmi';
        if ($kalemSatirlari === []) {
            $durum = 'Veri yok';
        } elseif ($aciktaKalemSayisi === 0 && $uyarilar === []) {
            $durum = 'Tamamlandı';
        } elseif ($aciktaKalemSayisi > 0) {
            $durum = 'Açık iş var';
        }

        $chartMax = $chartValues === [] ? 0.0 : max($chartValues);

        $screen = [
            'mudurluk_id' => $mudurlukId,
            'mudurluk_adi' => $mudurlukAdi,
            'yil' => $yil,
            'ay' => $ayPadded,
            'hafta' => $haftaNorm,
            'donem_etiketi' => $donem,
            'rapor_var' => true,
            'rapor_id' => (int) $rapor->id,
            'durum' => $durum,
            'ozet' => [
                'kod_sayisi' => count($rows),
                'kalem_sayisi' => count($kalemSatirlari),
                'toplam_gerceklesen' => $toplamGerceklesen,
                'acikta_kalem_sayisi' => $aciktaKalemSayisi,
            ],
            'chart' => [
                'categories' => $chartCategories,
                'values' => $chartValues,
                'planned' => $chartPlans,
                'max' => $chartMax,
            ],
            'rows' => $kalemSatirlari,
            'uyarilar' => array_values(array_unique($uyarilar)),
        ];
        $screen['tavsiye'] = self::tavsiye($screen);

        return $screen;
    }

    /**
     * @return array<string, mixed>
     */
    private static function empty(int $mudurlukId, string $mudurlukAdi, int $yil, string $ay, string $hafta, string $donem): array
    {
        return [
            'mudurluk_id' => $mudurlukId,
            'mudurluk_adi' => $mudurlukAdi,
            'yil' => $yil,
            'ay' => $ay,
            'hafta' => $hafta,
            'donem_etiketi' => $donem,
            'rapor_var' => false,
            'rapor_id' => null,
            'durum' => 'Rapor yok',
            'ozet' => [
                'kod_sayisi' => 0,
                'kalem_sayisi' => 0,
                'toplam_gerceklesen' => 0.0,
                'acikta_kalem_sayisi' => 0,
            ],
            'chart' => [
                'categories' => [],
                'values' => [],
                'planned' => [],
                'max' => 0.0,
            ],
            'rows' => [],
            'uyarilar' => [],
            'tavsiye' => [
                'seviye' => 'info',
                'baslik' => 'Müdürlük ve hafta seçin',
                'ozet' => 'Sistem tavsiyesi: Atandığınız müdürlüğü, ayı ve haftayı seçin; faaliyet raporu ve öneriler otomatik gelir.',
                'maddeler' => [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $screen
     * @return array{seviye: string, baslik: string, ozet: string, maddeler: list<string>}
     */
    public static function tavsiye(array $screen): array
    {
        $mudurluk = trim((string) ($screen['mudurluk_adi'] ?? ''));
        $donem = trim((string) ($screen['donem_etiketi'] ?? ''));
        $prefix = 'Sistem tavsiyesi: ';

        if (($screen['durum'] ?? '') === 'Yetki yok') {
            return [
                'seviye' => 'yuksek',
                'baslik' => 'Yetki yok',
                'ozet' => $prefix.'Bu müdürlük raporunu yalnızca atandığınız birimler için görebilirsiniz.',
                'maddeler' => [],
            ];
        }

        if (! (bool) ($screen['rapor_var'] ?? false)) {
            $kim = $mudurluk !== '' ? $mudurluk : 'Seçilen müdürlük';

            return [
                'seviye' => 'yuksek',
                'baslik' => 'Bu hafta rapor yok',
                'ozet' => $prefix.$kim.($donem !== '' ? ' · '.$donem : '').' için faaliyet raporu girilmemiş. Müdürlükten rapor istenmeli; not “rapor bekleniyor” olarak kaydedilebilir.',
                'maddeler' => [
                    'Rapor gelince aynı ay ve haftayı yeniden seçin; kalemler otomatik dolar.',
                ],
            ];
        }

        $rows = is_array($screen['rows'] ?? null) ? $screen['rows'] : [];
        $acik = [];
        $bos = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['kod'] ?? '').' · '.(string) ($row['kalem'] ?? ''));
            $olcu = trim((string) ($row['olcu'] ?? ''));
            if ($olcu !== '') {
                $label .= ' ('.$olcu.')';
            }
            $tone = (string) ($row['tone'] ?? '');
            if ($tone === 'danger') {
                $acik[] = $label.': açıkta '.(string) ($row['acikta'] ?? '0').' kapatılsın.';
            } elseif ($tone === 'warning') {
                $bos[] = $label.': ölçüye göre sayı girilmemiş; müdürlükten veri tamamlatın.';
            }
        }

        $maddeler = array_values(array_merge(array_slice($acik, 0, 5), array_slice($bos, 0, 3)));
        $acikSayisi = (int) ($screen['ozet']['acikta_kalem_sayisi'] ?? count($acik));

        if ($acikSayisi === 0 && $bos === []) {
            return [
                'seviye' => 'ok',
                'baslik' => 'Hafta kapanmış',
                'ozet' => $prefix.$mudurluk.' bu hafta hedeflerini kapatmış. Analiz notunda sapma yok diye işlenebilir; bir sonraki hafta aynı kalemler izlenmeye devam edilsin.',
                'maddeler' => ['Kritik sapma görünmüyor; takip bir sonraki rapor haftasına bırakılabilir.'],
            ];
        }

        if ($acikSayisi > 0) {
            return [
                'seviye' => 'yuksek',
                'baslik' => 'Açık iş var',
                'ozet' => $prefix.$mudurluk.' raporunda '.$acikSayisi.' kalemde açık iş var. Önce ölçü birimiyle birlikte kırmızı satırlar kapatılsın.',
                'maddeler' => $maddeler !== [] ? $maddeler : ['Açık kalemler kapatılmadan hafta tamamlanmış sayılmamalı.'],
            ];
        }

        return [
            'seviye' => 'orta',
            'baslik' => 'Eksik veri',
            'ozet' => $prefix.$mudurluk.' raporunda henüz sayı girilmemiş kalemler var. Notta veri tamamlama talebi yazılabilir.',
            'maddeler' => $maddeler,
        ];
    }

    private static function periodLabel(int $yil, string $ay, mixed $hafta): string
    {
        $ayInt = (int) $ay;
        $month = $ayInt >= 1 && $ayInt <= 12
            ? ReportPeriodWeeks::turkishMonthName($ayInt).' '.$yil
            : $yil.' / '.$ay;
        $week = ReportPeriodWeeks::periodLabelForRecord($yil, $ay, $hafta)
            ?? (filled($hafta) ? ('Hafta '.$hafta) : 'Hafta seçilmedi');

        return $month.' · '.$week;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, ActivityCatalog>
     */
    private static function loadCatalogs(array $rows): array
    {
        $ids = [];
        $codes = [];
        foreach ($rows as $row) {
            $cid = (int) ($row['activity_catalog_id'] ?? 0);
            if ($cid > 0) {
                $ids[$cid] = true;
            }
            $kod = trim((string) ($row['faaliyet_kodu'] ?? ''));
            if ($kod !== '') {
                $codes[$kod] = true;
            }
        }

        $out = [];
        if ($ids !== []) {
            foreach (ActivityCatalog::query()->whereIn('id', array_keys($ids))->get() as $catalog) {
                $out[(int) $catalog->id] = $catalog;
            }
        }
        if ($codes !== []) {
            foreach (ActivityCatalog::query()->whereIn('faaliyet_kodu', array_keys($codes))->get() as $catalog) {
                $out[(int) $catalog->id] = $catalog;
                $code = trim((string) $catalog->faaliyet_kodu);
                if ($code !== '') {
                    $out['code:'.$code] = $catalog;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int|string, ActivityCatalog>  $catalogs
     */
    private static function catalogForRow(array $row, array $catalogs): ?ActivityCatalog
    {
        $cid = (int) ($row['activity_catalog_id'] ?? 0);
        if ($cid > 0 && isset($catalogs[$cid]) && $catalogs[$cid] instanceof ActivityCatalog) {
            return $catalogs[$cid];
        }
        $kod = trim((string) ($row['faaliyet_kodu'] ?? ''));
        if ($kod !== '' && isset($catalogs['code:'.$kod]) && $catalogs['code:'.$kod] instanceof ActivityCatalog) {
            return $catalogs['code:'.$kod];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function resolveAile(array $row, ?ActivityCatalog $catalog): string
    {
        foreach ([
            $row['kapsam_icerigi'] ?? null,
            $row['faaliyet_ailesi'] ?? null,
            $catalog?->faaliyet_ailesi,
        ] as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $line
     */
    private static function resolveOlcu(array $row, ?array $line, ?ActivityCatalog $catalog): string
    {
        $candidates = [
            $line['olcu_birimi'] ?? null,
            $line['olcu'] ?? null,
            $row['olcu_birimi'] ?? null,
            $catalog?->olcu_birimi,
        ];
        foreach ($candidates as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private static function tone(bool $planFilled, bool $doneFilled, float $plan, float $done, float $pending): string
    {
        if ($pending > 0.0) {
            return 'danger';
        }
        if (! $planFilled && ! $doneFilled) {
            return 'warning';
        }
        if ($planFilled && $plan <= 0.0 && ! $doneFilled) {
            return 'neutral';
        }
        if ($done > 0.0 && $pending <= 0.0) {
            return 'success';
        }

        return 'neutral';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function hydratedFaaliyetRows(AylikFaaliyet $rapor, int $directorateUserId, string $name): array
    {
        $rows = $rapor->faaliyetler;
        if ($rows instanceof \Illuminate\Contracts\Support\Arrayable) {
            $rows = $rows->toArray();
        }
        if (is_string($rows) && $rows !== '') {
            $decoded = json_decode($rows, true);
            $rows = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($rows)) {
            return [];
        }

        $mudurlukAdi = trim($name);
        if ($mudurlukAdi === '' && $directorateUserId > 0) {
            $mudurlukAdi = trim((string) (User::query()->find($directorateUserId)?->name ?? ''));
        }

        $hydrated = ActivityCatalogFormatter::hydrateActivityCatalogIdsInFaaliyetler(
            ['faaliyetler' => $rows],
            $mudurlukAdi !== '' ? $mudurlukAdi : null
        );

        $out = $hydrated['faaliyetler'] ?? [];

        return is_array($out) ? array_values($out) : [];
    }

    private static function isFilledNumber(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return is_numeric($value);
    }

    private static function toFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    private static function formatNumber(float $value): string
    {
        if (abs($value - round($value)) < 0.00001) {
            return number_format($value, 0, ',', '.');
        }

        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }
}
