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
    Schema::table('swot_analizs', function (Blueprint $table) {
        
        // Eğer tabloda 'yil' sütunu yoksa, önce onu ekleyelim
        if (!Schema::hasColumn('swot_analizs', 'yil')) {
            $table->integer('yil')->default(date('Y'))->after('user_id');
        }

        // Şimdi 'baslik' sütununu ekleyelim (user_id'den sonra veya yil'dan sonra)
        // Hata almamak için doğrudan user_id sonrasına ekliyoruz.
        if (!Schema::hasColumn('swot_analizs', 'baslik')) {
            $table->string('baslik')->nullable()->after('user_id');
        }
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('swot_analizs', function (Blueprint $table) {
            //
        });
    }
};
