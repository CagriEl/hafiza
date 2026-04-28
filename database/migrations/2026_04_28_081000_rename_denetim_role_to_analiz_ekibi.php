<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roles')) {
            DB::table('roles')
                ->where('name', 'Denetim Ekibi')
                ->update(['name' => 'Analiz Ekibi']);
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
            DB::table('users')
                ->where('role', 'Denetim Ekibi')
                ->update(['role' => 'Analiz Ekibi']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('roles')) {
            DB::table('roles')
                ->where('name', 'Analiz Ekibi')
                ->update(['name' => 'Denetim Ekibi']);
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
            DB::table('users')
                ->where('role', 'Analiz Ekibi')
                ->update(['role' => 'Denetim Ekibi']);
        }
    }
};
