<?php

use App\Support\AylikFaaliyetPeriodMerge;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('aylik_faaliyets')) {
            return;
        }

        AylikFaaliyetPeriodMerge::normalizeAllAyValues();
        AylikFaaliyetPeriodMerge::mergeAllDuplicates();

        Schema::table('aylik_faaliyets', function (Blueprint $table) {
            $table->unique(['user_id', 'yil', 'ay'], 'aylik_faaliyets_user_period_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('aylik_faaliyets')) {
            return;
        }

        Schema::table('aylik_faaliyets', function (Blueprint $table) {
            $table->dropUnique('aylik_faaliyets_user_period_unique');
        });
    }
};
