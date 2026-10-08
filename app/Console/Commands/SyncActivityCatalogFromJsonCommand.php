<?php

namespace App\Console\Commands;

use App\Models\ActivityCatalog;
use App\Services\ActivityCatalogRaporlamaSikligiService;
use App\Services\ActivityCatalogSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncActivityCatalogFromJsonCommand extends Command
{
    protected $signature = 'activity-catalog:sync
                            {--path= : faaliyet_seti_full.json dosyasının tam yolu}
                            {--csv-path= : CSV dosyasının tam yolu}
                            {--google-sheet= : Google Sheets paylaşım URL’i}
                            {--gid=0 : Google Sheets sekme gid değeri}
                            {--reset-catalog : activity_catalogs tablosunu import öncesi temizle}
                            {--no-activity-sets : resources/data/activity_sets.json dosyasını güncelleme}
                            {--fill-raporlama-only : Yalnızca boş/geçersiz raporlama_sikligi alanlarını CSV ile doldur}
                            {--raporlama-csv-path= : Raporlama CSV yolu}
                            {--export-raporlama-csv : Snapshot+modelden raporlama CSV üret}
                            {--sync-reports : Mevcut rapor satırlarındaki raporlama sıklığını güncelle}';

    protected $description = 'Faaliyet kataloğunu JSON/CSV kaynağından günceller veya raporlama sıklığını CSV ile tamamlar.';

    public function handle(ActivityCatalogSyncService $sync): int
    {
        if ($this->option('fill-raporlama-only') || $this->option('export-raporlama-csv')) {
            return $this->handleRaporlamaFill();
        }

        $path = $this->option('path') ?: null;
        $csvPath = $this->option('csv-path') ?: null;
        $googleSheet = $this->option('google-sheet') ?: null;
        $gid = (string) ($this->option('gid') ?: '0');

        try {
            if ($this->option('reset-catalog')) {
                ActivityCatalog::query()->delete();
                $this->warn('activity_catalogs tablosu temizlendi.');
            }

            if ($googleSheet) {
                $csvExportUrl = $sync->buildGoogleSheetsCsvExportUrl((string) $googleSheet, $gid);
                $response = Http::timeout(30)->get($csvExportUrl);
                if (! $response->successful()) {
                    $this->error("Google Sheets CSV indirilemedi. HTTP: {$response->status()}");

                    return self::FAILURE;
                }

                $stats = $sync->syncFromCsvString($response->body());
                $this->info("Google Sheets CSV import tamamlandı: {$stats['created']} yeni, {$stats['updated']} güncellendi, {$stats['skipped']} atlandı.");
            } elseif ($csvPath) {
                if (! is_readable((string) $csvPath)) {
                    $this->error("CSV dosyası bulunamadı veya okunamıyor: {$csvPath}");

                    return self::FAILURE;
                }

                $stats = $sync->syncFromCsvFile((string) $csvPath);
                $this->info("CSV import tamamlandı: {$stats['created']} yeni, {$stats['updated']} güncellendi, {$stats['skipped']} atlandı.");
            } else {
                $path ??= $sync->resolveFullJsonPath();
                if (! is_readable($path)) {
                    $this->error("Dosya bulunamadı veya okunamıyor: {$path}");

                    return self::FAILURE;
                }

                $stats = $sync->syncFromFile($path);
                $this->info("JSON upsert tamamlandı: {$stats['created']} yeni, {$stats['updated']} güncellendi, {$stats['skipped']} atlandı.");
            }

            if (! $this->option('no-activity-sets')) {
                $sync->regenerateActivitySetsJsonFromCatalog();
                $this->info('resources/data/activity_sets.json ActivityService ile uyumlu şekilde yenilendi.');
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function handleRaporlamaFill(): int
    {
        $service = app(ActivityCatalogRaporlamaSikligiService::class);

        if ($this->option('export-raporlama-csv')) {
            try {
                $written = $service->exportCsvFromSnapshotAndModel();
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info("CSV üretildi: {$written['path']} ({$written['row_count']} satır)");
        }

        $csvPath = (string) ($this->option('raporlama-csv-path') ?: '');
        if ($csvPath === '') {
            $csvPath = is_readable($service->resolvePublicCsvPath())
                ? $service->resolvePublicCsvPath()
                : $service->resolveDefaultCsvPath();
        }

        if (! is_readable($csvPath)) {
            $this->error("Raporlama CSV bulunamadı: {$csvPath}");
            $this->line('Önce: php artisan activity-catalog:sync --export-raporlama-csv --fill-raporlama-only');

            return self::FAILURE;
        }

        try {
            $stats = $service->fillMissingFromCsvFile($csvPath);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Kaynak: {$csvPath}");
        $this->info("CSV satırı: {$stats['parsed']}");
        $this->info("DB güncellenen: {$stats['updated_db']}");
        $this->info("Snapshot güncellenen: {$stats['updated_snapshot']}");

        if (! $this->option('no-activity-sets')) {
            app(ActivityCatalogSyncService::class)->regenerateActivitySetsJsonFromServerSnapshot();
            $this->info('activity_sets.json sunucu snapshot dosyasından yenilendi.');
        }

        if ($this->option('sync-reports')) {
            $exitCode = $this->call('aylik-faaliyet:sync-catalog-metadata');

            return $exitCode === self::SUCCESS ? self::SUCCESS : $exitCode;
        }

        return self::SUCCESS;
    }
}
