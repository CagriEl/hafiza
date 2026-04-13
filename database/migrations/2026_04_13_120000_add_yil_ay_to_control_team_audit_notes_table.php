<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('control_team_audit_notes')) {
            return;
        }

        Schema::table('control_team_audit_notes', function (Blueprint $table): void {
            if (! Schema::hasColumn('control_team_audit_notes', 'yil')) {
                $table->unsignedSmallInteger('yil')->nullable()->after('directorate_user_id');
            }
            if (! Schema::hasColumn('control_team_audit_notes', 'ay')) {
                $table->string('ay', 2)->nullable()->after('yil');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('control_team_audit_notes')) {
            return;
        }

        Schema::table('control_team_audit_notes', function (Blueprint $table): void {
            if (Schema::hasColumn('control_team_audit_notes', 'ay')) {
                $table->dropColumn('ay');
            }
            if (Schema::hasColumn('control_team_audit_notes', 'yil')) {
                $table->dropColumn('yil');
            }
        });
    }
};
