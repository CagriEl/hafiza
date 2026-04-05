<?php

namespace App\Console\Commands;

use App\Models\ActivityCatalog;
use App\Models\User;
use App\Services\ActivityCatalogSyncService;
use App\Services\ActivityService;
use App\Support\TurkishString;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * faaliyet_seti_full.json içindeki Fen İşleri (FNM-*) kayıtlarının DB ve form seçenekleriyle uyumunu doğrular.
 * Not: Dosyada kod öneki FNM (ör. FNM-01); FİM değil.
 */
class DebugFenIsleriActivityCatalogCommand extends Command
{
    protected $signature = 'activity-catalog:debug-fen-isleri
                            {--canonical=Fen İşleri Müdürlüğü : tr-TR ile eşlenecek müdürlük adı}';

    protected $description = 'Fen İşleri müdürlüğü faaliyet kodlarını JSON ↔ activity_catalogs ↔ kullanıcı seçenekleri (tr-TR) ile doğrular';

    public function handle(ActivityCatalogSyncService $sync, ActivityService $activityService): int
    {
        $canonical = (string) $this->option('canonical');
        $path = $sync->resolveFullJsonPath();

        if (! File::isReadable($path)) {
            $this->error("JSON okunamadı: {$path}");

            return self::FAILURE;
        }

        $rows = json_decode(File::get($path), true);
        if (! is_array($rows)) {
            $this->error('Geçersiz JSON.');

            return self::FAILURE;
        }

        $jsonCodes = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mudurlukCol = trim((string) ($row['Müdürlük'] ?? ''));
            if (! TurkishString::same($mudurlukCol, $canonical)) {
                continue;
            }
            $kod = trim((string) ($row['Faaliyet Kodu'] ?? ''));
            if ($kod !== '') {
                $jsonCodes[] = $kod;
            }
        }
        sort($jsonCodes);

        $this->info("JSON — tr-TR eşleşen '{$canonical}': ".count($jsonCodes).' kod');
        $this->line($jsonCodes === [] ? '(yok)' : implode(', ', $jsonCodes));

        $dbCodes = ActivityCatalog::query()
            ->orderBy('faaliyet_kodu')
            ->get()
            ->filter(fn (ActivityCatalog $r) => TurkishString::same($r->mudurluk, $canonical))
            ->pluck('faaliyet_kodu')
            ->all();
        sort($dbCodes);

        $this->newLine();
        $this->info('activity_catalogs — tr-TR müdürlük eşleşmesi: '.count($dbCodes).' kayıt');
        $this->line($dbCodes === [] ? '(yok)' : implode(', ', $dbCodes));

        $missingInDb = array_values(array_diff($jsonCodes, $dbCodes));
        $extraInDb = array_values(array_diff($dbCodes, $jsonCodes));

        $this->newLine();
        if ($missingInDb === [] && $extraInDb === []) {
            $this->components->info('JSON ↔ DB kod kümeleri aynı.');
        } else {
            if ($missingInDb !== []) {
                $this->warn('DB’de eksik: '.implode(', ', $missingInDb).' → php artisan activity-catalog:sync');
            }
            if ($extraInDb !== []) {
                $this->warn('DB’de JSON’da olmayan: '.implode(', ', $extraInDb));
            }
        }

        $this->newLine();
        $this->info('users.name tr-TR eşleşmesi → getCatalogOptionsForMudurluk:');
        $matchedUsers = User::query()->orderBy('id')->get()
            ->filter(fn (User $u) => TurkishString::same($u->name, $canonical));

        if ($matchedUsers->isEmpty()) {
            $this->warn('Eşleşen kullanıcı yok. Kullanıcı adı JSON ile birebir veya tr-TR aynı olmalı (ör. Fen İşleri Müdürlüğü).');
        }

        foreach ($matchedUsers as $u) {
            $options = $activityService->getCatalogOptionsForMudurluk($u->name);
            $n = count($options);
            $ok = $n === count($jsonCodes) && $jsonCodes !== [];
            $this->line("  id={$u->id} email={$u->email} → {$n} seçenek ".($ok ? '✓' : '✗'));
            if (! $ok && $jsonCodes !== []) {
                $this->warn('    Beklenen seçenek sayısı: '.count($jsonCodes));
            }
        }

        return self::SUCCESS;
    }
}
