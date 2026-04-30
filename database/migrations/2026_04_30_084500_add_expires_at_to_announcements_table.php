<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            if (! Schema::hasColumn('announcements', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('published_at');
                $table->index('expires_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            if (Schema::hasColumn('announcements', 'expires_at')) {
                $table->dropIndex(['expires_at']);
                $table->dropColumn('expires_at');
            }
        });
    }
};
