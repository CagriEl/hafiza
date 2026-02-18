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
    Schema::create('personel_durums', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // Hangi Müdürlük
        
        // İstenilen Kategoriler
        $table->integer('memur')->default(0);
        $table->integer('sozlesmeli_memur')->default(0);
        $table->integer('kadrolu_isci')->default(0);
        $table->integer('sirket_personeli')->default(0);
        $table->integer('gecici_isci')->default(0);
        
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personel_durums');
    }
};
