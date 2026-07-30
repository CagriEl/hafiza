<?php

namespace App\Support;

use App\Filament\Resources\ActivityReportResource;
use App\Models\AylikFaaliyet;
use App\Models\User;

/**
 * Analiz / yönetici: bağlı müdürlük + hafta için
 * 0 girilen kodlar ve açıkta kalan işler.
 */
final class AnalizEkibiYoneticiRapor
{
    /**
     * @return array{
     *     mudurluk_id: int,
     *     mudurluk_adi: string,
     *     yil: int,
     *     ay: string,
     *     hafta: string,
     *     donem_etiketi: string,
     *     rapor_var: bool,
     *     rapor_id: int|null,
     *     rapor_url: string|null,
     *     ozet: array{
     *         toplam_kod: int,
     *         sifir_kod_sayisi: int,
     *         acikta_kalem_sayisi: int,
     *         acikta_toplam: float
     *     },
     *     sifir_girilen_kodlar: list<array<string, mixed>>,
     *     acikta_kalan_isler: list<array<string, mixed>>
     * }
     */
    public static function buildForUser(
        User $user,
        int $mudurlukId,
        int $yil,
        int|string $ay,
        mixed $hafta,
    ): array {
        $ayPadded = AylikFaaliyetPeriodMerge::normalizeAy($ay);
        $haftaNorm = ReportPeriodWeeks::normalizeReportHafta($hafta) ?? (string) max(1, (int) $hafta);
        $empty = self::emptyResult($mudurlukId, '', $yil, $ayPadded, $haftaNorm);

        if ($mudurlukId <= 0 || $yil <= 0 || $ayPadded === '') {
            return $empty;
        }

        if (! self::userCanAccessMudurluk($user, $mudurlukId)) {
            return $empty;
        }

        $mudurluk = User::query()->find($mudurlukId);
        $mudurlukAdi = trim((string) ($mudurluk?->name ?? ''));
        $empty['mudurluk_adi'] = $mudurlukAdi !== '' ? $mudurlukAdi : ('#'.$mudurlukId);

        $rapor = self::findWeekReport($mudurlukId, $yil, $ayPadded, $haftaNorm);
        $donemEtiketi = self::periodLabel($yil, $ayPadded, $haftaNorm);

        if (! $rapor instanceof AylikFaaliyet) {
            $empty['mudurluk_adi'] = $empty['mudurluk_adi'];
            $empty['donem_etiketi'] = $donemEtiketi;

            return $empty;
        }

        $rows = self::hydratedFaaliyetRows($rapor, $mudurlukId, $mudurlukAdi);
        $catalogIds = [];
        foreach ($rows as $row) {
            $cid = (int) ($row['activity_catalog_id'] ?? 0);
            if ($cid > 0) {
                $catalogIds[$cid] = true;
            }
        }
        ActivityCatalogFormatter::warmLabelCache(array_keys($catalogIds));

        $sifirKodlar = [];
        $aciktaIsler = [];
        $aciktaToplam = 0.0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $mapped = self::mapFaaliyetRow($row);
            if ((float) $mapped['gerceklesen'] <= 0.0) {
                $sifirKodlar[] = $mapped;
            }

            foreach ($mapped['kalemler'] as $kalem) {
                $pending = (float) ($kalem['acikta'] ?? 0);
                if ($pending <= 0.0 || (bool) ($kalem['kapatildi'] ?? false)) {
                    continue;
                }
                $aciktaToplam += $pending;
                $aciktaIsler[] = [
                    'faaliyet_kodu' => $mapped['faaliyet_kodu'],
                    'etiket' => $mapped['etiket'],
                    'kalem' => $kalem['kalem'],
                    'ongorulen' => $kalem['ongorulen'],
                    'gerceklesen' => $kalem['gerceklesen'],
                    'acikta' => $pending,
                    'durum' => $kalem['durum'],
                    'son_tarih' => $kalem['son_tarih'],
                ];
            }

            // Kapsam yoksa satır düzeyindeki açıkta kalanı listele.
            if ($mapped['kalemler'] === [] && (float) $mapped['kalan'] > 0.0) {
                $aciktaToplam += (float) $mapped['kalan'];
                $aciktaIsler[] = [
                    'faaliyet_kodu' => $mapped['faaliyet_kodu'],
                    'etiket' => $mapped['etiket'],
                    'kalem' => '—',
                    'ongorulen' => $mapped['hedef'],
                    'gerceklesen' => $mapped['gerceklesen'],
                    'acikta' => $mapped['kalan'],
                    'durum' => $mapped['durum'],
                    'son_tarih' => '',
                ];
            }
        }

        usort($sifirKodlar, fn (array $a, array $b): int => strcmp((string) $a['faaliyet_kodu'], (string) $b['faaliyet_kodu']));
        usort($aciktaIsler, function (array $a, array $b): int {
            $cmp = strcmp((string) $a['faaliyet_kodu'], (string) $b['faaliyet_kodu']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) $a['kalem'], (string) $b['kalem']);
        });

        return [
            'mudurluk_id' => $mudurlukId,
            'mudurluk_adi' => $mudurlukAdi !== '' ? $mudurlukAdi : ('#'.$mudurlukId),
            'yil' => $yil,
            'ay' => $ayPadded,
            'hafta' => $haftaNorm,
            'donem_etiketi' => $donemEtiketi,
            'rapor_var' => true,
            'rapor_id' => (int) $rapor->id,
            'rapor_url' => self::safeReportUrl($rapor),
            'ozet' => [
                'toplam_kod' => count($rows),
                'sifir_kod_sayisi' => count($sifirKodlar),
                'acikta_kalem_sayisi' => count($aciktaIsler),
                'acikta_toplam' => $aciktaToplam,
            ],
            'sifir_girilen_kodlar' => $sifirKodlar,
            'acikta_kalan_isler' => $aciktaIsler,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function mudurlukOptionsForUser(User $user): array
    {
        if ($user->isReportingSuperAdmin()) {
            return User::queryMudurlukReportingAccounts()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();
        }

        if ($user->isControlTeam()) {
            return $user->assignedDirectorates()
                ->orderBy('name')
                ->pluck('name', 'users.id')
                ->all();
        }

        if ($user->isViceMayorAccount()) {
            $ids = $user->reportAudienceUserIds() ?? [];
            if ($ids === []) {
                return [];
            }

            return User::query()
                ->whereIn('id', $ids)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();
        }

        return [];
    }

    public static function userCanAccessMudurluk(User $user, int $mudurlukId): bool
    {
        if ($mudurlukId <= 0) {
            return false;
        }

        if ($user->isReportingSuperAdmin()) {
            return true;
        }

        $options = self::mudurlukOptionsForUser($user);

        return array_key_exists($mudurlukId, $options);
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyResult(int $mudurlukId, string $mudurlukAdi, int $yil, string $ay, string $hafta): array
    {
        return [
            'mudurluk_id' => $mudurlukId,
            'mudurluk_adi' => $mudurlukAdi,
            'yil' => $yil,
            'ay' => $ay,
            'hafta' => $hafta,
            'donem_etiketi' => self::periodLabel($yil, $ay, $hafta),
            'rapor_var' => false,
            'rapor_id' => null,
            'rapor_url' => null,
            'ozet' => [
                'toplam_kod' => 0,
                'sifir_kod_sayisi' => 0,
                'acikta_kalem_sayisi' => 0,
                'acikta_toplam' => 0.0,
            ],
            'sifir_girilen_kodlar' => [],
            'acikta_kalan_isler' => [],
        ];
    }

    private static function periodLabel(int $yil, string $ay, string $hafta): string
    {
        $ayInt = (int) $ay;
        $month = $ayInt >= 1 && $ayInt <= 12
            ? ReportPeriodWeeks::turkishMonthName($ayInt).' '.$yil
            : $yil.' / '.$ay;
        $week = ReportPeriodWeeks::periodLabelForRecord($yil, $ay, $hafta)
            ?? ReportPeriodWeeks::weekLabelForRecord($yil, $ay, $hafta)
            ?? ('Hafta '.$hafta);

        return $month.' · '.$week;
    }

    private static function findWeekReport(int $mudurlukId, int $yil, string $ayPadded, string $haftaNorm): ?AylikFaaliyet
    {
        $variants = AylikFaaliyetPeriodMerge::ayQueryVariants($ayPadded);
        $haftaVariants = array_values(array_unique(array_filter([
            $haftaNorm,
            (string) ((int) $haftaNorm),
            is_numeric($haftaNorm) ? (int) $haftaNorm : null,
        ], fn ($v) => $v !== null && $v !== '')));

        return AylikFaaliyet::query()
            ->where('user_id', $mudurlukId)
            ->where('yil', $yil)
            ->whereIn('ay', $variants)
            ->where(function ($q) use ($haftaVariants, $haftaNorm): void {
                $q->whereIn('hafta', $haftaVariants);
                if (ReportPeriodWeeks::isMonthlyPeriod($haftaNorm)) {
                    $q->orWhereIn('hafta', ['aylik', 'Aylık', 'monthly', '0', 0]);
                }
            })
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function mapFaaliyetRow(array $row): array
    {
        $catalogId = (int) ($row['activity_catalog_id'] ?? 0);
        $label = ActivityCatalogFormatter::labelForCatalogId($catalogId)
            ?: trim((string) ($row['faaliyet_kodu'] ?? ''))
            ?: 'Faaliyet';

        $kalemler = [];
        $kv = is_array($row['kapsam_verileri'] ?? null) ? $row['kapsam_verileri'] : [];
        $hedef = 0.0;
        $gerceklesen = 0.0;
        $kalan = 0.0;

        if ($kv !== []) {
            foreach ($kv as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $kalem = trim((string) ($line['kalem'] ?? ''));
                if ($kalem === '') {
                    continue;
                }
                $plan = self::toFloat($line['ongorulen'] ?? $line['deger'] ?? 0);
                $done = self::toFloat($line['gerceklesen'] ?? 0);
                $pending = AylikFaaliyetWeeklyCarryover::kapsamPendingAmount($line);
                $kapatildi = (bool) ($line['acikta_kapatildi'] ?? false);
                $hedef += $plan;
                $gerceklesen += $done;
                if (! $kapatildi) {
                    $kalan += $pending;
                }
                $kalemler[] = [
                    'kalem' => $kalem,
                    'ongorulen' => $plan,
                    'gerceklesen' => $done,
                    'acikta' => $pending,
                    'kapatildi' => $kapatildi,
                    'durum' => AnalizEkibiRaporVerileri::suggestDurum((int) $done, (int) ceil($pending)),
                    'son_tarih' => AylikFaaliyetWeeklyCarryover::formatDisplayDate($line['son_yapilma_tarihi'] ?? null) ?? '',
                ];
            }
        } else {
            $hedef = self::toFloat($row['hedef'] ?? $row['ongorulen'] ?? 0);
            $gerceklesen = self::toFloat($row['gerceklesen'] ?? 0);
            $bekleyen = self::toFloat($row['bekleyen_is'] ?? 0);
            if ($hedef === 0.0 && $gerceklesen > 0.0) {
                $kalan = max(0.0, $bekleyen);
            } else {
                $kalan = max(0.0, $hedef - $gerceklesen);
                if ($bekleyen > 0.0) {
                    $kalan = max($kalan, $bekleyen);
                }
            }
        }

        return [
            'etiket' => $label,
            'faaliyet_kodu' => trim((string) ($row['faaliyet_kodu'] ?? '')),
            'hedef' => $hedef,
            'gerceklesen' => $gerceklesen,
            'kalan' => $kalan,
            'tamamlanma_orani' => AnalizEkibiRaporVerileri::completionPercent(
                (int) round($gerceklesen),
                (int) round($hedef > 0.0 ? $hedef : ($gerceklesen + $kalan))
            ),
            'durum' => AnalizEkibiRaporVerileri::suggestDurum((int) round($gerceklesen), (int) ceil($kalan)),
            'kalemler' => $kalemler,
        ];
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

    private static function safeReportUrl(?AylikFaaliyet $rapor): ?string
    {
        if (! $rapor instanceof AylikFaaliyet) {
            return null;
        }

        try {
            return ActivityReportResource::getUrl('view', ['record' => $rapor]);
        } catch (\Throwable) {
            return null;
        }
    }
}
