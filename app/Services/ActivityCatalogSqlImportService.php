<?php

namespace App\Services;

use App\Models\ActivityCatalog;
use App\Support\ActivityCatalogMetadataByCode;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Sunucu katalog anlık görüntüsünden (JSON veya SQL) yalnızca mevcut faaliyet kodlarını günceller.
 */
final class ActivityCatalogSqlImportService
{
    public const DEFAULT_SNAPSHOT_PATH = 'resources/data/activity_catalog_server_snapshot.json';

    public const PUBLIC_SQL_FILENAME = 'activity_catalogs.sql';
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'mudurluk',
        'faaliyet_kodu',
        'faaliyet_ailesi',
        'kategori',
        'kapsam',
        'olcu_birimi',
        'kpi_sla',
        'raporlama_sikligi',
        'baskanlik_bilgilendirme_seviyesi',
        'created_at',
        'updated_at',
    ];

    /** @var list<string> */
    private const UPDATABLE_FIELDS = [
        'mudurluk',
        'faaliyet_ailesi',
        'kategori',
        'kapsam',
        'olcu_birimi',
        'kpi_sla',
        'raporlama_sikligi',
        'baskanlik_bilgilendirme_seviyesi',
    ];

    public function resolveDefaultSnapshotPath(): string
    {
        return base_path(self::DEFAULT_SNAPSHOT_PATH);
    }

    /**
     * public_html / Laravel public köküne yüklenen SQL dökümü.
     */
    public function resolvePublicSqlPath(): string
    {
        return public_path(self::PUBLIC_SQL_FILENAME);
    }

    /**
     * @return list<array<string, string>>
     */
    public function readSnapshotRows(?string $path = null): array
    {
        $path ??= $this->resolveDefaultSnapshotPath();

        if (! File::isReadable($path)) {
            return [];
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded)) {
            return [];
        }

        $rows = $decoded['rows'] ?? null;
        if (! is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized[] = [
                'faaliyet_kodu' => $this->normalizeField($row['faaliyet_kodu'] ?? null),
                'mudurluk' => $this->normalizeField($row['mudurluk'] ?? null),
                'faaliyet_ailesi' => $this->normalizeField($row['faaliyet_ailesi'] ?? null),
                'kategori' => $this->normalizeField($row['kategori'] ?? null),
                'kapsam' => $this->normalizeField($row['kapsam'] ?? null),
                'olcu_birimi' => $this->normalizeField($row['olcu_birimi'] ?? null),
                'kpi_sla' => $this->normalizeField($row['kpi_sla'] ?? null),
                'raporlama_sikligi' => $this->normalizeField($row['raporlama_sikligi'] ?? null),
                'baskanlik_bilgilendirme_seviyesi' => $this->normalizeField($row['baskanlik_bilgilendirme_seviyesi'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * @return array{updated: int, skipped_new: int, unchanged: int, parsed: int}
     */
    public function updateExistingFromSnapshotFile(?string $path = null): array
    {
        $path ??= $this->resolveDefaultSnapshotPath();

        if (! File::isReadable($path)) {
            throw new RuntimeException("Katalog snapshot dosyası okunamadı: {$path}");
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Snapshot JSON geçersiz.');
        }

        $rows = $decoded['rows'] ?? null;
        if (! is_array($rows)) {
            throw new RuntimeException('Snapshot içinde rows dizisi bulunamadı.');
        }

        return $this->updateExistingFromRows($this->normalizeSnapshotRows($rows));
    }

    /**
     * Eksik faaliyet kodlarını snapshot'tan oluşturur; mevcut kayıtları değiştirmez.
     *
     * @return array{created: int, skipped_existing: int, parsed: int}
     */
    public function createMissingFromSnapshotFile(?string $path = null): array
    {
        $path ??= $this->resolveDefaultSnapshotPath();

        if (! File::isReadable($path)) {
            throw new RuntimeException("Katalog snapshot dosyası okunamadı: {$path}");
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Snapshot JSON geçersiz.');
        }

        $rows = $decoded['rows'] ?? null;
        if (! is_array($rows)) {
            throw new RuntimeException('Snapshot içinde rows dizisi bulunamadı.');
        }

        return $this->createMissingFromRows($this->normalizeSnapshotRows($rows));
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     * @return array{created: int, skipped_existing: int, parsed: int}
     */
    public function createMissingFromRows(array $rows): array
    {
        $created = 0;
        $skippedExisting = 0;

        foreach ($rows as $row) {
            $code = trim((string) ($row['faaliyet_kodu'] ?? ''));
            if ($code === '') {
                continue;
            }

            if (ActivityCatalog::query()->where('faaliyet_kodu', $code)->exists()) {
                $skippedExisting++;

                continue;
            }

            $payload = ['faaliyet_kodu' => $code];
            foreach (self::UPDATABLE_FIELDS as $field) {
                $payload[$field] = $this->normalizeField($row[$field] ?? null);
            }

            ActivityCatalog::query()->create($payload);
            $created++;
        }

        if ($created > 0) {
            app(ActivityService::class)->forgetCache();
            ActivityCatalogMetadataByCode::forgetCache();
        }

        return [
            'created' => $created,
            'skipped_existing' => $skippedExisting,
            'parsed' => count($rows),
        ];
    }

    /**
     * Admin katalog düzenlemesini snapshot JSON'a yansıtır (sonraki sync'lerin geri almaması için).
     */
    public function upsertCatalogIntoSnapshot(ActivityCatalog $catalog, ?string $path = null): void
    {
        $path ??= $this->resolveDefaultSnapshotPath();
        if (! File::isReadable($path) && ! File::exists(dirname($path))) {
            return;
        }

        $code = trim((string) $catalog->faaliyet_kodu);
        if ($code === '') {
            return;
        }

        $decoded = [];
        if (File::isReadable($path)) {
            $raw = json_decode(File::get($path), true);
            $decoded = is_array($raw) ? $raw : [];
        }

        $rows = is_array($decoded['rows'] ?? null) ? $decoded['rows'] : [];
        $payload = [
            'faaliyet_kodu' => $code,
            'mudurluk' => $this->normalizeField($catalog->mudurluk),
            'faaliyet_ailesi' => $this->normalizeField($catalog->faaliyet_ailesi),
            'kategori' => $this->normalizeField($catalog->kategori),
            'kapsam' => $this->normalizeField($catalog->kapsam),
            'olcu_birimi' => $this->normalizeField($catalog->olcu_birimi),
            'kpi_sla' => $this->normalizeField($catalog->kpi_sla),
            'raporlama_sikligi' => $this->normalizeField($catalog->raporlama_sikligi),
            'baskanlik_bilgilendirme_seviyesi' => $this->normalizeField($catalog->baskanlik_bilgilendirme_seviyesi),
        ];

        $found = false;
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            if (trim((string) ($row['faaliyet_kodu'] ?? '')) !== $code) {
                continue;
            }
            $rows[$index] = array_merge($row, $payload);
            $found = true;
            break;
        }

        if (! $found) {
            $rows[] = $payload;
        }

        usort($rows, fn (array $a, array $b): int => strcmp(
            (string) ($a['faaliyet_kodu'] ?? ''),
            (string) ($b['faaliyet_kodu'] ?? '')
        ));

        $out = [
            'generated_at' => now()->toDateString(),
            'source' => (string) ($decoded['source'] ?? 'activity_catalogs (admin)'),
            'row_count' => count($rows),
            'rows' => array_values($rows),
        ];

        File::put($path, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");
        app(ActivityService::class)->forgetCache();
        ActivityCatalogMetadataByCode::forgetCache();
    }

    public function removeCatalogFromSnapshot(string $faaliyetKodu, ?string $path = null): void
    {
        $path ??= $this->resolveDefaultSnapshotPath();
        $code = trim($faaliyetKodu);
        if ($code === '' || ! File::isReadable($path)) {
            return;
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded) || ! is_array($decoded['rows'] ?? null)) {
            return;
        }

        $rows = array_values(array_filter(
            $decoded['rows'],
            fn ($row): bool => is_array($row) && trim((string) ($row['faaliyet_kodu'] ?? '')) !== $code
        ));

        $decoded['rows'] = $rows;
        $decoded['row_count'] = count($rows);
        $decoded['generated_at'] = now()->toDateString();

        File::put($path, json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");
        app(ActivityService::class)->forgetCache();
        ActivityCatalogMetadataByCode::forgetCache();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, string|null>>
     */
    private function normalizeSnapshotRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized[] = [
                'faaliyet_kodu' => $this->normalizeField($row['faaliyet_kodu'] ?? null),
                'mudurluk' => $this->normalizeField($row['mudurluk'] ?? null),
                'faaliyet_ailesi' => $this->normalizeField($row['faaliyet_ailesi'] ?? null),
                'kategori' => $this->normalizeField($row['kategori'] ?? null),
                'kapsam' => $this->normalizeField($row['kapsam'] ?? null),
                'olcu_birimi' => $this->normalizeField($row['olcu_birimi'] ?? null),
                'kpi_sla' => $this->normalizeField($row['kpi_sla'] ?? null),
                'raporlama_sikligi' => $this->normalizeField($row['raporlama_sikligi'] ?? null),
                'baskanlik_bilgilendirme_seviyesi' => $this->normalizeField($row['baskanlik_bilgilendirme_seviyesi'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * @return array{updated: int, skipped_new: int, unchanged: int, parsed: int}
     */
    public function updateExistingFromSqlFile(string $path): array
    {
        if (! File::isReadable($path)) {
            throw new RuntimeException("SQL dosyası okunamadı: {$path}");
        }

        $rows = $this->parseInsertRows(File::get($path));

        return $this->updateExistingFromRows($rows);
    }

    /**
     * @return array{path: string, row_count: int}
     */
    public function writeSnapshotFromSqlFile(string $sqlPath, ?string $outPath = null): array
    {
        if (! File::isReadable($sqlPath)) {
            throw new RuntimeException("SQL dosyası okunamadı: {$sqlPath}");
        }

        $outPath ??= $this->resolveDefaultSnapshotPath();
        $rows = $this->parseInsertRows(File::get($sqlPath));
        $payload = $this->buildSnapshotPayload($rows);

        File::ensureDirectoryExists(dirname($outPath));
        File::put(
            $outPath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n"
        );

        return [
            'path' => $outPath,
            'row_count' => count($rows),
        ];
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     * @return array{generated_at: string, source: string, row_count: int, rows: list<array<string, string>>}
     */
    public function buildSnapshotPayload(array $rows): array
    {
        $snapshotRows = [];

        foreach ($rows as $row) {
            $snapshotRows[] = [
                'faaliyet_kodu' => $this->normalizeField($row['faaliyet_kodu'] ?? null),
                'mudurluk' => $this->normalizeField($row['mudurluk'] ?? null),
                'faaliyet_ailesi' => $this->normalizeField($row['faaliyet_ailesi'] ?? null),
                'kategori' => $this->normalizeField($row['kategori'] ?? null),
                'kapsam' => $this->normalizeField($row['kapsam'] ?? null),
                'olcu_birimi' => $this->normalizeField($row['olcu_birimi'] ?? null),
                'kpi_sla' => $this->normalizeField($row['kpi_sla'] ?? null),
                'raporlama_sikligi' => $this->normalizeField($row['raporlama_sikligi'] ?? null),
                'baskanlik_bilgilendirme_seviyesi' => $this->normalizeField($row['baskanlik_bilgilendirme_seviyesi'] ?? null),
            ];
        }

        usort($snapshotRows, fn (array $a, array $b): int => strcmp($a['faaliyet_kodu'], $b['faaliyet_kodu']));

        return [
            'generated_at' => now()->toDateString(),
            'source' => 'Sunucu activity_catalogs tablosu (phpMyAdmin dökümü)',
            'row_count' => count($snapshotRows),
            'rows' => $snapshotRows,
        ];
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     * @return array{updated: int, skipped_new: int, unchanged: int, parsed: int}
     */
    public function updateExistingFromRows(array $rows): array
    {
        $updated = 0;
        $skippedNew = 0;
        $unchanged = 0;

        foreach ($rows as $row) {
            $code = trim((string) ($row['faaliyet_kodu'] ?? ''));
            if ($code === '') {
                continue;
            }

            $catalog = ActivityCatalog::query()->where('faaliyet_kodu', $code)->first();
            if (! $catalog instanceof ActivityCatalog) {
                $skippedNew++;

                continue;
            }

            $payload = [];
            foreach (self::UPDATABLE_FIELDS as $field) {
                $payload[$field] = $this->normalizeField($row[$field] ?? null);
            }

            $dirty = false;
            foreach ($payload as $field => $value) {
                if ((string) ($catalog->{$field} ?? '') !== (string) $value) {
                    $dirty = true;
                    break;
                }
            }

            if (! $dirty) {
                $unchanged++;

                continue;
            }

            $catalog->fill($payload);
            $catalog->save();
            $updated++;
        }

        app(ActivityService::class)->forgetCache();
        ActivityCatalogMetadataByCode::forgetCache();

        return [
            'updated' => $updated,
            'skipped_new' => $skippedNew,
            'unchanged' => $unchanged,
            'parsed' => count($rows),
        ];
    }

    /**
     * @return list<array<string, string|null>>
     */
    public function parseInsertRows(string $sql): array
    {
        if (! preg_match(
            '/INSERT\s+INTO\s+`?activity_catalogs`?\s*\([^)]+\)\s*VALUES\s*(.+?);\s*(?:--|ALTER\s+TABLE)/is',
            $sql,
            $matches
        )) {
            throw new RuntimeException('activity_catalogs INSERT bloğu bulunamadı.');
        }

        $valuesBlob = trim($matches[1]);
        $tuples = $this->parseValueTuples($valuesBlob);
        $rows = [];

        foreach ($tuples as $values) {
            if (count($values) !== count(self::COLUMNS)) {
                throw new RuntimeException('SQL satırı beklenen sütun sayısıyla uyuşmuyor.');
            }
            $rows[] = array_combine(self::COLUMNS, $values);
        }

        return $rows;
    }

    /**
     * @return list<string|null>
     */
    private function parseValueTuples(string $valuesBlob): array
    {
        $tuples = [];
        $length = strlen($valuesBlob);
        $index = 0;

        while ($index < $length) {
            while ($index < $length && $valuesBlob[$index] !== '(') {
                $index++;
            }
            if ($index >= $length) {
                break;
            }

            $index++;
            $values = [];

            while ($index < $length) {
                $this->skipWhitespace($valuesBlob, $index);
                if ($index < $length && $valuesBlob[$index] === ')') {
                    break;
                }

                $values[] = $this->parseSqlValue($valuesBlob, $index);
                $this->skipWhitespace($valuesBlob, $index);

                if ($index < $length && $valuesBlob[$index] === ',') {
                    $index++;
                }
            }

            if ($index < $length && $valuesBlob[$index] === ')') {
                $index++;
            }

            $tuples[] = $values;

            while ($index < $length && in_array($valuesBlob[$index], [',', ' ', "\n", "\r", "\t"], true)) {
                $index++;
            }
        }

        return $tuples;
    }

    /**
     * @param  array<int, string>  $blob
     */
    private function parseSqlValue(string $blob, int &$index): ?string
    {
        $this->skipWhitespace($blob, $index);
        if ($index >= strlen($blob)) {
            return null;
        }

        if (str_starts_with(substr($blob, $index), 'NULL')) {
            $index += 4;

            return null;
        }

        if ($blob[$index] === "'") {
            $index++;
            $value = '';

            while ($index < strlen($blob)) {
                $char = $blob[$index];
                if ($char === "'") {
                    if (($index + 1) < strlen($blob) && $blob[$index + 1] === "'") {
                        $value .= "'";
                        $index += 2;

                        continue;
                    }
                    $index++;

                    break;
                }

                $value .= $char;
                $index++;
            }

            return $value;
        }

        if (preg_match('/^-?\d+/', substr($blob, $index), $matches) === 1) {
            $index += strlen($matches[0]);

            return $matches[0];
        }

        throw new RuntimeException('Beklenmeyen SQL değeri: '.substr($blob, $index, 40));
    }

    private function skipWhitespace(string $blob, int &$index): void
    {
        while ($index < strlen($blob) && in_array($blob[$index], [' ', "\n", "\r", "\t"], true)) {
            $index++;
        }
    }

    private function normalizeField(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }
}
