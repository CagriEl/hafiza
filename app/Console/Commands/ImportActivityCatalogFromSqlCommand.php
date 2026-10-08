<?php

namespace App\Console\Commands;

use App\Services\ActivityCatalogSqlImportService;
use App\Services\ActivityCatalogSyncService;
use Illuminate\Console\Command;

class ImportActivityCatalogFromSqlCommand extends Command
{
    protected $signature = 'activity-catalog:import-from-sql
                            {path : phpMyAdmin activity_catalogs.sql dosya yolu}
                            {--write-snapshot : SQL verisini resources/data/activity_catalog_server_snapshot.json dosyasına yazar}
                            {--regenerate-activity-sets : resources/data/activity_sets.json dosyasını yenile}
                            {--sync-reports : Sonrasında mevcut rapor meta alanlarını güncelle}';

    protected $description = 'Sunucu SQL dökümünden yalnızca mevcut faaliyet kodlarını günceller; yeni kod eklemez.';

    public function handle(ActivityCatalogSqlImportService $import): int
    {
        $path = (string) $this->argument('path');

        try {
            if ($this->option('write-snapshot')) {
                $written = $import->writeSnapshotFromSqlFile($path);
                $this->info("Snapshot yazıldı: {$written['path']} ({$written['row_count']} satır)");
                $stats = $import->updateExistingFromSnapshotFile($written['path']);
            } else {
                $stats = $import->updateExistingFromSqlFile($path);
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("SQL satırı: {$stats['parsed']}");
        $this->info("Güncellenen: {$stats['updated']}");
        $this->info("Değişmeyen: {$stats['unchanged']}");
        $this->warn("Atlanan (yerelde kod yok, eklenmedi): {$stats['skipped_new']}");

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
}
