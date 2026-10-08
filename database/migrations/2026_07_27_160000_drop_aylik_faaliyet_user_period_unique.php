<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('aylik_faaliyets')) {
            return;
        }

        // Haftalık unique varken ay bazlı unique kalırsa çoklu hafta raporları engellenir / silinmiş gibi görünür.
        try {
            Schema::table('aylik_faaliyets', function ($table) {
                $table->dropUnique('aylik_faaliyets_user_period_unique');
            });
        } catch (\Throwable) {
            try {
                DB::statement('ALTER TABLE aylik_faaliyets DROP INDEX aylik_faaliyets_user_period_unique');
            } catch (\Throwable) {
                // Index yoksa devam.
            }
        }
    }

    public function down(): void
    {
        // Geri alınmaz: ay bazlı unique haftalık modelle çelişir.
    }
};
