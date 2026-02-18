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
    Schema::create('swot_analizs', function (Blueprint $table) {
        $table->id();
        // Müdürlüğü temsil eden User ID (Otomatik dolacak)
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();

        // İşlem Adı
        $table->string('islem_adi'); 

        // SWOT Alanları
        $table->text('guclu_yonler')->nullable(); // Strengths
        $table->text('zayif_yonler')->nullable(); // Weaknesses
        $table->text('firsatlar')->nullable();    // Opportunities
        $table->text('tehditler')->nullable();    // Threats

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('swot_analizs');
    }
};
