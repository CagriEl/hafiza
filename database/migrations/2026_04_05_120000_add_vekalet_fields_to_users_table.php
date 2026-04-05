<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nullable alanlar: mevcut kullanıcılar etkilenmez.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('vekalet_baslangic')->nullable();
            $table->date('vekalet_bitis')->nullable();
            $table->boolean('vekalet_tam_yetki')->default(false);
            $table->foreignId('vekalet_mudurluk_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vekalet_mudurluk_user_id');
            $table->dropColumn(['vekalet_baslangic', 'vekalet_bitis', 'vekalet_tam_yetki']);
        });
    }
};
