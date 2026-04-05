<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * raporlama_modeli_full.json (Excel dışa aktarımı) — kategori → raporlama şekli metni.
 */
final class ReportingModelReader
{
    private const DEFAULT_PATH = 'raporlama_modeli_full.json';

    /** @var list<array<string, mixed>>|null */
    private static ?array $rowsCache = null;

    /**
     * @return list<array<string, mixed>>
     */
    public static function loadRows(?string $path = null): array
    {
        if (self::$rowsCache !== null) {
            return self::$rowsCache;
        }

        $path ??= base_path(self::DEFAULT_PATH);
        if (! File::isReadable($path)) {
            return self::$rowsCache = [];
        }

        $raw = File::get($path);
        $raw = preg_replace('/:\s*NaN\b/', ': null', (string) $raw) ?? $raw;
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return self::$rowsCache = [];
        }

        $out = [];
        foreach ($decoded as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return self::$rowsCache = $out;
    }

    public static function forgetCache(): void
    {
        self::$rowsCache = null;
    }

    /**
     * "Unnamed: 1" sütunundaki kategori adı ile eşleşen satırın raporlama şekli (Unnamed: 3).
     */
    public static function reportingStyleForKategori(string $kategori): ?string
    {
        $needle = TurkishString::normalizeForFuzzyMatch($kategori);
        if ($needle === '') {
            return null;
        }

        foreach (self::loadRows() as $row) {
            $cat = trim((string) ($row['Unnamed: 1'] ?? ''));
            if ($cat === '') {
                continue;
            }
            if (TurkishString::normalizeForFuzzyMatch($cat) !== $needle) {
                continue;
            }
            $style = $row['Unnamed: 3'] ?? null;

            return is_string($style) && $style !== '' ? $style : null;
        }

        return null;
    }
}
