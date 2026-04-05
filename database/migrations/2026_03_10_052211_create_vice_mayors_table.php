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
    Schema::create('vice_mayors', function (Blueprint $table) {
        $table->id();
        $table->string('ad_soyad');
        $table->string('unvan')->default('Belediye Başkan Yardımcısı');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vice_mayors');
    }
};
