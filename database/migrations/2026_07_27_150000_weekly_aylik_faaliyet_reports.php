<?php

use App\Models\AylikFaaliyet;
use App\Support\AylikFaaliyetPeriodMerge;
use App\Support\ReportPeriodWeeks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('aylik_faaliyets')) {
            return;
        }

        try {
            Schema::table('aylik_faaliyets', function (Blueprint $table) {
                $table->dropUnique('aylik_faaliyets_user_period_unique');
            });
        } catch (\Throwable) {
            // Index yoksa devam.
        }

        if (! Schema::hasColumn('aylik_faaliyets', 'hafta')) {
            Schema::table('aylik_faaliyets', function (Blueprint $table) {
                $table->string('hafta', 16)->default('1')->after('ay');
            });
        }

        AylikFaaliyet::query()->orderBy('id')->each(function (AylikFaaliyet $record): void {
            $ay = AylikFaaliyetPeriodMerge::normalizeAy((string) ($record->ay ?? ''));
            $yil = (int) ($record->yil ?? 0);
            $hafta = self::inferHafta($record, $yil, $ay);

            $dirty = false;
            if ((string) $record->ay !== $ay) {
                $record->ay = $ay;
                $dirty = true;
            }
            if ((string) ($record->hafta ?? '') !== $hafta) {
                $record->hafta = $hafta;
                $dirty = true;
            }
            if ($dirty) {
                $record->saveQuietly();
            }
        });

        // Aynı user/yil/ay/hafta çakışmalarını boş haftaya kaydır.
        $dupes = AylikFaaliyet::query()
            ->selectRaw('user_id, yil, ay, hafta, COUNT(*) as c, MIN(id) as keep_id')
            ->groupBy('user_id', 'yil', 'ay', 'hafta')
            ->having('c', '>', 1)
            ->get();

        foreach ($dupes as $dupe) {
            AylikFaaliyet::query()
                ->where('user_id', $dupe->user_id)
                ->where('yil', $dupe->yil)
                ->where('ay', $dupe->ay)
                ->where('hafta', $dupe->hafta)
                ->where('id', '!=', $dupe->keep_id)
                ->orderBy('id')
                ->each(function (AylikFaaliyet $extra) use ($dupe): void {
                    for ($w = 1; $w <= ReportPeriodWeeks::WEEK_COUNT; $w++) {
                        $candidate = (string) $w;
                        $exists = AylikFaaliyet::query()
                            ->where('user_id', $dupe->user_id)
                            ->where('yil', $dupe->yil)
                            ->where('ay', $dupe->ay)
                            ->where('hafta', $candidate)
                            ->exists();
                        if (! $exists) {
                            $extra->hafta = $candidate;
                            $extra->saveQuietly();

                            return;
                        }
                    }

                    $extra->hafta = ReportPeriodWeeks::MONTHLY_VALUE;
                    $monthlyTaken = AylikFaaliyet::query()
                        ->where('user_id', $dupe->user_id)
                        ->where('yil', $dupe->yil)
                        ->where('ay', $dupe->ay)
                        ->where('hafta', ReportPeriodWeeks::MONTHLY_VALUE)
                        ->whereKeyNot($extra->id)
                        ->exists();
                    if ($monthlyTaken) {
                        // Son çare: silinemez; unique bozulmasın diye id bazlı geçici değer kullanma —
                        // en az çakışan haftayı zorla overwrite etme, kaydı hafta 1'e bırakıp
                        // unique eklemeden önce silmek yerine delete extras that still conflict.
                        $extra->delete();

                        return;
                    }
                    $extra->saveQuietly();
                });
        }

        Schema::table('aylik_faaliyets', function (Blueprint $table) {
            $table->unique(['user_id', 'yil', 'ay', 'hafta'], 'aylik_faaliyets_user_week_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('aylik_faaliyets')) {
            return;
        }

        try {
            Schema::table('aylik_faaliyets', function (Blueprint $table) {
                $table->dropUnique('aylik_faaliyets_user_week_unique');
            });
        } catch (\Throwable) {
        }

        if (Schema::hasColumn('aylik_faaliyets', 'hafta')) {
            Schema::table('aylik_faaliyets', function (Blueprint $table) {
                $table->dropColumn('hafta');
            });
        }
    }

    private static function inferHafta(AylikFaaliyet $record, int $yil, string $ay): string
    {
        $counts = [];
        $rows = is_array($record->faaliyetler) ? $record->faaliyetler : [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $h = $row['hafta'] ?? null;
            if (ReportPeriodWeeks::isMonthlyPeriod($h)) {
                $key = ReportPeriodWeeks::MONTHLY_VALUE;
            } elseif (is_numeric($h) && (int) $h >= 1 && (int) $h <= ReportPeriodWeeks::WEEK_COUNT) {
                $key = (string) (int) $h;
            } else {
                continue;
            }
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        if ($counts !== []) {
            arsort($counts);

            return (string) array_key_first($counts);
        }

        if ($yil > 0 && $ay !== '') {
            return (string) ReportPeriodWeeks::resolveWeekForReportPeriod($yil, (int) $ay);
        }

        return '1';
    }
};
