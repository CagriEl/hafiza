<?php

namespace App\Support;

use App\Models\ActivityCatalog;
use App\Models\AylikFaaliyet;
use App\Services\ActivityCatalogSyncService;
use App\Services\ActivityService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Katalog kalem revizyonları: DB + rapor JSON (sayısal veriler korunur).
 */
final class CatalogKalemRevisions
{
    public const VERSION = '2026-08-14-kalem-v2';

    private static bool $ensuredThisRequest = false;

    public static function resetEnsureState(): void
    {
        self::$ensuredThisRequest = false;
        \Illuminate\Support\Facades\Cache::forget('catalog_kalem_revisions_applied');
    }

    /**
     * @return list<array{
     *     faaliyet_kodu: string,
     *     kapsam?: string,
     *     legacy_kapsam?: list<string>,
     *     olcu_birimi?: string,
     *     legacy_olcu_birimi?: list<string>,
     *     rename_kalemler?: array<string, string>,
     *     merge_kalemler?: list<array{from: list<string>, to: string}>,
     *     required_kalemler?: list<string>,
     *     forbidden_kalemler?: list<string>
     * }>
     */
    public static function catalogPatches(): array
    {
        return [
            [
                'faaliyet_kodu' => 'ULM-05',
                'kapsam' => 'Geçici trafik planı',
                'legacy_kapsam' => ['Geçici trafik planı ve saha uygulama takibi'],
                'rename_kalemler' => [
                    'Geçici trafik planı ve saha uygulama takibi' => 'Geçici trafik planı',
                ],
                'required_kalemler' => ['Geçici trafik planı'],
                'forbidden_kalemler' => ['Geçici trafik planı ve saha uygulama takibi'],
            ],
            [
                'faaliyet_kodu' => 'SKM-02',
                'kapsam' => 'Su arızası, isale hattı, pompa ve bakım işleri',
                'legacy_kapsam' => ['Su arızası, isale hattı, pompa ve bakım işleri, Arıza kontrol'],
                'forbidden_kalemler' => ['Arıza kontrol'],
            ],
            [
                'faaliyet_kodu' => 'SKM-03',
                'kapsam' => 'Abonelik açma-kapama, değişiklik, Arıza kontrol',
                'legacy_kapsam' => ['Abonelik açma-kapama, değişiklik, bildirim'],
                'required_kalemler' => ['Arıza kontrol'],
                'forbidden_kalemler' => ['bildirim'],
            ],
            [
                'faaliyet_kodu' => 'SKM-04',
                'olcu_birimi' => 'adet / tutanak',
                'legacy_olcu_birimi' => ['işlem / tutanak', 'işlem / tutanak / oran'],
            ],
            [
                'faaliyet_kodu' => 'MKM-01',
                'kapsam' => 'Periyodik bakım, muayene',
                'legacy_kapsam' => ['Periyodik bakım, muayene, kalibrasyon hazırlığı'],
                'forbidden_kalemler' => ['kalibrasyon hazırlığı'],
            ],
            [
                'faaliyet_kodu' => 'MKM-02',
                'kapsam' => 'Arıza giderme, motor, kaporta, kaynak, HVAC, Lastik ve boya atölyeleri, yıkama-yağlama işlemleri',
                'legacy_kapsam' => ['Arıza giderme, motor, kaporta, kaynak, HVAC, Elektrik, Hidrolik'],
                'required_kalemler' => ['Lastik ve boya atölyeleri', 'yıkama-yağlama işlemleri'],
            ],
            [
                'faaliyet_kodu' => 'MKM-03',
                'kapsam' => 'Görev sevk, dönüş kontrolü, şoför ve nöbet planı, Engelli ve hasta taşıma aracı, Araç talepleri, araç kiralama süreçleri',
                'legacy_kapsam' => ['Görev sevk, dönüş kontrolü, şoför ve nöbet planı, Engelli taşıma aracı, Hasta taşıma aracı'],
                'olcu_birimi' => 'sevk',
                'legacy_olcu_birimi' => ['sevk / araç'],
                'merge_kalemler' => [
                    [
                        'from' => ['Engelli taşıma aracı', 'Hasta taşıma aracı'],
                        'to' => 'Engelli ve hasta taşıma aracı',
                    ],
                ],
                'required_kalemler' => ['Engelli ve hasta taşıma aracı', 'Araç talepleri', 'araç kiralama süreçleri'],
                'forbidden_kalemler' => ['Engelli taşıma aracı', 'Hasta taşıma aracı'],
            ],
            [
                'faaliyet_kodu' => 'MKM-04',
                'kapsam' => 'Yakıt tüketimi, verimlilik analizi',
                'legacy_kapsam' => ['Yakıt tüketimi, rölanti, kilometre, verimlilik analizi'],
                'olcu_birimi' => 'lt',
                'legacy_olcu_birimi' => ['lt / km / saat'],
                'forbidden_kalemler' => ['rölanti', 'kilometre'],
            ],
            [
                'faaliyet_kodu' => 'MKM-05',
                'kapsam' => 'Kritik parça, stok, Malzeme girişi',
                'legacy_kapsam' => ['Kritik parça, stok, garanti ve iade süreçleri'],
                'required_kalemler' => ['Malzeme girişi'],
                'forbidden_kalemler' => ['garanti ve iade süreçleri'],
            ],
            [
                'faaliyet_kodu' => 'MKM-06',
                'kapsam' => 'Servis, Avans işlemleri',
                'legacy_kapsam' => ['Servis, muayene-kabul, garanti, teknik alım süreçleri'],
                'required_kalemler' => ['Avans işlemleri'],
                'forbidden_kalemler' => ['muayene-kabul', 'garanti', 'teknik alım süreçleri'],
            ],
        ];
    }

    /**
     * Dosya yüklenince artisan çalışmasa bile rapor/katalog ekranı açılınca bir kez uygular.
     */
    public static function ensureApplied(): void
    {
        if (self::$ensuredThisRequest) {
            return;
        }
        self::$ensuredThisRequest = true;

        try {
            $cached = \Illuminate\Support\Facades\Cache::get('catalog_kalem_revisions_applied');
            if ($cached === self::VERSION && ! self::needsApply()) {
                return;
            }

            self::apply(false);
            \Illuminate\Support\Facades\Cache::forever('catalog_kalem_revisions_applied', self::VERSION);
        } catch (\Throwable $e) {
            self::$ensuredThisRequest = false;
            Log::warning('Katalog kalem revizyonu otomatik uygulanamadı.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function needsApply(): bool
    {
        if (self::catalogHasLegacyValues() || self::catalogMissesTargetValues()) {
            return true;
        }

        return self::reportsNeedKalemSync();
    }

    public static function catalogHasLegacyValues(): bool
    {
        foreach (self::catalogPatches() as $patch) {
            $catalog = ActivityCatalog::query()->where('faaliyet_kodu', $patch['faaliyet_kodu'])->first();
            if (! $catalog instanceof ActivityCatalog) {
                continue;
            }
            $currentKapsam = trim((string) $catalog->kapsam);
            foreach ($patch['legacy_kapsam'] ?? [] as $legacy) {
                if ($currentKapsam === $legacy) {
                    return true;
                }
            }
            $currentOlcu = trim((string) $catalog->olcu_birimi);
            foreach ($patch['legacy_olcu_birimi'] ?? [] as $legacy) {
                if ($currentOlcu === $legacy) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function catalogMissesTargetValues(): bool
    {
        foreach (self::catalogPatches() as $patch) {
            $catalog = ActivityCatalog::query()->where('faaliyet_kodu', $patch['faaliyet_kodu'])->first();
            if (! $catalog instanceof ActivityCatalog) {
                continue;
            }
            if (isset($patch['kapsam']) && trim((string) $catalog->kapsam) !== trim($patch['kapsam'])) {
                return true;
            }
            if (isset($patch['olcu_birimi']) && trim((string) $catalog->olcu_birimi) !== trim($patch['olcu_birimi'])) {
                return true;
            }
        }

        return false;
    }

    public static function reportsNeedKalemSync(): bool
    {
        $patches = [];
        foreach (self::catalogPatches() as $patch) {
            $patches[$patch['faaliyet_kodu']] = $patch;
        }

        foreach (AylikFaaliyet::query()->orderBy('id')->cursor() as $report) {
            foreach ((array) $report->faaliyetler as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $code = trim((string) ($row['faaliyet_kodu'] ?? ''));
                if ($code === '' || ! isset($patches[$code])) {
                    continue;
                }
                $patch = $patches[$code];
                if (isset($patch['olcu_birimi']) && trim((string) ($row['olcu_birimi'] ?? '')) !== trim($patch['olcu_birimi'])) {
                    return true;
                }
                $kalemler = [];
                foreach ((array) ($row['kapsam_verileri'] ?? []) as $line) {
                    if (is_array($line)) {
                        $kalem = trim((string) ($line['kalem'] ?? ''));
                        if ($kalem !== '') {
                            $kalemler[] = $kalem;
                        }
                    }
                }
                foreach ($patch['forbidden_kalemler'] ?? [] as $forbidden) {
                    if (in_array($forbidden, $kalemler, true)) {
                        return true;
                    }
                }
                foreach ($patch['required_kalemler'] ?? [] as $required) {
                    if (! in_array($required, $kalemler, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return array{catalogs: int, reports_renamed: int, reports_synced: int, ulasim_week3_closed: int}
     */
    public static function apply(bool $writeJsonSources = true): array
    {
        $catalogs = self::applyCatalogPatches($writeJsonSources);

        if ($writeJsonSources) {
            self::writeJsonSources();
        }

        $renamed = self::migrateReportKalemNames();
        $synced = self::syncReportsToCatalog();
        $closed = self::completeUlasimJuly2026Week3Pending();

        ActivityCatalogMetadataByCode::forgetCache();
        app(ActivityService::class)->forgetCache();

        return [
            'catalogs' => $catalogs,
            'reports_renamed' => $renamed,
            'reports_synced' => $synced,
            'ulasim_week3_closed' => $closed,
        ];
    }

    private static function applyCatalogPatches(bool $persistJson = true): int
    {
        $updated = 0;
        foreach (self::catalogPatches() as $patch) {
            $code = $patch['faaliyet_kodu'];
            $catalog = ActivityCatalog::query()->where('faaliyet_kodu', $code)->first();
            if (! $catalog instanceof ActivityCatalog) {
                continue;
            }

            $dirty = false;
            if (isset($patch['kapsam']) && trim((string) $catalog->kapsam) !== trim($patch['kapsam'])) {
                $catalog->kapsam = $patch['kapsam'];
                $dirty = true;
            }
            if (isset($patch['olcu_birimi']) && trim((string) $catalog->olcu_birimi) !== trim($patch['olcu_birimi'])) {
                $catalog->olcu_birimi = $patch['olcu_birimi'];
                $dirty = true;
            }
            if ($dirty) {
                $catalog->save();
                $updated++;
            }

            if (! $persistJson) {
                continue;
            }

            try {
                app(ActivityCatalogSyncService::class)->persistAdminCatalogChange($catalog->fresh() ?? $catalog);
            } catch (\Throwable $e) {
                Log::warning('Katalog JSON senkronu atlandı.', [
                    'faaliyet_kodu' => $code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $updated;
    }

    private static function writeJsonSources(): void
    {
        $byCode = [];
        foreach (self::catalogPatches() as $patch) {
            $byCode[$patch['faaliyet_kodu']] = $patch;
        }

        self::patchSnapshotJson($byCode);
        self::patchActivitySetsJson($byCode);
        self::patchFaaliyetSetiFullJson($byCode);
    }

    /**
     * @param  array<string, array<string, mixed>>  $byCode
     */
    private static function patchSnapshotJson(array $byCode): void
    {
        $path = resource_path('data/activity_catalog_server_snapshot.json');
        if (! File::isReadable($path) || ! is_writable($path)) {
            return;
        }
        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded) || ! is_array($decoded['rows'] ?? null)) {
            return;
        }
        foreach ($decoded['rows'] as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = trim((string) ($row['faaliyet_kodu'] ?? ''));
            if (! isset($byCode[$code])) {
                continue;
            }
            if (isset($byCode[$code]['kapsam'])) {
                $decoded['rows'][$i]['kapsam'] = $byCode[$code]['kapsam'];
            }
            if (isset($byCode[$code]['olcu_birimi'])) {
                $decoded['rows'][$i]['olcu_birimi'] = $byCode[$code]['olcu_birimi'];
            }
        }
        File::put($path, json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
    }

    /**
     * @param  array<string, array<string, mixed>>  $byCode
     */
    private static function patchActivitySetsJson(array $byCode): void
    {
        $path = resource_path('data/activity_sets.json');
        if (! File::isReadable($path) || ! is_writable($path)) {
            return;
        }
        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded) || ! is_array($decoded['sets'] ?? null)) {
            return;
        }
        foreach ($decoded['sets'] as $si => $set) {
            if (! is_array($set['activities'] ?? null)) {
                continue;
            }
            foreach ($set['activities'] as $ai => $activity) {
                if (! is_array($activity)) {
                    continue;
                }
                $code = trim((string) ($activity['faaliyet_kodu'] ?? ''));
                if (! isset($byCode[$code])) {
                    continue;
                }
                if (isset($byCode[$code]['kapsam'])) {
                    $decoded['sets'][$si]['activities'][$ai]['kapsam'] = $byCode[$code]['kapsam'];
                }
                if (isset($byCode[$code]['olcu_birimi'])) {
                    $decoded['sets'][$si]['activities'][$ai]['olcu_birimi'] = $byCode[$code]['olcu_birimi'];
                }
            }
        }
        File::put($path, json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
    }

    /**
     * @param  array<string, array<string, mixed>>  $byCode
     */
    private static function patchFaaliyetSetiFullJson(array $byCode): void
    {
        foreach ([base_path('faaliyet_seti_full.json'), resource_path('data/faaliyet_seti_full.json')] as $path) {
            if (! File::isReadable($path) || ! is_writable($path)) {
                continue;
            }
            $decoded = json_decode(File::get($path), true);
            if (! is_array($decoded)) {
                continue;
            }
            $rows = $decoded['Faaliyetler'] ?? $decoded;
            if (! is_array($rows)) {
                continue;
            }
            $isWrapped = isset($decoded['Faaliyetler']);
            foreach ($rows as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $code = trim((string) ($row['Faaliyet Kodu'] ?? $row['faaliyet_kodu'] ?? ''));
                if (! isset($byCode[$code])) {
                    continue;
                }
                if (isset($byCode[$code]['kapsam'])) {
                    if (array_key_exists('Kapsam', $row)) {
                        $rows[$i]['Kapsam'] = $byCode[$code]['kapsam'];
                    } else {
                        $rows[$i]['kapsam'] = $byCode[$code]['kapsam'];
                    }
                }
                if (isset($byCode[$code]['olcu_birimi'])) {
                    if (array_key_exists('Ölçü Birimi', $row)) {
                        $rows[$i]['Ölçü Birimi'] = $byCode[$code]['olcu_birimi'];
                    } else {
                        $rows[$i]['olcu_birimi'] = $byCode[$code]['olcu_birimi'];
                    }
                }
            }
            if ($isWrapped) {
                $decoded['Faaliyetler'] = $rows;
                File::put($path, json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
            } else {
                File::put($path, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
            }
        }
    }

    private static function migrateReportKalemNames(): int
    {
        $renameByCode = [];
        $mergeByCode = [];
        foreach (self::catalogPatches() as $patch) {
            $code = $patch['faaliyet_kodu'];
            if (! empty($patch['rename_kalemler'])) {
                $renameByCode[$code] = $patch['rename_kalemler'];
            }
            if (! empty($patch['merge_kalemler'])) {
                $mergeByCode[$code] = $patch['merge_kalemler'];
            }
        }

        $updated = 0;
        AylikFaaliyet::query()->orderBy('id')->chunkById(40, function ($records) use ($renameByCode, $mergeByCode, &$updated): void {
            foreach ($records as $report) {
                if (! $report instanceof AylikFaaliyet) {
                    continue;
                }
                $rows = is_array($report->faaliyetler) ? $report->faaliyetler : [];
                if ($rows === []) {
                    continue;
                }
                $changed = false;
                foreach ($rows as $i => $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $code = trim((string) ($row['faaliyet_kodu'] ?? ''));
                    if (isset($renameByCode[$code])) {
                        [$row, $did] = self::renameKalemlerInRow($row, $renameByCode[$code]);
                        $changed = $changed || $did;
                    }
                    if (isset($mergeByCode[$code])) {
                        [$row, $did] = self::mergeKalemlerInRow($row, $mergeByCode[$code]);
                        $changed = $changed || $did;
                    }
                    $rows[$i] = $row;
                }
                if (! $changed) {
                    continue;
                }
                $report->faaliyetler = $rows;
                $report->save();
                $updated++;
            }
        });

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $map
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private static function renameKalemlerInRow(array $row, array $map): array
    {
        $changed = false;
        $kv = $row['kapsam_verileri'] ?? null;
        if (is_array($kv)) {
            foreach ($kv as $j => $line) {
                if (! is_array($line)) {
                    continue;
                }
                $kalem = trim((string) ($line['kalem'] ?? ''));
                if (isset($map[$kalem])) {
                    $kv[$j]['kalem'] = $map[$kalem];
                    $changed = true;
                }
            }
            $row['kapsam_verileri'] = $kv;
        }

        $icerik = trim((string) ($row['kapsam_icerigi'] ?? ''));
        if (isset($map[$icerik])) {
            $row['kapsam_icerigi'] = $map[$icerik];
            $changed = true;
        }

        return [$row, $changed];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{from: list<string>, to: string}>  $merges
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private static function mergeKalemlerInRow(array $row, array $merges): array
    {
        $kv = $row['kapsam_verileri'] ?? null;
        if (! is_array($kv)) {
            return [$row, false];
        }

        $changed = false;
        foreach ($merges as $merge) {
            $from = $merge['from'];
            $to = $merge['to'];
            $picked = [];
            $rest = [];
            foreach ($kv as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $kalem = trim((string) ($line['kalem'] ?? ''));
                if (in_array($kalem, $from, true)) {
                    $picked[] = $line;
                } else {
                    $rest[] = $line;
                }
            }
            if ($picked === []) {
                continue;
            }
            $combined = $picked[0];
            for ($i = 1; $i < count($picked); $i++) {
                $combined = self::sumKapsamLines($combined, $picked[$i]);
            }
            $combined['kalem'] = $to;
            $combined['acikta_kalan'] = AylikFaaliyetRepeaterLock::kapsamSatirAciktaKalan($combined);
            $rest[] = $combined;
            $kv = $rest;
            $changed = true;
        }

        $row['kapsam_verileri'] = $kv;

        return [$row, $changed];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private static function sumKapsamLines(array $a, array $b): array
    {
        $out = $a;
        foreach (['ongorulen', 'gerceklesen', 'deger', 'not_ile_kapatilan'] as $key) {
            $va = is_numeric($a[$key] ?? null) ? (float) $a[$key] : null;
            $vb = is_numeric($b[$key] ?? null) ? (float) $b[$key] : null;
            if ($va === null && $vb === null) {
                continue;
            }
            $out[$key] = ($va ?? 0.0) + ($vb ?? 0.0);
        }

        $ha = is_array($a['haftalik_kayitlar'] ?? null) ? $a['haftalik_kayitlar'] : [];
        $hb = is_array($b['haftalik_kayitlar'] ?? null) ? $b['haftalik_kayitlar'] : [];
        if ($ha !== [] || $hb !== []) {
            $out['haftalik_kayitlar'] = array_values(array_merge($ha, $hb));
        }

        return $out;
    }

    private static function syncReportsToCatalog(): int
    {
        $ids = [];
        foreach (self::catalogPatches() as $patch) {
            $id = (int) (ActivityCatalog::query()->where('faaliyet_kodu', $patch['faaliyet_kodu'])->value('id') ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return 0;
        }

        $stats = ActivityCatalogReportSync::applyForCatalogIds($ids);

        return (int) ($stats['reports'] ?? 0);
    }

    private static function completeUlasimJuly2026Week3Pending(): int
    {
        $closed = 0;
        $reports = AylikFaaliyet::query()
            ->with('user:id,name')
            ->where('yil', 2026)
            ->where('ay', '07')
            ->where('hafta', 3)
            ->get();

        foreach ($reports as $report) {
            $name = (string) ($report->user?->name ?? '');
            if (! str_contains($name, 'Ulaşım')) {
                continue;
            }
            $rows = is_array($report->faaliyetler) ? $report->faaliyetler : [];
            $changed = false;
            foreach ($rows as $i => $row) {
                if (! is_array($row) || ! is_array($row['kapsam_verileri'] ?? null)) {
                    continue;
                }
                foreach ($row['kapsam_verileri'] as $j => $line) {
                    if (! is_array($line)) {
                        continue;
                    }
                    $pending = AylikFaaliyetWeeklyCarryover::kapsamPendingAmount($line);
                    if ($pending <= 0.0) {
                        continue;
                    }
                    $ong = $line['ongorulen'] ?? $line['deger'] ?? null;
                    if (! is_numeric($ong)) {
                        continue;
                    }
                    $rows[$i]['kapsam_verileri'][$j]['gerceklesen'] = $ong;
                    $rows[$i]['kapsam_verileri'][$j]['acikta_kalan'] = AylikFaaliyetRepeaterLock::kapsamSatirAciktaKalan(
                        array_merge($line, ['gerceklesen' => $ong])
                    );
                    $changed = true;
                    $closed++;
                }
            }
            if (! $changed) {
                continue;
            }
            $synced = AylikFaaliyetRepeaterLock::syncRowAySonuTotalsFromKapsamVerileri(['faaliyetler' => $rows]);
            $report->faaliyetler = $synced['faaliyetler'] ?? $rows;
            $report->save();
        }

        return $closed;
    }
}
