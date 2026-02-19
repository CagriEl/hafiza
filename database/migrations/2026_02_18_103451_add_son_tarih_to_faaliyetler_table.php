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
    Schema::table('faaliyetler', function (Blueprint $table) {
        // Tarih alanı ekliyoruz, boş bırakılabilir (nullable) olması esneklik sağlar
        $table->date('son_tarih')->after('faaliyet_adi')->nullable();
    });
}

public function down(): void
{
    Schema::table('faaliyetler', function (Blueprint $table) {
        $table->dropColumn('son_tarih');
    });
}

};
