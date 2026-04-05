<?php

namespace App\Services;

use App\Models\ActivityCatalog;
use App\Support\TurkishString;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class ActivityService
{
    private const LAYER_MIN = 1;

    private const LAYER_MAX = 3;

    private string $activitySetsPath;

    private string $reportingRulesPath;

    /** @var array<string, mixed>|null */
    private ?array $activitySetsCache = null;

    /** @var array<string, mixed>|null */
    private ?array $reportingRulesCache = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $faaliyetFullJsonRowsCache = null;

    /** @var array<string, mixed>|null */
    private ?array $lastCatalogResolutionDebug = null;

    public function __construct(
        ?string $activitySetsPath = null,
        ?string $reportingRulesPath = null,
    ) {
        $this->activitySetsPath = $activitySetsPath ?? base_path('resources/data/activity_sets.json');
        $this->reportingRulesPath = $reportingRulesPath ?? base_path('resources/data/reporting_rules.json');
    }

    public function forgetCache(): void
    {
        $this->activitySetsCache = null;
        $this->reportingRulesCache = null;
        $this->faaliyetFullJsonRowsCache = null;
        $this->lastCatalogResolutionDebug = null;
    }

    /**
     * Son katalog çözümlemesi (boş dropdown debug).
     *
     * @return array<string, mixed>|null
     */
    public function getLastCatalogResolutionDebug(): ?array
    {
        return $this->lastCatalogResolutionDebug;
    }

    /**
     * @return list<array{mudurluk: string, activities: list<array<string, mixed>>}>
     */
    public function getAllSets(): array
    {
        $data = $this->loadActivitySets();

        return $data['sets'] ?? [];
    }

    /**
     * Seçilen müdürlüğe göre JSON’daki faaliyet satırları.
     *
     * @return list<array<string, mixed>>
     */
    public function getActivitiesForMudurluk(string $mudurluk): array
    {
        foreach ($this->getAllSets() as $set) {
            $label = (string) ($set['mudurluk'] ?? '');
            if (TurkishString::same($label, $mudurluk) || $this->mudurlukNamesLooselyMatch($label, $mudurluk)) {
                $activities = $set['activities'] ?? [];

                return is_array($activities) ? array_values($activities) : [];
            }
        }

        return [];
    }

    /**
     * Kullanıcı adı ile katalog / JSON müdürlük metni birebir aynı olmayabilir (ek/eksik "Müdürlüğü" vb.).
     * trim + tr küçük harf sonrası tam eşitlik veya anlamlı içerme ile eşler.
     */
    private function mudurlukNamesLooselyMatch(string $catalogSide, string $userSide): bool
    {
        $catalogSide = trim($catalogSide);
        $userSide = trim($userSide);
        if ($catalogSide === '' || $userSide === '') {
            return false;
        }
        if (TurkishString::same($catalogSide, $userSide)) {
            return true;
        }

        $nc = TurkishString::normalizeForFuzzyMatch($catalogSide);
        $nu = TurkishString::normalizeForFuzzyMatch($userSide);
        if ($nc === '' || $nu === '') {
            return false;
        }

        $minLen = min(mb_strlen($nc, 'UTF-8'), mb_strlen($nu, 'UTF-8'));
        if ($minLen < 4) {
            return false;
        }

        return str_contains($nc, $nu) || str_contains($nu, $nc);
    }

    /**
     * Filament seçenekleri: katalog id => faaliyet_ailesi.
     * Öncelik: faaliyet_seti_full.json (Müdürlük sütunu, bulanık eşleşme) → kodlar → activity_catalogs upsert kayıtları.
     *
     * @return array<int|string, string>
     */
    public function getCatalogOptionsForMudurluk(string $mudurluk): array
    {
        return $this->resolveCatalogOptionsForMudurluk($mudurluk)['options'];
    }

    /**
     * @return array{options: array<int|string, string>, debug: array<string, mixed>}
     */
    public function resolveCatalogOptionsForMudurluk(string $mudurluk): array
    {
        $mudurlukRaw = $mudurluk;
        $debug = [
            'directorate_input' => $mudurlukRaw,
            'directorate_normalized' => TurkishString::normalizeForFuzzyMatch($mudurlukRaw),
            'full_json_path' => null,
            'full_json_readable' => false,
            'full_json_matched_rows' => 0,
            'full_json_fuzzy_matched_rows' => 0,
            'codes_from_full_json' => [],
            'fallback_activity_sets' => false,
            'codes_after_fallback' => [],
            'catalog_rows_fetched' => 0,
            'catalog_mudurluk_fallback_used' => false,
            'reason_if_empty' => null,
            'sample_mudurlukler_from_json' => [],
        ];

        $path = app(ActivityCatalogSyncService::class)->resolveFullJsonPath();
        $debug['full_json_path'] = $path;
        $debug['full_json_readable'] = File::isReadable($path);

        $codes = [];
        if ($debug['full_json_readable']) {
            foreach ($this->getFaaliyetFullJsonRows() as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $mCol = trim((string) ($row['Müdürlük'] ?? ''));
                $strict = TurkishString::same($mCol, trim($mudurlukRaw));
                $fuzzy = ! $strict && $this->mudurlukNamesLooselyMatch($mCol, $mudurlukRaw);
                if (! $strict && ! $fuzzy) {
                    continue;
                }
                if ($strict) {
                    $debug['full_json_matched_rows']++;
                } else {
                    $debug['full_json_fuzzy_matched_rows']++;
                }
                $k = trim((string) ($row['Faaliyet Kodu'] ?? ''));
                if ($k !== '') {
                    $codes[] = $k;
                }
            }
            $codes = array_values(array_unique($codes));
        }
        $debug['codes_from_full_json'] = $codes;

        if ($codes === []) {
            $debug['fallback_activity_sets'] = true;
            $codes = collect($this->getActivitiesForMudurluk($mudurlukRaw))
                ->pluck('faaliyet_kodu')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }
        $debug['codes_after_fallback'] = $codes;

        $query = ActivityCatalog::query();
        if (! $query instanceof Builder) {
            $debug['reason_if_empty'] = 'query_builder_unavailable';
            $this->lastCatalogResolutionDebug = $debug;

            return ['options' => [], 'debug' => $debug];
        }

        $query->orderBy('faaliyet_kodu');
        if ($codes !== []) {
            $query->whereIn('faaliyet_kodu', $codes);
        }

        $rows = $query->get();
        $debug['catalog_rows_fetched'] = $rows->count();

        if ($codes !== []) {
            $options = $rows->mapWithKeys(fn (ActivityCatalog $r) => [$r->id => $r->faaliyet_ailesi])->all();
            if ($options === [] && trim($mudurlukRaw) !== '') {
                $debug['catalog_mudurluk_fallback_used'] = true;
                $rows = ActivityCatalog::query()
                    ->orderBy('faaliyet_kodu')
                    ->get()
                    ->filter(fn (ActivityCatalog $r) => $this->mudurlukNamesLooselyMatch($r->mudurluk, $mudurlukRaw));
                $debug['catalog_rows_fetched'] = $rows->count();
                $options = $rows->mapWithKeys(fn (ActivityCatalog $r) => [$r->id => $r->faaliyet_ailesi])->all();
            }
        } else {
            $options = $rows
                ->filter(fn (ActivityCatalog $r) => $this->mudurlukNamesLooselyMatch($r->mudurluk, $mudurlukRaw))
                ->mapWithKeys(fn (ActivityCatalog $r) => [$r->id => $r->faaliyet_ailesi])
                ->all();
        }

        if ($options === [] && trim($mudurlukRaw) !== '') {
            $debug['catalog_mudurluk_fallback_used'] = true;
            $fallbackRows = ActivityCatalog::query()
                ->orderBy('faaliyet_kodu')
                ->get()
                ->filter(fn (ActivityCatalog $r) => $this->mudurlukNamesLooselyMatch($r->mudurluk, $mudurlukRaw));
            $options = $fallbackRows
                ->mapWithKeys(fn (ActivityCatalog $r) => [$r->id => $r->faaliyet_ailesi])
                ->all();
            $debug['catalog_rows_fetched'] = $fallbackRows->count();
        }

        if ($options === []) {
            if (! $debug['full_json_readable']) {
                $debug['reason_if_empty'] = 'faaliyet_seti_full.json_okunamadi';
            } elseif ($debug['full_json_matched_rows'] === 0) {
                $debug['reason_if_empty'] = 'full_jsonda_mudurluk_eslesmedi';
                $debug['sample_mudurlukler_from_json'] = $this->sampleDistinctMudurluksFromFullJson(15);
            } elseif ($debug['catalog_rows_fetched'] === 0) {
                $debug['reason_if_empty'] = 'activity_catalogs_kayit_yok_php_artisan_activity-catalog_sync';
            } else {
                $debug['reason_if_empty'] = 'kodlar_dbde_yok_veya_mudurluk_filtresi';
            }
        }

        $this->lastCatalogResolutionDebug = $debug;

        return ['options' => $options, 'debug' => $debug];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getFaaliyetFullJsonRows(): array
    {
        if ($this->faaliyetFullJsonRowsCache !== null) {
            return $this->faaliyetFullJsonRowsCache;
        }

        $path = app(ActivityCatalogSyncService::class)->resolveFullJsonPath();
        if (! File::isReadable($path)) {
            $this->faaliyetFullJsonRowsCache = [];

            return $this->faaliyetFullJsonRowsCache;
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded)) {
            $this->faaliyetFullJsonRowsCache = [];

            return $this->faaliyetFullJsonRowsCache;
        }

        $out = [];
        foreach ($decoded as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        $this->faaliyetFullJsonRowsCache = $out;

        return $this->faaliyetFullJsonRowsCache;
    }

    /**
     * @return list<string>
     */
    private function sampleDistinctMudurluksFromFullJson(int $limit): array
    {
        $seen = [];
        foreach ($this->getFaaliyetFullJsonRows() as $row) {
            $m = trim((string) ($row['Müdürlük'] ?? ''));
            if ($m === '') {
                continue;
            }
            $key = TurkishString::normalizeForFuzzyMatch($m);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = $m;
            if (count($seen) >= $limit) {
                break;
            }
        }

        return array_values($seen);
    }

    /**
     * @return list<string>
     */
    public function listMudurluklerFromJson(): array
    {
        return collect($this->getAllSets())
            ->pluck('mudurluk')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLayerRules(int $layer): ?array
    {
        $this->assertLayer($layer);
        $layers = $this->loadReportingRules()['layers'] ?? [];

        $key = (string) $layer;

        return isset($layers[$key]) && is_array($layers[$key]) ? $layers[$key] : null;
    }

    public function isLayerEnabled(int $layer): bool
    {
        $rules = $this->getLayerRules($layer);

        return $rules !== null && ($rules['enabled'] ?? true) === true;
    }

    /**
     * Tek bir faaliyet satırı (repeater öğesi) için katman uygunluğu.
     *
     * @param  array<string, mixed>  $activityRow
     * @return array{valid: bool, violations: list<array{field: string, message: string, layer: int}>}
     */
    public function validateLayer(int $layer, array $activityRow): array
    {
        $this->assertLayer($layer);
        $violations = [];

        if (! $this->isLayerEnabled($layer)) {
            return ['valid' => true, 'violations' => []];
        }

        $rules = $this->getLayerRules($layer);
        if ($rules === null) {
            return ['valid' => true, 'violations' => []];
        }

        if ($layer === 1) {
            foreach ($rules['required_fields'] ?? [] as $field) {
                if (! $this->isFieldFilled($activityRow, (string) $field)) {
                    $violations[] = [
                        'field' => (string) $field,
                        'message' => "Katman {$layer}: \"{$field}\" zorunludur.",
                        'layer' => $layer,
                    ];
                }
            }
        }

        if ($layer === 2) {
            foreach ($rules['conditional_required'] ?? [] as $cond) {
                if (! is_array($cond)) {
                    continue;
                }
                if (! $this->evaluateWhen($activityRow, $cond['when'] ?? [])) {
                    continue;
                }
                foreach ($cond['required_fields'] ?? [] as $field) {
                    if (! $this->isFieldFilled($activityRow, (string) $field)) {
                        $violations[] = [
                            'field' => (string) $field,
                            'message' => "Katman {$layer}: koşul sağlandığında \"{$field}\" zorunludur.",
                            'layer' => $layer,
                        ];
                    }
                }
            }
        }

        if ($layer === 3) {
            // Şimdilik yalnızca isteğe bağlı alanlar; ileride required_fields eklenebilir.
            foreach ($rules['required_fields'] ?? [] as $field) {
                if (! $this->isFieldFilled($activityRow, (string) $field)) {
                    $violations[] = [
                        'field' => (string) $field,
                        'message' => "Katman {$layer}: \"{$field}\" zorunludur.",
                        'layer' => $layer,
                    ];
                }
            }
        }

        return [
            'valid' => $violations === [],
            'violations' => $violations,
        ];
    }

    /**
     * Katman 1–3 için sırayla doğrulama (devre dışı katmanlar atlanır).
     *
     * @param  array<string, mixed>  $activityRow
     * @return array{valid: bool, violations: list<array{field: string, message: string, layer: int}>}
     */
    public function validateAllLayers(array $activityRow): array
    {
        $all = [];
        for ($l = self::LAYER_MIN; $l <= self::LAYER_MAX; $l++) {
            $result = $this->validateLayer($l, $activityRow);
            $all = array_merge($all, $result['violations']);
        }

        return ['valid' => $all === [], 'violations' => $all];
    }

    private function assertLayer(int $layer): void
    {
        if ($layer < self::LAYER_MIN || $layer > self::LAYER_MAX) {
            throw new InvalidArgumentException('Raporlama katmanı 1, 2 veya 3 olmalıdır.');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $when
     */
    private function evaluateWhen(array $row, array $when): bool
    {
        $type = $when['type'] ?? '';

        if ($type === 'numeric_less_than') {
            $left = $row[$when['left_field'] ?? ''] ?? null;
            $right = $row[$when['right_field'] ?? ''] ?? null;
            if (! is_numeric($left) || ! is_numeric($right)) {
                return false;
            }

            return (float) $left < (float) $right;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isFieldFilled(array $row, string $field): bool
    {
        if (! array_key_exists($field, $row)) {
            return false;
        }
        $value = $row[$field];
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_numeric($value)) {
            return true;
        }

        return $value !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadActivitySets(): array
    {
        if ($this->activitySetsCache !== null) {
            return $this->activitySetsCache;
        }

        if (! File::isReadable($this->activitySetsPath)) {
            $this->activitySetsCache = ['version' => 0, 'sets' => []];

            return $this->activitySetsCache;
        }

        $decoded = json_decode(File::get($this->activitySetsPath), true);
        if (! is_array($decoded)) {
            $this->activitySetsCache = ['version' => 0, 'sets' => []];

            return $this->activitySetsCache;
        }

        $this->activitySetsCache = $decoded;

        return $this->activitySetsCache;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadReportingRules(): array
    {
        if ($this->reportingRulesCache !== null) {
            return $this->reportingRulesCache;
        }

        if (! File::isReadable($this->reportingRulesPath)) {
            $this->reportingRulesCache = ['version' => 0, 'layers' => []];

            return $this->reportingRulesCache;
        }

        $decoded = json_decode(File::get($this->reportingRulesPath), true);
        if (! is_array($decoded)) {
            $this->reportingRulesCache = ['version' => 0, 'layers' => []];

            return $this->reportingRulesCache;
        }

        $this->reportingRulesCache = $decoded;

        return $this->reportingRulesCache;
    }
}
