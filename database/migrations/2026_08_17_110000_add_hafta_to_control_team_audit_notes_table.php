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
            if (! Schema::hasColumn('control_team_audit_notes', 'hafta')) {
                $table->string('hafta', 16)->nullable()->after('ay');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('control_team_audit_notes')) {
            return;
        }

        Schema::table('control_team_audit_notes', function (Blueprint $table): void {
            if (Schema::hasColumn('control_team_audit_notes', 'hafta')) {
                $table->dropColumn('hafta');
            }
        });
    }
};
