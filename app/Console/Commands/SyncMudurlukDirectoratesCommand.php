<?php

namespace App\Console\Commands;

use App\Models\Directorate;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Müdürlük kullanıcılarının directorate_id alanını, aynı isimli directorates kaydıyla eşler.
 * Yanlış MHM bağından dolayı İklim gibi hesapların Raporlar yerine Mali menü görmesini düzeltir.
 */
class SyncMudurlukDirectoratesCommand extends Command
{
    protected $signature = 'hafiza:sync-mudurluk-directorates
                            {--dry-run : Değişiklikleri kaydetmeden özet göster}
                            {--apply : Değişiklikleri veritabanına yaz}';

    protected $description = 'Müdürlük kullanıcılarının directorate_id değerini isim eşleşmesiyle düzeltir.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if (! $dryRun && ! $apply) {
            $this->warn('Varsayılan: kuru çalıştırma. Kaydetmek için --apply kullanın.');
            $dryRun = true;
        }

        if ($dryRun && $apply) {
            $this->error('--dry-run ve --apply birlikte kullanılamaz.');

            return self::FAILURE;
        }

        $byName = Directorate::query()
            ->get()
            ->keyBy(fn (Directorate $d): string => mb_strtolower(trim((string) $d->name)));

        $fixed = 0;
        $ok = 0;
        $missing = 0;
        $rows = [];

        $users = User::query()
            ->where('role', User::ROLE_MUDURLUK)
            ->orderBy('id')
            ->get(['id', 'name', 'directorate_id']);

        foreach ($users as $user) {
            $key = mb_strtolower(trim((string) $user->name));
            $want = $byName->get($key);

            if (! $want instanceof Directorate) {
                $missing++;
                $rows[] = [
                    $user->id,
                    $user->name,
                    (string) ($user->directorate_id ?? '—'),
                    'eşleşme yok',
                    'atlanacak',
                ];

                continue;
            }

            if ((int) $user->directorate_id === (int) $want->id) {
                $ok++;

                continue;
            }

            $rows[] = [
                $user->id,
                $user->name,
                (string) ($user->directorate_id ?? '—'),
                "{$want->id}/{$want->code}",
                $dryRun ? 'dry-run' : 'güncellendi',
            ];

            if (! $dryRun) {
                $user->directorate_id = $want->id;
                $user->save();
            }

            $fixed++;
        }

        if ($rows !== []) {
            $this->table(
                ['user_id', 'ad', 'eski_dir', 'yeni_dir', 'durum'],
                $rows
            );
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}Düzeltilecek/düzeltildi: {$fixed}, zaten doğru: {$ok}, isim eşleşmesi yok: {$missing}");

        if ($dryRun && $fixed > 0) {
            $this->comment('Kayıt için: php artisan hafiza:sync-mudurluk-directorates --apply');
        }

        return self::SUCCESS;
    }
}
