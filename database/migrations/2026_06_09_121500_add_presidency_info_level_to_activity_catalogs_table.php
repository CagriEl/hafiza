<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_catalogs', function (Blueprint $table): void {
            if (! Schema::hasColumn('activity_catalogs', 'baskanlik_bilgilendirme_seviyesi')) {
                $table->string('baskanlik_bilgilendirme_seviyesi')->nullable()->after('raporlama_sikligi');
            }
        });
    }

    public function down(): void
    {
        Schema::table('activity_catalogs', function (Blueprint $table): void {
            if (Schema::hasColumn('activity_catalogs', 'baskanlik_bilgilendirme_seviyesi')) {
                $table->dropColumn('baskanlik_bilgilendirme_seviyesi');
            }
        });
    }
};
