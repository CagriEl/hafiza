<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mali_hizmetler_raporlari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('yil');
            $table->string('ay', 2);
            $table->unsignedTinyInteger('hafta');
            $table->decimal('kasa_tutari', 15, 2)->default(0);
            $table->decimal('haftalik_odeme_toplam', 15, 2)->default(0);
            $table->json('odeme_talepleri')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'yil', 'ay', 'hafta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mali_hizmetler_raporlari');
    }
};
