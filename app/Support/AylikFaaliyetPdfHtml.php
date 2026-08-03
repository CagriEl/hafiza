<?php

namespace App\Support;

use App\Filament\Resources\AylikFaaliyetResource;
use App\Models\AylikFaaliyet;
use App\Models\ExtraordinarySituation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Aylık faaliyet listesi PDF HTML üretimi (N+1 olmadan).
 * Aynı müdürlük + yıl + ay kayıtları tek satırda, haftalar sıralı birleştirilir.
 */
final class AylikFaaliyetPdfHtml
{
    /**
     * @param  Collection<int, AylikFaaliyet>|iterable<AylikFaaliyet>  $records
     */
    public static function render(iterable $records): string
    {
        $collection = $records instanceof Collection ? $records : collect($records);
        $collection->loadMissing('user');

        $extraordinaryByKey = self::loadExtraordinaryIndex($collection);
        $reporterNames = self::loadReporterNames($extraordinaryByKey);

        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
            <style>
                body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #333; }
                table { width: 100%; border-collapse: collapse; margin-top: 15px; }
                th, td { padding: 6px; border: 1px solid #999; text-align: left; }
                th { background-color: #f2f2f2; font-weight: bold; }
                .title { text-align: center; font-size: 14px; font-weight: bold; margin-bottom: 10px; }
            </style>
        </head>
        <body>
            <div class="title">AYLIK FAALİYET VE PLANLAMA GENEL RAPORU</div>
            <p style="text-align:right">Rapor Tarihi: '.now()->format('d.m.Y').'</p>

            <table>
                <thead>
                    <tr>
                        <th width="15%">Müdürlük</th>
                        <th width="10%">Dönem</th>
                        <th width="75%">Faaliyet Detayları (Konu - Durum - Son Tarih)</th>
                    </tr>
                </thead>
                <tbody>';

        foreach (self::groupByPeriod($collection) as $group) {
            /** @var Collection<int, AylikFaaliyet> $group */
            $seed = $group->first();
            if (! $seed instanceof AylikFaaliyet) {
                continue;
            }

            // Seçimde tek hafta olsa bile o aya ait tüm haftalık raporları sırayla al.
            $periodReports = AylikFaaliyetResource::monthPeriodReports($seed);
            $record = $periodReports->first() ?? $seed;
            $isler = self::mergedFaaliyetRowsForPeriod($periodReports);
            $isDetaylari = '';

            $ayPad = str_pad(preg_replace('/\D/', '', (string) ($record->ay ?? '')) ?: '', 2, '0', STR_PAD_LEFT);
            $extraKey = ((int) ($record->user_id ?? 0)).'|'.((int) ($record->yil ?? 0)).'|'.$ayPad;
            $extraordinary = $extraordinaryByKey[$extraKey] ?? null;
            $extraordinaryText = null;
            if ($extraordinary instanceof ExtraordinarySituation) {
                $reporterId = (int) ($extraordinary->reporter_user_id ?? 0);
                $reporterName = $reporterNames[$reporterId] ?? 'Sistem';
                $message = trim((string) ($extraordinary->message ?? ''));
                $extraordinaryText = $message === '' ? $reporterName : $reporterName.': '.$message;
            }

            foreach ($isler as $is) {
                if (! is_array($is) || ! AylikFaaliyetResource::faaliyetRowHasEnteredQuantity($is)) {
                    continue;
                }

                $durum = match ($is['durum'] ?? '') {
                    'tamam' => 'Tamamlandı',
                    'devam' => 'Devam Ediyor',
                    'bekliyor' => 'Planlandı',
                    default => $is['durum'] ?? ''
                };

                $sonTarih = isset($is['son_tarih']) ? Carbon::parse($is['son_tarih'])->format('d.m.Y') : '-';
                $baslik = trim((string) ($is['konu'] ?? ''));
                if ($baslik === '') {
                    $baslik = trim((string) ($is['faaliyet_kodu'] ?? 'Faaliyet'));
                }

                $kapsamIcerigi = trim((string) ($is['kapsam_icerigi'] ?? ''));
                $olcuBirimi = trim((string) ($is['olcu_birimi'] ?? ''));
                $gerceklesen = AylikFaaliyetResource::formatPdfQuantity($is['gerceklesen'] ?? null);
                $bekleyen = AylikFaaliyetResource::formatPdfQuantity($is['bekleyen_is'] ?? null);

                $kapsamKalemleri = '';
                $satirlar = $is['kapsam_verileri'] ?? [];
                if (is_array($satirlar) && $satirlar !== []) {
                    $pairs = [];
                    foreach ($satirlar as $satir) {
                        if (! is_array($satir) || ! AylikFaaliyetResource::kapsamRowHasEnteredQuantity($satir)) {
                            continue;
                        }
                        $kalem = trim((string) ($satir['kalem'] ?? ''));
                        if ($kalem === '') {
                            continue;
                        }
                        $ong = AylikFaaliyetResource::formatPdfQuantity($satir['ongorulen'] ?? $satir['deger'] ?? null);
                        $ger = AylikFaaliyetResource::formatPdfQuantity($satir['gerceklesen'] ?? null);
                        $acik = AylikFaaliyetWeeklyCarryover::kapsamPendingAmount($satir);
                        $acikText = AylikFaaliyetResource::formatPdfQuantityWhenProvided(
                            $acik,
                            AylikFaaliyetResource::kapsamRowHasEnteredQuantity($satir)
                        );
                        $parts = [];
                        if ($ong !== '') {
                            $parts[] = 'yapılacak '.$ong;
                        }
                        if ($ger !== '') {
                            $parts[] = 'yapılan '.$ger;
                        }
                        if ($acikText !== '') {
                            $parts[] = 'bekleyen '.$acikText;
                        }
                        $pairs[] = e($kalem).($parts !== [] ? ': '.e(implode(' / ', $parts)) : '');
                    }
                    if ($pairs !== []) {
                        $kapsamKalemleri = '<br><b>Kapsam Kalemleri:</b> '.implode(' | ', $pairs);
                    }
                }

                $haftaLabel = ReportPeriodWeeks::weekLabelForRecord(
                    (int) ($record->yil ?? 0),
                    $record->ay ?? null,
                    $is['hafta'] ?? null
                );

                $aySonuParts = [];
                if ($gerceklesen !== '') {
                    $aySonuParts[] = 'gerçekleşen '.$gerceklesen;
                }
                if ($bekleyen !== '') {
                    $aySonuParts[] = 'bekleyen '.$bekleyen;
                }
                $aySonuLine = $aySonuParts !== []
                    ? '<br><b>Ay sonu:</b> '.e(implode(' / ', $aySonuParts))
                    : '';

                $isDetaylari .= "<div style='margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 4px;'>
                                    <b>[".e((string) $durum).']</b> '.e($baslik).'
                                    '.($haftaLabel ? '<br><b>Hafta:</b> '.e($haftaLabel) : '').'
                                    '.$aySonuLine.'
                                    '.($olcuBirimi !== '' ? '<br><b>Ölçü birimi:</b> '.e($olcuBirimi) : '').'
                                    '.($kapsamIcerigi !== '' ? '<br><b>Kapsam:</b> '.e($kapsamIcerigi) : '').'
                                    '.(filled($extraordinaryText) ? '<br><b>Olağanüstü durum:</b> '.e((string) $extraordinaryText) : '').'
                                    '.$kapsamKalemleri.'
                                    <br><b>Bitiş:</b> '.$sonTarih.'
                                 </div>';
            }

            if ($isDetaylari === '') {
                continue;
            }

            $yil = (int) ($record->yil ?? 0);
            $ay = (int) preg_replace('/\D/', '', (string) ($record->ay ?? ''));
            $donem = $yil > 0 && $ay >= 1 && $ay <= 12
                ? e(ReportPeriodWeeks::monthPeriodLabel($yil, $ay))
                : e((string) ($record->yil.' / '.$record->ay));
            $kayitTarihi = e(AylikFaaliyetResource::reportRecordSavedAtLabel($record) ?? '—');
            $raporHaftalari = e(AylikFaaliyetResource::reportAssignedWeeksSummary($record, true) ?? '—');

            $html .= '<tr>
                        <td>'.e($record->user->name ?? 'Belirtilmemiş').'</td>
                        <td>'.$donem.'<br><small>Kayıt: '.$kayitTarihi.'</small><br><small>Haftalar: '.$raporHaftalari.'</small></td>
                        <td>'.$isDetaylari.'</td>
                      </tr>';
        }

        $html .= '</tbody></table></body></html>';

        return $html;
    }

    /**
     * @param  Collection<int, AylikFaaliyet>  $records
     * @return Collection<int, Collection<int, AylikFaaliyet>>
     */
    private static function groupByPeriod(Collection $records): Collection
    {
        return $records
            ->groupBy(function (AylikFaaliyet $record): string {
                $ay = AylikFaaliyetPeriodMerge::normalizeAy((string) ($record->ay ?? ''));

                return ((int) ($record->user_id ?? 0)).'|'.((int) ($record->yil ?? 0)).'|'.$ay;
            })
            ->sortBy(function (Collection $group, string $key): string {
                return $key;
            })
            ->map(function (Collection $group): Collection {
                return $group
                    ->sortBy(function (AylikFaaliyet $record): int {
                        return (self::haftaSortKey($record->hafta ?? null) * 1_000_000)
                            + (int) ($record->id ?? 0);
                    })
                    ->values();
            })
            ->values();
    }

    /**
     * @param  Collection<int, AylikFaaliyet>  $group
     * @return list<array<string, mixed>>
     */
    private static function mergedFaaliyetRowsForPeriod(Collection $group): array
    {
        $merged = [];
        foreach ($group as $sibling) {
            if (! $sibling instanceof AylikFaaliyet) {
                continue;
            }
            $reportHafta = ReportPeriodWeeks::normalizeReportHafta($sibling->hafta ?? null);
            $rows = is_string($sibling->faaliyetler)
                ? json_decode($sibling->faaliyetler, true)
                : $sibling->faaliyetler;
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (($row['hafta'] ?? null) === null || $row['hafta'] === '') {
                    if ($reportHafta !== null) {
                        $row['hafta'] = ReportPeriodWeeks::isMonthlyPeriod($reportHafta)
                            ? ReportPeriodWeeks::MONTHLY_VALUE
                            : (int) $reportHafta;
                    }
                }
                $merged[] = $row;
            }
        }

        usort($merged, function (array $a, array $b): int {
            $wa = self::haftaSortKey($a['hafta'] ?? null);
            $wb = self::haftaSortKey($b['hafta'] ?? null);
            if ($wa !== $wb) {
                return $wa <=> $wb;
            }

            return strcmp(
                trim((string) ($a['faaliyet_kodu'] ?? $a['konu'] ?? '')),
                trim((string) ($b['faaliyet_kodu'] ?? $b['konu'] ?? ''))
            );
        });

        return $merged;
    }

    private static function haftaSortKey(mixed $hafta): int
    {
        $raw = mb_strtolower(trim((string) ($hafta ?? '')), 'UTF-8');
        if (in_array($raw, ['aylik', 'aylık', 'monthly', '0'], true) || $hafta === 0) {
            return 99;
        }

        $normalized = ReportPeriodWeeks::normalizeReportHafta($hafta);
        if ($normalized === ReportPeriodWeeks::MONTHLY_VALUE) {
            return 99;
        }
        if ($normalized !== null && is_numeric($normalized)) {
            return (int) $normalized;
        }

        if (is_numeric($hafta)) {
            $week = (int) $hafta;
            if ($week >= 1 && $week <= ReportPeriodWeeks::WEEK_COUNT) {
                return $week;
            }
        }

        return 50;
    }

    /**
     * @param  Collection<int, AylikFaaliyet>  $records
     * @return array<string, ExtraordinarySituation>
     */
    private static function loadExtraordinaryIndex(Collection $records): array
    {
        $userIds = $records->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->filter()->values()->all();
        if ($userIds === []) {
            return [];
        }

        $rows = ExtraordinarySituation::query()
            ->whereIn('target_user_id', $userIds)
            ->orderByDesc('id')
            ->get(['id', 'target_user_id', 'reporter_user_id', 'yil', 'ay', 'message']);

        $index = [];
        foreach ($rows as $row) {
            $ayPad = str_pad(preg_replace('/\D/', '', (string) ($row->ay ?? '')) ?: '', 2, '0', STR_PAD_LEFT);
            $key = ((int) $row->target_user_id).'|'.((int) $row->yil).'|'.$ayPad;
            if (! isset($index[$key])) {
                $index[$key] = $row;
            }
        }

        return $index;
    }

    /**
     * @param  array<string, ExtraordinarySituation>  $extraordinaryByKey
     * @return array<int, string>
     */
    private static function loadReporterNames(array $extraordinaryByKey): array
    {
        $ids = [];
        foreach ($extraordinaryByKey as $row) {
            $id = (int) ($row->reporter_user_id ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', array_keys($ids))
            ->pluck('name', 'id')
            ->map(fn ($name) => trim((string) $name))
            ->all();
    }
}
