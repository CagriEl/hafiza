<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routine_work_items', function (Blueprint $table): void {
            $table->date('start_date')->nullable()->after('user_id');
            $table->date('end_date')->nullable()->after('start_date');
        });

        DB::table('routine_work_items')->orderBy('id')->each(function (object $row): void {
            $date = $row->work_date ?? null;
            if ($date === null) {
                return;
            }

            DB::table('routine_work_items')
                ->where('id', $row->id)
                ->update([
                    'start_date' => $date,
                    'end_date' => $date,
                ]);
        });

        Schema::table('routine_work_items', function (Blueprint $table): void {
            $table->dropColumn('work_date');
            $table->date('start_date')->nullable(false)->change();
            $table->date('end_date')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('routine_work_items', function (Blueprint $table): void {
            $table->date('work_date')->nullable()->after('user_id');
        });

        DB::table('routine_work_items')->orderBy('id')->each(function (object $row): void {
            DB::table('routine_work_items')
                ->where('id', $row->id)
                ->update([
                    'work_date' => $row->start_date ?? $row->end_date,
                ]);
        });

        Schema::table('routine_work_items', function (Blueprint $table): void {
            $table->dropColumn(['start_date', 'end_date']);
            $table->date('work_date')->nullable(false)->change();
        });
    }
};
