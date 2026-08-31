<?php

namespace App\Console\Commands;

use App\Filament\Resources\AylikFaaliyetResource;
use App\Models\AylikFaaliyet;
use Illuminate\Console\Command;

class SyncAylikFaaliyetCatalogMetadataCommand extends Command
{
    protected $signature = 'aylik-faaliyet:sync-catalog-metadata
                            {--dry-run : Değişiklikleri kaydetmeden önizle}';

    protected $description = 'Mevcut rapor satırlarında yalnızca başkanlık bilgilendirme seviyesini katalog/JSON ile günceller (kodlar ve kapsam korunur).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updatedReports = 0;
        $updatedRows = 0;

        AylikFaaliyet::query()
            ->with('user')
            ->orderBy('id')
            ->chunkById(50, function ($records) use ($dryRun, &$updatedReports, &$updatedRows): void {
                foreach ($records as $record) {
                    $original = is_array($record->faaliyetler) ? $record->faaliyetler : [];
                    $data = ['faaliyetler' => $original];
                    $synced = AylikFaaliyetResource::refreshFaaliyetMetadataFromCatalog(
                        $data,
                        $record->user?->name
                    );
                    $newRows = is_array($synced['faaliyetler'] ?? null) ? $synced['faaliyetler'] : [];

                    if ($newRows === $original) {
                        continue;
                    }

                    $rowChanges = $this->countMetadataRowChanges($original, $newRows);
                    if ($rowChanges === 0) {
                        continue;
                    }

                    $updatedReports++;
                    $updatedRows += $rowChanges;

                    $this->line(sprintf(
                        'Rapor #%d (%s %s/%s): %d satır güncellendi',
                        $record->id,
                        $record->user?->name ?? '—',
                        (string) $record->yil,
                        (string) $record->ay,
                        $rowChanges
                    ));

                    if (! $dryRun) {
                        $record->faaliyetler = $newRows;
                        $record->save();
                    }
                }
            });

        if ($dryRun) {
            $this->info("Önizleme: {$updatedReports} raporda toplam {$updatedRows} satır güncellenecek.");
        } else {
            $this->info("Tamamlandı: {$updatedReports} raporda toplam {$updatedRows} satır güncellendi.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     */
    private function countMetadataRowChanges(array $before, array $after): int
    {
        if ($before === $after || count($before) !== count($after)) {
            return 0;
        }

        $count = 0;

        foreach ($before as $index => $row) {
            if (! is_array($row) || ! isset($after[$index]) || ! is_array($after[$index])) {
                continue;
            }
            $next = $after[$index];
            if (trim((string) ($row['faaliyet_kodu'] ?? '')) !== trim((string) ($next['faaliyet_kodu'] ?? ''))) {
                return 0;
            }
            foreach (['baskanlik_bilgilendirme_seviyesi'] as $field) {
                if (trim((string) ($row[$field] ?? '')) !== trim((string) ($next[$field] ?? ''))) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }
}
