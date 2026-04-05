<?php

namespace Database\Seeders;

use App\Services\ActivityCatalogSyncService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class ActivityCatalogSeeder extends Seeder
{
    /**
     * faaliyet_seti_full.json üzerinden upsert (silme yok).
     * Dosya kök dizinde veya resources/data/ altında aranır.
     */
    public function run(): void
    {
        $sync = app(ActivityCatalogSyncService::class);
        $path = $sync->resolveFullJsonPath();

        if (! File::isReadable($path)) {
            $this->command?->warn("faaliyet_seti_full.json bulunamadı ({$path}); ActivityCatalog tohumlaması atlandı.");

            return;
        }

        $stats = $sync->syncFromFile($path);
        $sync->regenerateActivitySetsJson($path);

        $this->command?->info(
            "ActivityCatalog upsert: {$stats['created']} eklendi, {$stats['updated']} güncellendi, {$stats['skipped']} atlandı. activity_sets.json yenilendi."
        );
    }
}
