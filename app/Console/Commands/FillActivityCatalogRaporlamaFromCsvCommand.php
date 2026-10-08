<?php

namespace App\Console\Commands;

use App\Services\ActivityCatalogRaporlamaSikligiService;
use App\Services\ActivityCatalogSyncService;
use Illuminate\Console\Command;

class FillActivityCatalogRaporlamaFromCsvCommand extends Command
{
    protected $signature = 'activity-catalog:fill-raporlama-from-csv
                            {path? : CSV yolu (varsayılan: resources/data/activity_catalog_raporlama_sikligi.csv)}
                            {--public : public/activity_catalog_raporlama_sikligi.csv dosyasını kullan}
                            {--export : Önce snapshot + raporlama modelinden CSV üret}
                            {--regenerate-activity-sets : activity_sets.json dosyasını snapshot’tan yenile}
                            {--sync-reports : Mevcut rapor satırlarındaki raporlama sıklığını güncelle}';

    protected $description = 'activity_catalogs’ta boş raporlama_sikligi alanlarını CSV’den doldurur; dolu kayıtlara dokunmaz.';

    public function handle(ActivityCatalogRaporlamaSikligiService $service): int
    {
        if ($this->option('export')) {
            try {
                $written = $service->exportCsvFromSnapshotAndModel();
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info("CSV üretildi: {$written['path']} ({$written['row_count']} satır)");
        }

        $path = $this->resolveCsvPath($service);
        if ($path === null) {
            return self::FAILURE;
        }

        try {
            $stats = $service->fillMissingFromCsvFile($path);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Kaynak: {$path}");
        $this->info("CSV satırı: {$stats['parsed']}");
        $this->info("DB güncellenen: {$stats['updated_db']}");
        $this->info("Snapshot güncellenen: {$stats['updated_snapshot']}");
        $this->line("Zaten dolu (atlandı): {$stats['skipped_existing']}");
        $this->warn("Yerelde kod yok (atlandı): {$stats['skipped_unknown']}");

        if ($this->option('regenerate-activity-sets')) {
            app(ActivityCatalogSyncService::class)->regenerateActivitySetsJsonFromServerSnapshot();
            $this->info('activity_sets.json sunucu snapshot dosyasından yenilendi.');
        }

        if ($this->option('sync-reports')) {
            $exitCode = $this->call('aylik-faaliyet:sync-catalog-metadata');
            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        return self::SUCCESS;
    }

    private function resolveCsvPath(ActivityCatalogRaporlamaSikligiService $service): ?string
    {
        if ($this->option('public')) {
            $path = $service->resolvePublicCsvPath();
            if (! is_readable($path)) {
                $this->error("public CSV bulunamadı: {$path}");

                return null;
            }

            return $path;
        }

        $argument = $this->argument('path');
        $path = is_string($argument) && $argument !== ''
            ? $argument
            : $service->resolveDefaultCsvPath();

        if (! is_readable($path)) {
            $this->error("CSV bulunamadı: {$path}");
            $this->line('Önce üretin: php artisan activity-catalog:fill-raporlama-from-csv --export');

            return null;
        }

        return $path;
    }
}
