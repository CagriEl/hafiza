<?php

namespace Database\Seeders;

use App\Models\Directorate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DirectorateUserSeeder extends Seeder
{
    public function run(): void
    {
        $directorates = Directorate::query()->orderBy('name')->get();
        $created = 0;
        $updated = 0;

        foreach ($directorates as $directorate) {
            $slug = trim((string) $directorate->slug);
            if ($slug === '') {
                $slug = Str::slug((string) $directorate->name);
            }

            if ($slug === '') {
                continue;
            }

            $email = $slug.'@kirklareli.bel.tr';

            $existing = User::query()
                ->where('directorate_id', $directorate->id)
                ->orWhere('email', $email)
                ->first();

            $user = User::query()->updateOrCreate(
                ['directorate_id' => $directorate->id],
                [
                    'name' => $directorate->name,
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

            if (method_exists($user, 'assignRole')) {
                try {
                    $user->assignRole(User::ROLE_MUDURLUK);
                } catch (\Throwable) {
                    // Spatie rol altyapısı kurulu değilse role sütunu yeterlidir.
                }
            }
        }

        $this->command?->info("DirectorateUserSeeder tamamlandı: {$created} eklendi, {$updated} güncellendi.");
    }
}
