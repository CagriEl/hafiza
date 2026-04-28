<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AnnouncementPermissionSeeder extends Seeder
{
    public function run(): void
    {
        if (! class_exists(\Spatie\Permission\Models\Permission::class) || ! class_exists(\Spatie\Permission\Models\Role::class)) {
            return;
        }

        $guard = config('auth.defaults.guard', 'web');

        /** @var \Spatie\Permission\Models\Permission $permission */
        $permission = \Spatie\Permission\Models\Permission::findOrCreate('announcement.view-any', $guard);

        /** @var \Spatie\Permission\Models\Role $adminRole */
        $adminRole = \Spatie\Permission\Models\Role::findOrCreate('Admin', $guard);
        $adminRole->givePermissionTo($permission);

        $adminUsers = User::query()
            ->whereRaw('LOWER(COALESCE(role, "")) = ?', ['admin'])
            ->get();

        foreach ($adminUsers as $adminUser) {
            if (method_exists($adminUser, 'assignRole')) {
                $adminUser->assignRole($adminRole);
            }
        }
    }
}
