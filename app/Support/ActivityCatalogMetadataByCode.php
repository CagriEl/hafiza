<?php

namespace App\Support;

use App\Services\ActivityCatalogSyncService;
use App\Services\ActivityService;

/**
 * Faaliyet koduna göre raporlama sıklığı ve başkanlık bilgilendirme seviyesi (JSON kaynakları).
 */
final class ActivityCatalogMetadataByCode
{
    /** @var array<string, array{raporlama_sikligi: string, baskanlik_bilgilendirme_seviyesi: string}>|null */
    private static ?array $index = null;

    /**
     * @return array{raporlama_sikligi: string, baskanlik_bilgilendirme_seviyesi: string}
     */
    public static function resolveForCode(?string $code): array
    {
        $code = trim((string) $code);
        if ($code === '') {
            return self::emptyMetadata();
        }

        return self::index()[$code] ?? self::emptyMetadata();
    }

    public static function forgetCache(): void
    {
        self::$index = null;
    }

    /**
     * @return array{raporlama_sikligi: string, baskanlik_bilgilendirme_seviyesi: string}
     */
    public static function mergeWithCatalog(
        ?string $code,
        ?string $catalogRaporlama,
        ?string $catalogBaskanlik
    ): array {
        $json = self::resolveForCode($code);

        return [
            'raporlama_sikligi' => self::firstNonEmpty(
                $json['raporlama_sikligi'],
                trim((string) $catalogRaporlama)
            ),
            'baskanlik_bilgilendirme_seviyesi' => self::firstNonEmpty(
                $json['baskanlik_bilgilendirme_seviyesi'],
                trim((string) $catalogBaskanlik)
            ),
        ];
    }

    /**
     * @return array<string, array{raporlama_sikligi: string, baskanlik_bilgilendirme_seviyesi: string}>
     */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        self::$index = [];

        self::ingestServerSnapshotJson();
        self::ingestActivitySetsJson();

        return self::$index;
    }

    private static function ingestServerSnapshotJson(): void
    {
        $rows = app(ActivityCatalogSyncService::class)->readServerSnapshotRows();
        foreach ($rows as $row) {
            self::upsertRow(
                trim((string) ($row['faaliyet_kodu'] ?? '')),
                trim((string) ($row['raporlama_sikligi'] ?? '')),
                trim((string) ($row['baskanlik_bilgilendirme_seviyesi'] ?? '')),
                true
            );
        }
    }

    private static function ingestActivitySetsJson(): void
    {
        foreach (app(ActivityService::class)->getAllSets() as $set) {
            $activities = $set['activities'] ?? null;
            if (! is_array($activities)) {
                continue;
            }
            foreach ($activities as $activity) {
                if (! is_array($activity)) {
                    continue;
                }
                self::upsertRow(
                    trim((string) ($activity['faaliyet_kodu'] ?? '')),
                    trim((string) ($activity['raporlama_sikligi'] ?? '')),
                    trim((string) ($activity['baskanlik_bilgilendirme_seviyesi'] ?? '')),
                    true
                );
            }
        }
    }

    private static function upsertRow(string $code, string $raporlama, string $baskanlik, bool $overwrite): void
    {
        if ($code === '') {
            return;
        }

        if (! isset(self::$index[$code])) {
            self::$index[$code] = self::emptyMetadata();
        }

        if ($overwrite || $raporlama !== '') {
            if ($overwrite || self::$index[$code]['raporlama_sikligi'] === '') {
                self::$index[$code]['raporlama_sikligi'] = $raporlama;
            }
        }

        if ($overwrite || $baskanlik !== '') {
            if ($overwrite || self::$index[$code]['baskanlik_bilgilendirme_seviyesi'] === '') {
                self::$index[$code]['baskanlik_bilgilendirme_seviyesi'] = $baskanlik;
            }
        }
    }

  /**
     * @return array{raporlama_sikligi: string, baskanlik_bilgilendirme_seviyesi: string}
     */
    private static function emptyMetadata(): array
    {
        return [
            'raporlama_sikligi' => '',
            'baskanlik_bilgilendirme_seviyesi' => '',
        ];
    }

    private static function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
