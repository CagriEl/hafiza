<?php

namespace App\Support;

use App\Filament\Resources\AylikFaaliyetResource;
use App\Models\ActivityCatalog;
use App\Models\AylikFaaliyet;
use Illuminate\Support\Collection;

/**
 * Katalog değişikliklerini mevcut aylık faaliyet raporlarına önizleyip uygulama.
 */
final class ActivityCatalogReportSync
{
    /**
     * @return array{
     *     summary: array{reports: int, rows: int, change_fields: int},
     *     items: list<array<string, mixed>>,
     *     truncated: bool
     * }
     */
    public static function previewForCatalog(ActivityCatalog $catalog, int $limit = 80): array
    {
        $code = trim((string) $catalog->faaliyet_kodu);
        $catalogId = (int) $catalog->id;

        return self::preview(
            fn (array $row): bool => self::rowMatchesCatalog($row, $code, $catalogId),
            $limit
        );
    }

    /**
     * @return array{
     *     summary: array{reports: int, rows: int, change_fields: int},
     *     items: list<array<string, mixed>>,
     *     truncated: bool
     * }
     */
    public static function previewForMudurluk(string $mudurluk, int $limit = 120): array
    {
        $mudurluk = trim($mudurluk);
        $codes = ActivityCatalog::query()
            ->where('mudurluk', $mudurluk)
            ->pluck('faaliyet_kodu')
            ->map(fn ($c) => trim((string) $c))
            ->filter()
            ->values()
            ->all();
        $ids = ActivityCatalog::query()
            ->where('mudurluk', $mudurluk)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return self::preview(
            fn (array $row): bool => self::rowMatchesCatalog(
                $row,
                trim((string) ($row['faaliyet_kodu'] ?? '')),
                (int) ($row['activity_catalog_id'] ?? 0),
                $codes,
                $ids
            ),
            $limit
        );
    }

    /**
     * @param  list<int>  $catalogIds
     * @return array{
     *     summary: array{reports: int, rows: int, change_fields: int},
     *     items: list<array<string, mixed>>,
     *     truncated: bool
     * }
     */
    public static function previewForCatalogIds(array $catalogIds, int $limit = 120): array
    {
        $catalogIds = array_values(array_unique(array_filter(array_map('intval', $catalogIds))));
        if ($catalogIds === []) {
            return self::emptyPreview();
        }

        $catalogs = ActivityCatalog::query()->whereIn('id', $catalogIds)->get(['id', 'faaliyet_kodu']);
        $codes = $catalogs->map(fn (ActivityCatalog $c) => trim((string) $c->faaliyet_kodu))->filter()->values()->all();
        $ids = $catalogs->map(fn (ActivityCatalog $c) => (int) $c->id)->all();

        return self::preview(
            fn (array $row): bool => self::rowMatchesCatalog(
                $row,
                trim((string) ($row['faaliyet_kodu'] ?? '')),
                (int) ($row['activity_catalog_id'] ?? 0),
                $codes,
                $ids
            ),
            $limit
        );
    }

    /**
     * @return array{reports: int, rows: int, change_fields: int}
     */
    public static function applyForCatalog(ActivityCatalog $catalog): array
    {
        $code = trim((string) $catalog->faaliyet_kodu);
        $catalogId = (int) $catalog->id;

        return self::apply(
            fn (array $row): bool => self::rowMatchesCatalog($row, $code, $catalogId)
        );
    }

    /**
     * @return array{reports: int, rows: int, change_fields: int}
     */
    public static function applyForMudurluk(string $mudurluk): array
    {
        $mudurluk = trim($mudurluk);
        $codes = ActivityCatalog::query()
            ->where('mudurluk', $mudurluk)
            ->pluck('faaliyet_kodu')
            ->map(fn ($c) => trim((string) $c))
            ->filter()
            ->values()
            ->all();
        $ids = ActivityCatalog::query()
            ->where('mudurluk', $mudurluk)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return self::apply(
            fn (array $row): bool => self::rowMatchesCatalog(
                $row,
                trim((string) ($row['faaliyet_kodu'] ?? '')),
                (int) ($row['activity_catalog_id'] ?? 0),
                $codes,
                $ids
            )
        );
    }

    /**
     * @param  list<int>  $catalogIds
     * @return array{reports: int, rows: int, change_fields: int}
     */
    public static function applyForCatalogIds(array $catalogIds): array
    {
        $catalogIds = array_values(array_unique(array_filter(array_map('intval', $catalogIds))));
        if ($catalogIds === []) {
            return ['reports' => 0, 'rows' => 0, 'change_fields' => 0];
        }

        $catalogs = ActivityCatalog::query()->whereIn('id', $catalogIds)->get(['id', 'faaliyet_kodu']);
        $codes = $catalogs->map(fn (ActivityCatalog $c) => trim((string) $c->faaliyet_kodu))->filter()->values()->all();
        $ids = $catalogs->map(fn (ActivityCatalog $c) => (int) $c->id)->all();

        return self::apply(
            fn (array $row): bool => self::rowMatchesCatalog(
                $row,
                trim((string) ($row['faaliyet_kodu'] ?? '')),
                (int) ($row['activity_catalog_id'] ?? 0),
                $codes,
                $ids
            )
        );
    }

    /**
     * @param  array{
     *     summary: array{reports: int, rows: int, change_fields: int},
     *     items: list<array<string, mixed>>,
     *     truncated: bool
     * }  $preview
     */
    public static function previewToHtml(array $preview): string
    {
        $summary = $preview['summary'];
        if (($summary['reports'] ?? 0) === 0) {
            return '<div style="padding:8px 0;color:#6b7280;">Bu katalog kaydı için güncellenecek rapor satırı bulunamadı. '
                .'Katalog zaten raporlarla uyumlu olabilir veya henüz bu kodla rapor girilmemiş olabilir.</div>';
        }

        $html = '<div style="margin-bottom:10px;font-size:13px;">'
            .'<b>'.(int) $summary['reports'].'</b> raporda '
            .'<b>'.(int) $summary['rows'].'</b> satır etkilenecek '
            .'('.((int) $summary['change_fields']).' alan değişikliği).'
            .((! empty($preview['truncated'])) ? ' <span style="color:#b45309;">Liste kısaltıldı; uygulamada tümü işlenir.</span>' : '')
            .'</div>';

        $html .= '<div style="max-height:360px;overflow:auto;border:1px solid #e5e7eb;border-radius:8px;">';
        $html .= '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
        $html .= '<thead><tr style="background:#f9fafb;text-align:left;">'
            .'<th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">Rapor</th>'
            .'<th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">Kod</th>'
            .'<th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">Değişiklik</th>'
            .'</tr></thead><tbody>';

        foreach ($preview['items'] as $item) {
            $reportLabel = e((string) ($item['mudurluk'] ?? '—'))
                .' · '.(string) ($item['yil'] ?? '')
                .'/'.(string) ($item['ay'] ?? '')
                .(isset($item['hafta']) && $item['hafta'] !== null && $item['hafta'] !== ''
                    ? ' H'.(string) $item['hafta']
                    : '')
                .' (#'.(int) ($item['report_id'] ?? 0).')';

            $changesHtml = '';
            foreach (($item['changes'] ?? []) as $change) {
                $field = e((string) ($change['field_label'] ?? $change['field'] ?? ''));
                $from = e((string) ($change['from'] ?? '—'));
                $to = e((string) ($change['to'] ?? '—'));
                $changesHtml .= '<div style="margin-bottom:4px;"><b>'.$field.':</b> '
                    .'<span style="color:#b91c1c;">'.$from.'</span> → '
                    .'<span style="color:#047857;">'.$to.'</span></div>';
            }

            $html .= '<tr>'
                .'<td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;vertical-align:top;">'.$reportLabel.'</td>'
                .'<td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;vertical-align:top;">'.e((string) ($item['faaliyet_kodu'] ?? '')).'</td>'
                .'<td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;">'.$changesHtml.'</td>'
                .'</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    /**
     * @param  callable(array<string, mixed>): bool  $rowMatcher
     * @return array{
     *     summary: array{reports: int, rows: int, change_fields: int},
     *     items: list<array<string, mixed>>,
     *     truncated: bool
     * }
     */
    private static function preview(callable $rowMatcher, int $limit, ?string $preferMudurluk = null): array
    {
        $items = [];
        $reportIds = [];
        $rowCount = 0;
        $fieldCount = 0;
        $truncated = false;

        self::eachCandidateReport($preferMudurluk, function (AylikFaaliyet $report) use (
            $rowMatcher,
            $limit,
            &$items,
            &$reportIds,
            &$rowCount,
            &$fieldCount,
            &$truncated
        ): bool {
            $diff = self::diffReport($report, $rowMatcher);
            if ($diff === []) {
                return true;
            }

            $reportIds[$report->id] = true;
            foreach ($diff as $item) {
                $rowCount++;
                $fieldCount += count($item['changes']);
                if (count($items) < $limit) {
                    $items[] = $item;
                } else {
                    $truncated = true;
                }
            }

            return true;
        });

        return [
            'summary' => [
                'reports' => count($reportIds),
                'rows' => $rowCount,
                'change_fields' => $fieldCount,
            ],
            'items' => $items,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  callable(array<string, mixed>): bool  $rowMatcher
     * @return array{reports: int, rows: int, change_fields: int}
     */
    private static function apply(callable $rowMatcher, ?string $preferMudurluk = null): array
    {
        $reports = 0;
        $rows = 0;
        $fields = 0;

        self::eachCandidateReport($preferMudurluk, function (AylikFaaliyet $report) use (
            $rowMatcher,
            &$reports,
            &$rows,
            &$fields
        ): bool {
            $original = is_array($report->faaliyetler) ? $report->faaliyetler : [];
            if ($original === []) {
                return true;
            }

            $hasMatch = false;
            foreach ($original as $row) {
                if (is_array($row) && $rowMatcher($row)) {
                    $hasMatch = true;
                    break;
                }
            }
            if (! $hasMatch) {
                return true;
            }

            $synced = AylikFaaliyetResource::syncFaaliyetlerWithCurrentCatalog(
                ['faaliyetler' => $original],
                $report->user?->name
            );
            $syncedRows = is_array($synced['faaliyetler'] ?? null) ? $synced['faaliyetler'] : $original;
            $newRows = self::replaceMatchingRows($original, $syncedRows, $rowMatcher);

            $diff = self::diffRows($original, $newRows, $rowMatcher, $report);
            if ($diff === []) {
                return true;
            }

            $report->faaliyetler = $newRows;
            $report->save();

            $reports++;
            $rows += count($diff);
            foreach ($diff as $item) {
                $fields += count($item['changes']);
            }

            return true;
        });

        return [
            'reports' => $reports,
            'rows' => $rows,
            'change_fields' => $fields,
        ];
    }

    /**
     * @param  callable(AylikFaaliyet): bool  $callback  return false to stop
     */
    private static function eachCandidateReport(?string $preferMudurluk, callable $callback): void
    {
        $query = AylikFaaliyet::query()->with('user:id,name')->orderBy('id');

        if ($preferMudurluk !== null && $preferMudurluk !== '') {
            $query->whereHas('user', function ($q) use ($preferMudurluk): void {
                $q->where('name', $preferMudurluk);
            });
        }

        $query->chunkById(40, function (Collection $records) use ($callback): void {
            foreach ($records as $report) {
                if (! $report instanceof AylikFaaliyet) {
                    continue;
                }
                if ($callback($report) === false) {
                    return;
                }
            }
        });
    }

    /**
     * @param  callable(array<string, mixed>): bool  $rowMatcher
     * @return list<array<string, mixed>>
     */
    private static function diffReport(AylikFaaliyet $report, callable $rowMatcher): array
    {
        $original = is_array($report->faaliyetler) ? $report->faaliyetler : [];
        if ($original === []) {
            return [];
        }

        $hasMatch = false;
        foreach ($original as $row) {
            if (is_array($row) && $rowMatcher($row)) {
                $hasMatch = true;
                break;
            }
        }
        if (! $hasMatch) {
            return [];
        }

        $synced = AylikFaaliyetResource::syncFaaliyetlerWithCurrentCatalog(
            ['faaliyetler' => $original],
            $report->user?->name
        );
        $newRows = is_array($synced['faaliyetler'] ?? null) ? $synced['faaliyetler'] : $original;

        return self::diffRows($original, $newRows, $rowMatcher, $report);
    }

    /**
     * Katalog sync tüm satırları döndürür; yalnızca eşleşen faaliyet kodlarını yaz, diğer satırlara dokunma.
     *
     * @param  list<array<string, mixed>>|array<int|string, mixed>  $original
     * @param  list<array<string, mixed>>|array<int|string, mixed>  $synced
     * @param  callable(array<string, mixed>): bool  $rowMatcher
     * @return list<array<string, mixed>>
     */
    private static function replaceMatchingRows(array $original, array $synced, callable $rowMatcher): array
    {
        $syncedByKey = [];
        foreach (array_values($synced) as $idx => $row) {
            if (! is_array($row) || ! $rowMatcher($row)) {
                continue;
            }
            $syncedByKey[self::rowKey($row, $idx)] = $row;
        }

        $out = [];
        foreach (array_values($original) as $idx => $row) {
            if (! is_array($row) || ! $rowMatcher($row)) {
                $out[] = $row;

                continue;
            }
            $key = self::rowKey($row, $idx);
            $out[] = $syncedByKey[$key] ?? $row;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>|array<int|string, mixed>  $before
     * @param  list<array<string, mixed>>|array<int|string, mixed>  $after
     * @param  callable(array<string, mixed>): bool  $rowMatcher
     * @return list<array<string, mixed>>
     */
    private static function diffRows(array $before, array $after, callable $rowMatcher, AylikFaaliyet $report): array
    {
        $beforeByKey = [];
        foreach (array_values($before) as $idx => $row) {
            if (! is_array($row)) {
                continue;
            }
            $beforeByKey[self::rowKey($row, $idx)] = $row;
        }

        $items = [];
        foreach (array_values($after) as $idx => $row) {
            if (! is_array($row) || ! $rowMatcher($row)) {
                continue;
            }
            $key = self::rowKey($row, $idx);
            $prev = $beforeByKey[$key] ?? null;
            if (! is_array($prev)) {
                // Kod ile eşleştirmeyi dene
                $code = trim((string) ($row['faaliyet_kodu'] ?? ''));
                foreach ($beforeByKey as $candidate) {
                    if (trim((string) ($candidate['faaliyet_kodu'] ?? '')) === $code && $code !== '') {
                        $prev = $candidate;
                        break;
                    }
                }
            }
            if (! is_array($prev)) {
                continue;
            }

            $changes = self::fieldChanges($prev, $row);
            if ($changes === []) {
                continue;
            }

            $items[] = [
                'report_id' => (int) $report->id,
                'mudurluk' => (string) ($report->user?->name ?? '—'),
                'yil' => $report->yil,
                'ay' => $report->ay,
                'hafta' => $report->hafta ?? null,
                'faaliyet_kodu' => trim((string) ($row['faaliyet_kodu'] ?? '')),
                'changes' => $changes,
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{field: string, field_label: string, from: string, to: string}>
     */
    private static function fieldChanges(array $before, array $after): array
    {
        $map = [
            'kapsam_icerigi' => 'Faaliyet ailesi',
            'olcu_birimi' => 'Ölçü birimi',
            'raporlama_sikligi' => 'Raporlama sıklığı',
            'baskanlik_bilgilendirme_seviyesi' => 'Başkanlık bilgilendirme',
        ];

        $changes = [];
        foreach ($map as $field => $label) {
            $from = trim((string) ($before[$field] ?? ''));
            $to = trim((string) ($after[$field] ?? ''));
            if ($from !== $to) {
                $changes[] = [
                    'field' => $field,
                    'field_label' => $label,
                    'from' => $from !== '' ? $from : '—',
                    'to' => $to !== '' ? $to : '—',
                ];
            }
        }

        $beforeKalemler = self::kapsamKalemList($before);
        $afterKalemler = self::kapsamKalemList($after);
        if ($beforeKalemler !== $afterKalemler) {
            $added = array_values(array_diff($afterKalemler, $beforeKalemler));
            $removed = array_values(array_diff($beforeKalemler, $afterKalemler));
            $from = $beforeKalemler === [] ? '—' : implode(', ', $beforeKalemler);
            $to = $afterKalemler === [] ? '—' : implode(', ', $afterKalemler);
            if ($added !== [] || $removed !== []) {
                $extra = [];
                if ($added !== []) {
                    $extra[] = 'eklenen: '.implode(', ', $added);
                }
                if ($removed !== []) {
                    $extra[] = 'çıkan: '.implode(', ', $removed);
                }
                $to .= ' ('.implode('; ', $extra).')';
            }
            $changes[] = [
                'field' => 'kapsam_verileri',
                'field_label' => 'Kapsam kalemleri',
                'from' => $from,
                'to' => $to,
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private static function kapsamKalemList(array $row): array
    {
        $kv = $row['kapsam_verileri'] ?? null;
        if (! is_array($kv)) {
            return [];
        }
        $out = [];
        foreach ($kv as $line) {
            if (! is_array($line)) {
                continue;
            }
            $kalem = trim((string) ($line['kalem'] ?? ''));
            if ($kalem !== '') {
                $out[] = $kalem;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>|null  $codes
     * @param  list<int>|null  $ids
     */
    private static function rowMatchesCatalog(
        array $row,
        string $code,
        int $catalogId,
        ?array $codes = null,
        ?array $ids = null
    ): bool {
        $rowCode = trim((string) ($row['faaliyet_kodu'] ?? ''));
        $rowId = (int) ($row['activity_catalog_id'] ?? 0);

        if ($codes !== null || $ids !== null) {
            if ($codes !== null && $rowCode !== '' && in_array($rowCode, $codes, true)) {
                return true;
            }
            if ($ids !== null && $rowId > 0 && in_array($rowId, $ids, true)) {
                return true;
            }

            return false;
        }

        if ($code !== '' && $rowCode === $code) {
            return true;
        }

        return $catalogId > 0 && $rowId === $catalogId;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function rowKey(array $row, int $fallbackIndex): string
    {
        $code = trim((string) ($row['faaliyet_kodu'] ?? ''));
        if ($code !== '') {
            return 'code:'.$code;
        }
        $id = (int) ($row['activity_catalog_id'] ?? 0);
        if ($id > 0) {
            return 'id:'.$id;
        }

        return 'idx:'.$fallbackIndex;
    }

    /**
     * @return array{
     *     summary: array{reports: int, rows: int, change_fields: int},
     *     items: list<array<string, mixed>>,
     *     truncated: bool
     * }
     */
    private static function emptyPreview(): array
    {
        return [
            'summary' => ['reports' => 0, 'rows' => 0, 'change_fields' => 0],
            'items' => [],
            'truncated' => false,
        ];
    }
}
