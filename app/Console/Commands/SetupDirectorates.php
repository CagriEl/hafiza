<?php

namespace App\Console\Commands;

use App\Models\Directorate;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SetupDirectorates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hafiza:mudurluk-kurulum';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Müdürlük kullanıcılarını ve eksik veritabanı sütunlarını otomatik kurar.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Müdürlük kurulum süreci başlatıldı.');

        if (! Schema::hasTable('users')) {
            $this->error('users tablosu bulunamadı.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('directorates')) {
            $this->error('directorates tablosu bulunamadı.');

            return self::FAILURE;
        }

        if (! Schema::hasColumn('users', 'directorate_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unsignedBigInteger('directorate_id')->nullable()->after('vice_mayor_id');
            });
            $this->info('Veritabanı güncellendi: directorate_id sütunu eklendi.');
        } else {
            $this->line('directorate_id sütunu zaten mevcut.');
        }

        $directories = Directorate::query()->orderBy('name')->get();
        if ($directories->isEmpty()) {
            $this->warn('Müdürlük kaydı bulunamadı, kullanıcı üretimi yapılmadı.');

            return self::SUCCESS;
        }

        $hasHasRolesTrait = in_array(
            'Spatie\\Permission\\Traits\\HasRoles',
            class_uses_recursive(User::class),
            true
        );

        if (! $hasHasRolesTrait) {
            $this->error('Lütfen User modeline HasRoles ekleyin!');
        }

        $created = 0;
        $updated = 0;

        foreach ($directories as $dir) {
            $slug = trim((string) $dir->slug);
            if ($slug === '') {
                $slug = Str::slug((string) $dir->name);
            }

            if ($slug === '') {
                $this->warn($dir->name.' için slug üretilemedi, kayıt atlandı.');
                continue;
            }

            $email = $slug.'@kirklareli.bel.tr';

            $existing = User::query()
                ->where('directorate_id', $dir->id)
                ->orWhere('email', $email)
                ->first();

            $user = User::query()->updateOrCreate(
                ['directorate_id' => $dir->id],
                [
                    'name' => $dir->name,
                    'email' => $email,
                    'password' => Hash::make('Hafiza2026!'),
                    'role' => User::ROLE_MUDURLUK,
                ]
            );

            if ($existing) {
                $updated++;
            } else {
                $created++;
            }

            try {
                if (method_exists($user, 'assignRole')) {
                    $user->assignRole('Müdürlük');
                }
            } catch (\Throwable $e) {
                $this->error($dir->name.' rol atama hatası: '.$e->getMessage());
            }

            $this->line('✓ '.$dir->name.' hesabı aktif edildi.');
        }

        $this->info("Kurulum tamamlandı. Eklenen: {$created}, Güncellenen: {$updated}");

        return self::SUCCESS;
    }
}
