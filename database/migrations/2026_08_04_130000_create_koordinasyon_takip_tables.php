<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('koordinasyon_haftalar', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('yil');
            $table->unsignedTinyInteger('ay');
            $table->unsignedTinyInteger('hafta');
            $table->json('checklist')->nullable();
            $table->text('ozet_not')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['yil', 'ay', 'hafta']);
        });

        Schema::create('koordinasyon_takip_maddeleri', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('yil');
            $table->unsignedTinyInteger('ay');
            $table->unsignedTinyInteger('hafta');
            $table->foreignId('analiz_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('directorate_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('baslik');
            $table->string('durum', 20)->default('suphe'); // teyit | suphe | duzeltme
            $table->boolean('saha_kontrolu')->default(false);
            $table->text('notlar')->nullable();
            $table->timestamp('kapanis_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['yil', 'ay', 'hafta']);
            $table->index('durum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('koordinasyon_takip_maddeleri');
        Schema::dropIfExists('koordinasyon_haftalar');
    }
};
