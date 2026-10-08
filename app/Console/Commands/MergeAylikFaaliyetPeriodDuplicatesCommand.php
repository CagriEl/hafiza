<?php

namespace App\Console\Commands;

use App\Support\AylikFaaliyetPeriodMerge;
use Illuminate\Console\Command;

class MergeAylikFaaliyetPeriodDuplicatesCommand extends Command
{
    protected $signature = 'aylik-faaliyet:merge-period-duplicates
                            {--dry-run : Yalnızca kaç grup birleşeceğini göster}';

    protected $description = 'Aynı müdürlük + yıl + ay için yinelenen raporları tek kayıtta birleştirir; hafta/tarih satır ayrımını korur.';

    public function handle(): int
    {
        $normalized = AylikFaaliyetPeriodMerge::normalizeAllAyValues();
        $this->info("Normalize edilen ay alanı: {$normalized}");

        $groups = AylikFaaliyetPeriodMerge::duplicateGroups();
        $this->info('Yinelenen dönem grubu: '.$groups->count());

        if ($this->option('dry-run')) {
            foreach ($groups as $group) {
                $first = $group->first();
                $ids = $group->pluck('id')->implode(', ');
                $this->line(sprintf(
                    '- user_id=%s %s-%s (%d kayıt: %s)',
                    $first?->user_id,
                    $first?->yil,
                    AylikFaaliyetPeriodMerge::normalizeAy((string) ($first?->ay ?? '')),
                    $group->count(),
                    $ids
                ));
            }

            return self::SUCCESS;
        }

        $merged = AylikFaaliyetPeriodMerge::mergeAllDuplicates();
        $this->info("Birleştirilen grup: {$merged}");

        return self::SUCCESS;
    }
}
