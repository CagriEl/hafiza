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
    Schema::dropIfExists('haftalik_raporlar'); // Tablo adınız farklıysa güncelleyin
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
