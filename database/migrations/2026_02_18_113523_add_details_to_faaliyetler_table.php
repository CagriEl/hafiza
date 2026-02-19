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
        $table->date('son_tarih')->nullable();
        $table->text('gecikme_gerekcesi')->nullable();
        $table->string('durum')->default('planlandi'); // planlandi, devam_ediyor, tamamlandi
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('faaliyetler', function (Blueprint $table) {
            //
        });
    }
};
