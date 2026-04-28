<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedbacks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('directorate_id')->nullable()->constrained('directorates')->nullOnDelete();
            $table->string('subject');
            $table->string('category', 40);
            $table->text('message');
            $table->string('status', 40)->default('Yeni');
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'category']);
            $table->index('directorate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedbacks');
    }
};
