<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rapor_hafta_tanimlari', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('yil');
            $table->string('ay', 2);
            $table->unsignedTinyInteger('hafta');
            $table->date('baslangic');
            $table->date('bitis');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['yil', 'ay', 'hafta']);
            $table->index(['yil', 'ay']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_manage_rapor_haftalari')->default(false)->after('include_in_performance_charts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_manage_rapor_haftalari');
        });

        Schema::dropIfExists('rapor_hafta_tanimlari');
    }
};
