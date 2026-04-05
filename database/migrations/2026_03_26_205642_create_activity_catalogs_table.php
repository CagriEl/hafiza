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
    Schema::create('activity_catalogs', function (Blueprint $table) {
        $table->id();
        $table->string('mudurluk');        // Örn: Özel Kalem Müdürlüğü
        $table->string('faaliyet_kodu');  // Örn: OKM-01
        $table->string('faaliyet_ailesi'); // Örn: Protokol ve Başkanlık Takvimi
        $table->string('kategori');       // Örn: İletişim / Memnuniyet
        $table->text('kapsam');           // Faaliyetin detayı
        $table->string('olcu_birimi');    // Örn: adet / hafta
        $table->string('kpi_sla');        // Örn: zamanında gerçekleşme oranı
        $table->timestamps();
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_catalogs');
    }
};
