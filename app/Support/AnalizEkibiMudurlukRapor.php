<?php

namespace App\Support;

use App\Filament\Resources\ActivityReportResource;
use App\Models\AylikFaaliyet;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Analiz ekibi genel bakış: bağlı müdürlüklerin dönem bazlı detaylı raporu.
 */
final class AnalizEkibiMudurlukRapor
{
    /**
     * @return array{
     *     yil: int,
     *     ay: string,
     *     donem_etiketi: string,
     *     ozet: array{
     *         mudurluk_sayisi: int,
     *         rapor_olan: int,
     *         rapor_olmayan: int,
     *         hedef: int,
     *         gerceklesen: int,
     *         kalan: int,
     *         tamamlanma_orani: int|null,
     *         revize_karar: int,
     *         dikkat_sayisi: int,
     *         gecen_ay_fark: int|null
     *     },
     *     mudurlukler: list<array<string, mixed>>
     * }
     */
    public static function buildForUser(User $user, int $yil, int $ay): array
    {
        $ayPadded = str_pad((string) $ay, 2, '0', STR_PAD_LEFT);
        $directorates = $user->assignedDirectorates()
            ->orderBy('users.name')
            ->get(['users.id', 'users.name']);

        $ids = $directorates->pluck('id')->map(fn ($id) => (int) $id)->all();
        $prev = self::previousPeriod($yil, $ayPadded);

        $currentByUser = self::loadPeriodReportsByUser($ids, $yil, $ayPadded);
        $prevByUser = self::loadPeriodReportsByUser($ids, $prev['yil'], $prev['ay']);

        // Katalog etiketlerini tek seferde ısıt.
        $catalogIds = [];
        foreach ([$currentByUser, $prevByUser] as $bucket) {
            foreach ($bucket as $reports) {
                foreach ($reports as $rapor) {
                    $rows = is_array($rapor->faaliyetler) ? $rapor->faaliyetler : [];
                    foreach ($rows as $row) {
                        if (! is_array($row)) {
                            continue;
                        }
                        $cid = (int) ($row['activity_catalog_id'] ?? 0);
                        if ($cid > 0) {
                            $catalogIds[$cid] = true;
                        }
                    }
                }
            }
        }
        ActivityCatalogFormatter::warmLabelCache(array_keys($catalogIds));

        $mudurlukler = [];
        $toplamHedef = 0;
        $toplamGerceklesen = 0;
        $toplamKalan = 0;
        $toplamRevize = 0;
        $toplamDikkat = 0;
        $raporOlan = 0;
        $oncekiToplamGerceklesen = 0;
        $hasPrevious = false;

        foreach ($directorates as $directorate) {
            $uid = (int) $directorate->id;
            $detail = self::buildDirectorateDetailFromReports(
                $uid,
                (string) $directorate->name,
                $yil,
                $ayPadded,
                $currentByUser->get($uid, collect()),
                $prevByUser->get($uid, collect())
            );
            $mudurlukler[] = $detail;

            if ($detail['rapor_var']) {
                $raporOlan++;
            }

            $toplamHedef += (int) $detail['ozet']['hedef'];
            $toplamGerceklesen += (int) $detail['ozet']['gerceklesen'];
            $toplamKalan += (int) $detail['ozet']['kalan'];
            $toplamRevize += (int) $detail['ozet']['revize_karar'];
            $toplamDikkat += (int) $detail['ozet']['dikkat_sayisi'];

            if ($detail['ozet']['gecen_ay_gerceklesen'] !== null) {
                $oncekiToplamGerceklesen += (int) $detail['ozet']['gecen_ay_gerceklesen'];
                $hasPrevious = true;
            }
        }

        $toplam = $toplamHedef > 0 ? $toplamHedef : ($toplamGerceklesen + $toplamKalan);

        return [
            'yil' => $yil,
            'ay' => $ayPadded,
            'donem_etiketi' => ReportPeriodWeeks::turkishMonthName($ay).' '.$yil,
            'ozet' => [
                'mudurluk_sayisi' => $directorates->count(),
                'rapor_olan' => $raporOlan,
                'rapor_olmayan' => max(0, $directorates->count() - $raporOlan),
                'hedef' => $toplamHedef,
                'gerceklesen' => $toplamGerceklesen,
                'kalan' => $toplamKalan,
                'tamamlanma_orani' => AnalizEkibiRaporVerileri::completionPercent($toplamGerceklesen, $toplam),
                'revize_karar' => $toplamRevize,
                'dikkat_sayisi' => $toplamDikkat,
                'gecen_ay_fark' => $hasPrevious ? ($toplamGerceklesen - $oncekiToplamGerceklesen) : null,
            ],
            'mudurlukler' => $mudurlukler,
        ];
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, Collection<int, AylikFaaliyet>>
     */
    private static function loadPeriodReportsByUser(array $userIds, int $yil, string $ayPadded): Collection
    {
        if ($userIds === [] || $yil <= 0) {
            return collect();
        }

        $variants = self::ayVariants($ayPadded);

        return AylikFaaliyet::query()
            ->whereIn('user_id', $userIds)
            ->where('yil', $yil)
            ->whereIn('ay', $variants)
            ->orderBy('hafta')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (AylikFaaliyet $r) => (int) $r->user_id);
    }

    /**
     * @param  Collection<int, AylikFaaliyet>  $currentReports
     * @param  Collection<int, AylikFaaliyet>  $prevReports
     * @return array<string, mixed>
     */
    private static function buildDirectorateDetailFromReports(
        int $directorateUserId,
        string $name,
        int $yil,
        string $ayPadded,
        Collection $currentReports,
        Collection $prevReports
    ): array {
        $exactMatch = $currentReports->isNotEmpty();
        $summary = self::summarizeReports($currentReports);
        $prevSummary = self::summarizeReports($prevReports);

        $faaliyetler = [];
        $dikkatSayisi = 0;
        $allKalemler = [];
        $primary = $currentReports->first();

        if ($exactMatch) {
            foreach ($currentReports as $rapor) {
                $rows = self::hydratedFaaliyetRows($rapor, $directorateUserId, $name);
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $item = self::mapFaaliyetRow($row);
                    if ($item['dikkat']) {
                        $dikkatSayisi++;
                    }
                    $faaliyetler[] = $item;
                    foreach (AnalizEkibiRaporVerileri::buildKalemAnalizi($row) as $kalem) {
                        $allKalemler[] = $kalem;
                    }
                }
            }
        }

        $hedef = (int) ($summary['hedef'] ?? 0);
        $gerceklesen = (int) ($summary['gerceklesen'] ?? 0);
        $kalan = (int) ($summary['kalan'] ?? 0);
        $toplam = $hedef > 0 ? $hedef : ($gerceklesen + $kalan);
        $prevGerceklesen = $exactMatch ? (int) ($prevSummary['gerceklesen'] ?? 0) : null;

        if (! $exactMatch) {
            $hedef = 0;
            $gerceklesen = 0;
            $kalan = 0;
            $toplam = 0;
            $summary['revize_karar'] = 0;
            $prevGerceklesen = null;
        }

        return [
            'directorate_user_id' => $directorateUserId,
            'name' => $name,
            'rapor_var' => $exactMatch,
            'rapor_id' => $exactMatch && $primary ? (int) $primary->id : null,
            'rapor_url' => self::safeReportUrl($exactMatch ? $primary : null),
            'ozet' => [
                'hedef' => $hedef,
                'gerceklesen' => $gerceklesen,
                'kalan' => $kalan,
                'tamamlanma_orani' => AnalizEkibiRaporVerileri::completionPercent($gerceklesen, $toplam),
                'revize_karar' => (int) ($summary['revize_karar'] ?? 0),
                'dikkat_sayisi' => $dikkatSayisi,
                'gecen_ay_gerceklesen' => $prevGerceklesen,
                'gecen_ay_fark' => $prevGerceklesen === null ? null : ($gerceklesen - $prevGerceklesen),
                'kritik_kalem_notu' => AnalizEkibiRaporVerileri::computeKritikKalemNotu($allKalemler),
            ],
            'faaliyetler' => $faaliyetler,
        ];
    }

    /**
     * @param  Collection<int, AylikFaaliyet>  $reports
     * @return array{hedef:int, gerceklesen:int, kalan:int, revize_karar:int}
     */
    private static function summarizeReports(Collection $reports): array
    {
        $hedef = 0;
        $gerceklesen = 0;
        $bekleyen = 0;
        $revizeKarar = 0;

        foreach ($reports as $rapor) {
            $rows = is_array($rapor->faaliyetler) ? $rapor->faaliyetler : [];
            foreach ($rows as $satir) {
                if (! is_array($satir)) {
                    continue;
                }
                $hedef += (int) ($satir['hedef'] ?? 0);
                $gerceklesen += (int) ($satir['gerceklesen'] ?? 0);
                $bekleyen += (int) ($satir['bekleyen_is'] ?? 0);
                if ((bool) ($satir['gerekli_revize'] ?? false)) {
                    $revizeKarar++;
                }
                if (filled($satir['karar_ihtiyaci'] ?? null)) {
                    $revizeKarar++;
                }
            }
        }

        if ($hedef === 0 && $gerceklesen > 0) {
            $kalan = max(0, $bekleyen);
        } else {
            $kalan = max(0, $hedef - $gerceklesen);
            if ($bekleyen > 0) {
                $kalan = max($kalan, $bekleyen);
            }
        }

        return [
            'hedef' => $hedef,
            'gerceklesen' => $gerceklesen,
            'kalan' => $kalan,
            'revize_karar' => $revizeKarar,
        ];
    }

    /**
     * @return array{yil:int, ay:string}
     */
    private static function previousPeriod(int $yil, string $ayPadded): array
    {
        $ay = (int) $ayPadded;
        if ($ay <= 1) {
            return ['yil' => $yil - 1, 'ay' => '12'];
        }

        return ['yil' => $yil, 'ay' => str_pad((string) ($ay - 1), 2, '0', STR_PAD_LEFT)];
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

        $hedef = (int) ($row['hedef'] ?? 0);
        $gerceklesen = (int) ($row['gerceklesen'] ?? 0);
        $bekleyen = (int) ($row['bekleyen_is'] ?? 0);
        if ($hedef === 0 && $gerceklesen > 0) {
            $kalan = max(0, $bekleyen);
        } else {
            $kalan = max(0, $hedef - $gerceklesen);
            if ($bekleyen > 0) {
                $kalan = max($kalan, $bekleyen);
            }
        }

        $revize = (bool) ($row['gerekli_revize'] ?? false);
        $karar = trim((string) ($row['karar_ihtiyaci'] ?? ''));
        $sapma = trim((string) ($row['sapma_nedeni'] ?? ''));
        $escalation = AylikFaaliyetEscalation::itemNeedsUpperManagementAttention($row);
        $dikkat = $revize || $karar !== '' || $escalation;

        return [
            'etiket' => $label,
            'faaliyet_kodu' => trim((string) ($row['faaliyet_kodu'] ?? '')),
            'hedef' => $hedef,
            'gerceklesen' => $gerceklesen,
            'kalan' => $kalan,
            'tamamlanma_orani' => AnalizEkibiRaporVerileri::completionPercent(
                $gerceklesen,
                $hedef > 0 ? $hedef : ($gerceklesen + $kalan)
            ),
            'durum' => AnalizEkibiRaporVerileri::suggestDurum($gerceklesen, $kalan),
            'gerekli_revize' => $revize,
            'karar_ihtiyaci' => $karar,
            'sapma_nedeni' => $sapma,
            'dikkat' => $dikkat,
            'kalemler' => AnalizEkibiRaporVerileri::buildKalemAnalizi($row),
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

    /**
     * @return list<string>
     */
    private static function ayVariants(string $ayPadded): array
    {
        $n = (int) $ayPadded;

        return array_values(array_unique([
            $ayPadded,
            (string) $n,
            str_pad((string) $n, 2, '0', STR_PAD_LEFT),
        ]));
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
