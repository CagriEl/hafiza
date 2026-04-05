<?php

namespace App\Console\Commands;

use App\Services\ActivityCatalogSyncService;
use Illuminate\Console\Command;

class SyncActivityCatalogFromJsonCommand extends Command
{
    protected $signature = 'activity-catalog:sync
                            {--path= : faaliyet_seti_full.json dosyasının tam yolu}
                            {--no-activity-sets : resources/data/activity_sets.json dosyasını güncelleme}';

    protected $description = 'Faaliyet kataloğunu JSON’dan faaliyet_kodu ile upsert eder; katalog veya rapor silinmez.';

    public function handle(ActivityCatalogSyncService $sync): int
    {
        $path = $this->option('path') ?: null;

        try {
            $path ??= $sync->resolveFullJsonPath();
            if (! is_readable($path)) {
                $this->error("Dosya bulunamadı veya okunamıyor: {$path}");

                return self::FAILURE;
            }

            $stats = $sync->syncFromFile($path);
            $this->info("Upsert tamamlandı: {$stats['created']} yeni, {$stats['updated']} güncellendi, {$stats['skipped']} atlandı.");

            if (! $this->option('no-activity-sets')) {
                $sync->regenerateActivitySetsJson($path);
                $this->info('resources/data/activity_sets.json ActivityService ile uyumlu şekilde yenilendi.');
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
