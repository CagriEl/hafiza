<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                if (! Schema::hasColumn('users', 'role')) {
                    $table->string('role')->nullable()->after('password');
                }
            });
        }

        if (! Schema::hasTable('control_team_user_directorate')) {
            Schema::create('control_team_user_directorate', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('control_team_user_id');
                $table->unsignedBigInteger('directorate_user_id');
                $table->timestamps();
                $table->unique(['control_team_user_id', 'directorate_user_id'], 'ctud_unique_pair');
            });
        } else {
            Schema::table('control_team_user_directorate', function (Blueprint $table): void {
                if (! Schema::hasColumn('control_team_user_directorate', 'control_team_user_id')) {
                    $table->unsignedBigInteger('control_team_user_id')->nullable()->after('id');
                }
                if (! Schema::hasColumn('control_team_user_directorate', 'directorate_user_id')) {
                    $table->unsignedBigInteger('directorate_user_id')->nullable()->after('control_team_user_id');
                }
            });
        }

        if (! Schema::hasTable('control_team_audit_notes')) {
            Schema::create('control_team_audit_notes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('directorate_user_id');
                $table->unsignedBigInteger('activity_catalog_id')->nullable();
                $table->text('note');
                $table->date('audit_date');
                $table->timestamps();
            });
        } else {
            Schema::table('control_team_audit_notes', function (Blueprint $table): void {
                if (! Schema::hasColumn('control_team_audit_notes', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable()->after('id');
                }
                if (! Schema::hasColumn('control_team_audit_notes', 'directorate_user_id')) {
                    $table->unsignedBigInteger('directorate_user_id')->nullable()->after('user_id');
                }
                if (! Schema::hasColumn('control_team_audit_notes', 'activity_catalog_id')) {
                    $table->unsignedBigInteger('activity_catalog_id')->nullable()->after('directorate_user_id');
                }
                if (! Schema::hasColumn('control_team_audit_notes', 'note')) {
                    $table->text('note')->nullable()->after('activity_catalog_id');
                }
                if (! Schema::hasColumn('control_team_audit_notes', 'audit_date')) {
                    $table->date('audit_date')->nullable()->after('note');
                }
            });
        }
    }

    public function down(): void
    {
        // Veri koruma: finalize migration geri alınırken veri silmemek için boş bırakıldı.
    }
};
