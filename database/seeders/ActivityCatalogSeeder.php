<?php

namespace Database\Seeders;

use App\Services\ActivityCatalogSqlImportService;
use App\Services\ActivityCatalogSyncService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class ActivityCatalogSeeder extends Seeder
{
    /**
     * Sunucu snapshot JSON üzerinden mevcut faaliyet kodlarını günceller (yeni kod eklemez).
     */
    public function run(): void
    {
        $import = app(ActivityCatalogSqlImportService::class);
        $sync = app(ActivityCatalogSyncService::class);
        $path = $import->resolveDefaultSnapshotPath();

        if (! File::isReadable($path)) {
            $this->command?->warn("activity_catalog_server_snapshot.json bulunamadı ({$path}); ActivityCatalog tohumlaması atlandı.");

            return;
        }

        $stats = $import->updateExistingFromSnapshotFile($path);
        $sync->regenerateActivitySetsJsonFromServerSnapshot($path);

        $this->command?->info(
            "ActivityCatalog (sunucu snapshot): {$stats['updated']} güncellendi, {$stats['unchanged']} değişmedi, {$stats['skipped_new']} yeni kod atlandı. activity_sets.json yenilendi."
        );
    }
}
