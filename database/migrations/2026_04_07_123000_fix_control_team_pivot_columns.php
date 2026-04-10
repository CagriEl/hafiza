<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('control_team_user_directorate')) {
            Schema::create('control_team_user_directorate', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('directorate_id');
                $table->timestamps();
                $table->unique(['user_id', 'directorate_id'], 'ctud_user_directorate_unique');
            });

            return;
        }

        Schema::table('control_team_user_directorate', function (Blueprint $table): void {
            if (! Schema::hasColumn('control_team_user_directorate', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
            }
            if (! Schema::hasColumn('control_team_user_directorate', 'directorate_id')) {
                $table->unsignedBigInteger('directorate_id')->nullable()->after('user_id');
            }
        });

        if (Schema::hasColumn('control_team_user_directorate', 'control_team_user_id')) {
            DB::table('control_team_user_directorate')
                ->whereNull('user_id')
                ->update([
                    'user_id' => DB::raw('control_team_user_id'),
                ]);
        }

        if (Schema::hasColumn('control_team_user_directorate', 'directorate_user_id')) {
            DB::table('control_team_user_directorate')
                ->whereNull('directorate_id')
                ->update([
                    'directorate_id' => DB::raw('directorate_user_id'),
                ]);
        }

        try {
            Schema::table('control_team_user_directorate', function (Blueprint $table): void {
                $table->unique(['user_id', 'directorate_id'], 'ctud_user_directorate_unique');
            });
        } catch (\Throwable $e) {
            // index zaten varsa migration devam etsin (veri koruma)
        }
    }

    public function down(): void
    {
        // veri koruma amaçlı no-op
    }
};
