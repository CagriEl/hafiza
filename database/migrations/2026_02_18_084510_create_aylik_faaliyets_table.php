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
        Schema::create('aylik_faaliyets', function (Blueprint $table) {
            $table->id();
            
            // Hangi Müdürlük (Kullanıcı) ekledi?
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            
            // Dönem Bilgileri
            $table->integer('yil'); // 2026
            $table->string('ay');   // 01, 02...
            
            // Personel Sayıları (Snapshot - O anki durum)
            $table->integer('memur')->default(0);
            $table->integer('sozlesmeli_memur')->default(0);
            $table->integer('kadrolu_isci')->default(0);
            $table->integer('sirket_personeli')->default(0);
            $table->integer('gecici_isci')->default(0);
            
            // Planlanan İşler ve Sonuçları (JSON formatında tutulacak)
            $table->json('faaliyetler')->nullable(); 

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aylik_faaliyets');
    }
};