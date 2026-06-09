<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_catalogs', function (Blueprint $table): void {
            if (! Schema::hasColumn('activity_catalogs', 'raporlama_sikligi')) {
                $table->string('raporlama_sikligi')->nullable()->after('kpi_sla');
            }
        });
    }

    public function down(): void
    {
        Schema::table('activity_catalogs', function (Blueprint $table): void {
            if (Schema::hasColumn('activity_catalogs', 'raporlama_sikligi')) {
                $table->dropColumn('raporlama_sikligi');
            }
        });
    }
};
