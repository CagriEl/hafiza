<?php

namespace App\Console\Commands;

use App\Services\ActivityCatalogSqlImportService;
use App\Services\ActivityCatalogSyncService;
use Illuminate\Console\Command;

class ApplyActivityCatalogServerSnapshotCommand extends Command
{
    protected $signature = 'activity-catalog:apply-server-snapshot
                            {path? : Opsiyonel snapshot JSON yolu (varsayılan: resources/data/activity_catalog_server_snapshot.json)}
                            {--regenerate-activity-sets : resources/data/activity_sets.json dosyasını yenile}
                            {--sync-reports : Sonrasında mevcut rapor meta alanlarını güncelle}';

    protected $description = 'Repodaki sunucu katalog snapshot dosyasından mevcut faaliyet kodlarını günceller; yeni kod eklemez.';

    public function handle(ActivityCatalogSqlImportService $import): int
    {
        $path = $this->argument('path');
        $resolvedPath = is_string($path) && $path !== ''
            ? $path
            : $import->resolveDefaultSnapshotPath();

        try {
            $stats = $import->updateExistingFromSnapshotFile($resolvedPath);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Kaynak: {$resolvedPath}");
        $this->info("Snapshot satırı: {$stats['parsed']}");
        $this->info("Güncellenen: {$stats['updated']}");
        $this->info("Değişmeyen: {$stats['unchanged']}");
        $this->warn("Atlanan (yerelde kod yok, eklenmedi): {$stats['skipped_new']}");

        if ($this->option('regenerate-activity-sets')) {
            app(ActivityCatalogSyncService::class)->regenerateActivitySetsJsonFromServerSnapshot($resolvedPath);
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
