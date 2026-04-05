<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('aylik_faaliyets', function (Blueprint $table) {
        $table->text('vice_mayor_notu')->nullable(); // Başkan yardımcısının özeti
        $table->timestamp('vice_mayor_onay_tarihi')->nullable(); // Ne zaman değerlendirdi?
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('aylik_faaliyets', function (Blueprint $table) {
            //
        });
    }
};
