<?php

namespace App\Console\Commands;

use App\Services\ActivityCatalogSqlImportService;
use App\Services\ActivityCatalogSyncService;
use Illuminate\Console\Command;

class ImportActivityCatalogFromPublicSqlCommand extends Command
{
    protected $signature = 'activity-catalog:import-from-public-sql
                            {--skip-snapshot : Snapshot JSON yazmadan yalnızca SQL ile DB günceller}
                            {--skip-faaliyet-seti-json : faaliyet_seti_full.json dosyasını güncelleme}
                            {--skip-activity-sets : activity_sets.json dosyasını yenileme}
                            {--skip-raporlama-csv : Boş raporlama_sikligi alanlarını CSV ile tamamlama}
                            {--sync-reports : Sonrasında mevcut rapor meta alanlarını güncelle}';

    protected $description = 'public_html activity_catalogs.sql → snapshot, DB, faaliyet_seti_full.json ve activity_sets.json (yeni kod eklemez).';

    public function handle(ActivityCatalogSqlImportService $import, ActivityCatalogSyncService $sync): int
    {
        $sqlPath = $import->resolvePublicSqlPath();

        if (! is_readable($sqlPath)) {
            $this->error("SQL dosyası bulunamadı: {$sqlPath}");
            $this->line('Dosyayı web köküne activity_catalogs.sql adıyla yükleyin (public_html / public).');

            return self::FAILURE;
        }

        try {
            if ($this->option('skip-snapshot')) {
                $stats = $import->updateExistingFromSqlFile($sqlPath);
                $this->info("Kaynak (SQL): {$sqlPath}");
                $this->info("İşlenen satır: {$stats['parsed']}");
                $this->info("Güncellenen: {$stats['updated']}");
                $this->info("Değişmeyen: {$stats['unchanged']}");
                $this->warn("Atlanan (yerelde kod yok): {$stats['skipped_new']}");
            } else {
                $result = $sync->syncAllFromPublicSql($sqlPath, [
                    'fill_raporlama' => ! $this->option('skip-raporlama-csv'),
                    'update_full_json' => ! $this->option('skip-faaliyet-seti-json'),
                    'update_activity_sets' => ! $this->option('skip-activity-sets'),
                ]);

                $this->info("SQL: {$result['sql_path']}");
                $this->info("Snapshot: {$result['snapshot_path']} ({$result['snapshot_rows']} satır)");

                $db = $result['db'];
                $this->info("DB güncellenen: {$db['updated']}, değişmeyen: {$db['unchanged']}, atlanan: {$db['skipped_new']}");

                if (is_array($result['raporlama'])) {
                    $r = $result['raporlama'];
                    $this->info("Raporlama sıklığı: DB {$r['updated_db']}, snapshot {$r['updated_snapshot']} güncellendi.");
                }

                if (is_array($result['faaliyet_seti_full'])) {
                    $full = $result['faaliyet_seti_full'];
                    $this->info("faaliyet_seti_full.json: {$full['updated']} satır güncellendi ({$full['path']})");
                }

                if (! $this->option('skip-activity-sets')) {
                    $this->info('activity_sets.json snapshot ile yenilendi.');
                }
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
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
