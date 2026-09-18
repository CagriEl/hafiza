<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aynı gün için birden fazla faaliyet raporu girilebilsin.
 * Unique düşmeden önce user_id indeksi eklenir (FK gereksinimi).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('aylik_faaliyets')) {
            return;
        }

        $hasUserIndex = collect(DB::select('SHOW INDEX FROM aylik_faaliyets'))
            ->contains(fn ($row): bool => ($row->Key_name ?? '') === 'aylik_faaliyets_user_id_index');

        if (! $hasUserIndex) {
            Schema::table('aylik_faaliyets', function (Blueprint $table) {
                $table->index('user_id', 'aylik_faaliyets_user_id_index');
            });
        }

        try {
            Schema::table('aylik_faaliyets', function (Blueprint $table) {
                $table->dropUnique('aylik_faaliyets_user_week_unique');
            });
        } catch (\Throwable $e) {
            try {
                DB::statement('ALTER TABLE aylik_faaliyets DROP INDEX aylik_faaliyets_user_week_unique');
            } catch (\Throwable $inner) {
                logger()->warning('aylik_faaliyets_user_week_unique drop failed', [
                    'error' => $inner->getMessage(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('aylik_faaliyets')) {
            return;
        }

        try {
            Schema::table('aylik_faaliyets', function (Blueprint $table) {
                $table->unique(['user_id', 'yil', 'ay', 'hafta'], 'aylik_faaliyets_user_week_unique');
            });
        } catch (\Throwable) {
        }
    }
};
