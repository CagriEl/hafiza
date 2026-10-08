<?php

namespace App\Console\Commands;

use App\Models\AylikFaaliyet;
use App\Models\User;
use App\Support\AylikFaaliyetWeeklyCarryover;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CloseEmptyTamamlananCommand extends Command
{
    protected $signature = 'aylik-faaliyet:close-empty-tamamlanan
                            {--dry-run : Değişiklikleri kaydetmeden yalnızca özet göster}
                            {--apply : Değişiklikleri veritabanına yaz}
                            {--yil= : Yalnızca belirtilen yıl}
                            {--ay= : Yalnızca belirtilen ay (01-12)}
                            {--user= : Yalnızca belirtilen kullanıcı / müdürlük id}';

    protected $description = 'Tamamlanan boş veya 0 olan kalemlerde gerceklesen = ongorulen yapar (kısmi ilerlemeye dokunmaz; veri silmez).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if (! $dryRun && ! $apply) {
            $this->warn('Varsayılan: kuru çalıştırma. Kaydetmek için --apply, özet için --dry-run kullanın.');
            $dryRun = true;
        }

        if ($dryRun && $apply) {
            $this->error('--dry-run ve --apply birlikte kullanılamaz.');

            return self::FAILURE;
        }

        $filterYil = $this->option('yil') !== null ? (int) $this->option('yil') : null;
        $filterAy = $this->option('ay') !== null
            ? str_pad((string) preg_replace('/\D/', '', (string) $this->option('ay')), 2, '0', STR_PAD_LEFT)
            : null;
        $filterUser = $this->option('user') !== null ? (int) $this->option('user') : null;

        $query = AylikFaaliyet::query()->orderBy('id');
        if ($filterYil !== null && $filterYil > 0) {
            $query->where('yil', $filterYil);
        }
        if ($filterAy !== null && $filterAy !== '') {
            $query->where('ay', $filterAy);
        }
        if ($filterUser !== null && $filterUser > 0) {
            $query->where('user_id', $filterUser);
        }

        $updatedReports = 0;
        $updatedKalems = 0;
        $byMudurluk = [];
        $logRows = [];

        $names = User::query()->pluck('name', 'id');

        $process = function () use (
            $query,
            $dryRun,
            &$updatedReports,
            &$updatedKalems,
            &$byMudurluk,
            &$logRows,
            $names
        ): void {
            $query->chunkById(50, function ($records) use (
                $dryRun,
                &$updatedReports,
                &$updatedKalems,
                &$byMudurluk,
                &$logRows,
                $names
            ): void {
                foreach ($records as $record) {
                    $data = [
                        'yil' => $record->yil,
                        'ay' => $record->ay,
                        'hafta' => $record->hafta,
                        'faaliyetler' => is_array($record->faaliyetler) ? $record->faaliyetler : [],
                    ];

                    [$next, $changes] = AylikFaaliyetWeeklyCarryover::backfillEmptyGerceklesen($data);
                    if ($changes === []) {
                        continue;
                    }

                    $updatedReports++;
                    $updatedKalems += count($changes);
                    $uid = (int) $record->user_id;
                    $byMudurluk[$uid] = ($byMudurluk[$uid] ?? 0) + count($changes);
                    $mudName = (string) ($names[$uid] ?? "user#{$uid}");

                    foreach ($changes as $change) {
                        $logRows[] = [
                            $record->id,
                            $mudName,
                            "{$record->yil}/{$record->ay}/H{$record->hafta}",
                            $change['kod'],
                            $change['kalem'],
                            $change['onceki'] === null || $change['onceki'] === '' ? 'boş' : (string) $change['onceki'],
                            (string) $change['yeni'],
                        ];
                    }

                    if ($dryRun) {
                        continue;
                    }

                    $record->faaliyetler = $next['faaliyetler'];
                    $record->save();
                }
            });
        };

        if ($dryRun) {
            $process();
        } else {
            DB::transaction(function () use ($process): void {
                $process();
            });
        }

        if ($logRows !== []) {
            $this->table(
                ['rapor_id', 'müdürlük', 'dönem', 'kod', 'kalem', 'eski_tamamlanan', 'yeni'],
                array_slice($logRows, 0, 80)
            );
            if (count($logRows) > 80) {
                $this->line('... ve '.(count($logRows) - 80).' satır daha');
            }
        }

        $this->newLine();
        foreach ($byMudurluk as $uid => $count) {
            $this->line(($names[$uid] ?? "user#{$uid}").": {$count} kalem");
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}Rapor: {$updatedReports}, kalem: {$updatedKalems}");
        if ($dryRun) {
            $this->comment('Kayıt için: php artisan aylik-faaliyet:close-empty-tamamlanan --apply');
        }

        return self::SUCCESS;
    }
}
