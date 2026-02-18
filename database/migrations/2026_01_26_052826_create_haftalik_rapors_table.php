<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('haftalik_rapors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            
            // Tarih Aralığı (Pazartesi - Perşembe)
            $table->date('baslangic_tarihi');
            $table->date('bitis_tarihi');

            // 1. Personel Sayıları
            $table->integer('memur_sayisi')->default(0);
            $table->integer('sozlesmeli_memur_sayisi')->default(0);
            $table->integer('kadrolu_isci_sayisi')->default(0);
            $table->integer('sirket_personeli_sayisi')->default(0);

            // 2. Şikayet Sayıları
            $table->integer('cimer_sayisi')->default(0);
            $table->integer('acikkapi_sayisi')->default(0);
            $table->integer('belediye_sayisi')->default(0);
            $table->integer('toplam_sikayet')->default(0);

            // 3. Listeler (JSON Formatında)
            $table->json('basit_planlanan_faaliyetler')->nullable(); // Madde 2 (Hızlı Plan)
            $table->json('faaliyetler')->nullable(); // Madde 3 (Yapılan İşler)
            $table->json('detayli_planlanan_faaliyetler')->nullable(); // Madde 4 (Gelecek Plan)
            $table->json('ihaleler')->nullable(); // Madde 5 (İhaleler)

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('haftalik_rapors');
    }
};