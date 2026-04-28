<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'directorate_id')) {
                $table->unsignedBigInteger('directorate_id')->nullable()->after('vice_mayor_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'directorate_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('directorate_id');
        });
    }
};
