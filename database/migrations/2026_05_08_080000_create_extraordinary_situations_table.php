<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extraordinary_situations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('yil');
            $table->string('ay', 2);
            $table->text('message');
            $table->timestamps();

            $table->index(['target_user_id', 'yil', 'ay'], 'extraordinary_situations_target_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extraordinary_situations');
    }
};
