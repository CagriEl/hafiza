<?php

/**
 * Birleştirilmiş aylık faaliyet raporlarını haftalara ayırır.
 *
 * Aynı kayıttaki faaliyetler[].hafta değerlerine göre
 * her (user_id, yil, ay, hafta) için ayrı rapor üretir.
 *
 * Kullanım:
 *   DB_DATABASE=hafiza_canli php scripts/split_merged_weekly_reports.php
 *   php scripts/split_merged_weekly_reports.php --database=hafiza_canli --dry-run
 */

use App\Support\ReportPeriodWeeks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$dryRun = in_array('--dry-run', $argv, true);
$database = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--database=')) {
        $database = substr($arg, strlen('--database='));
    }
}

if ($database) {
    config(['database.connections.mysql.database' => $database]);
    DB::purge('mysql');
    DB::reconnect('mysql');
}

$dbName = DB::connection()->getDatabaseName();
echo "Database: {$dbName}".($dryRun ? " (dry-run)\n" : "\n");

if (! Schema::hasTable('aylik_faaliyets')) {
    fwrite(STDERR, "aylik_faaliyets yok.\n");
    exit(1);
}

// Ay bazlı unique varsa haftalık ayrımı engeller.
try {
    Schema::table('aylik_faaliyets', function ($table) {
        $table->dropUnique('aylik_faaliyets_user_period_unique');
    });
    echo "Dropped aylik_faaliyets_user_period_unique\n";
} catch (Throwable $e) {
    try {
        DB::statement('ALTER TABLE aylik_faaliyets DROP INDEX aylik_faaliyets_user_period_unique');
        echo "Dropped aylik_faaliyets_user_period_unique (raw)\n";
    } catch (Throwable $e2) {
        echo 'Period unique: '.$e2->getMessage()."\n";
    }
}

function normalizeAy(string|int|null $ay): string
{
    $digits = preg_replace('/\D/', '', (string) $ay) ?: '';
    $n = (int) $digits;

    return ($n >= 1 && $n <= 12)
        ? str_pad((string) $n, 2, '0', STR_PAD_LEFT)
        : trim((string) $ay);
}

function normalizeHafta(mixed $hafta): ?string
{
    if ($hafta === null || $hafta === '') {
        return null;
    }
    if (ReportPeriodWeeks::isMonthlyPeriod($hafta)) {
        return ReportPeriodWeeks::MONTHLY_VALUE;
    }
    if (is_numeric($hafta)) {
        $w = (int) $hafta;
        if ($w >= 1 && $w <= ReportPeriodWeeks::WEEK_COUNT) {
            return (string) $w;
        }
    }

    return null;
}

/** @param list<array<string, mixed>> $rows */
function stampHafta(array $rows, string $hafta): array
{
    foreach ($rows as $i => $row) {
        if (! is_array($row)) {
            continue;
        }
        $rows[$i]['hafta'] = ReportPeriodWeeks::isMonthlyPeriod($hafta)
            ? ReportPeriodWeeks::MONTHLY_VALUE
            : (int) $hafta;
    }

    return $rows;
}

$records = DB::table('aylik_faaliyets')->orderBy('id')->get();
$created = 0;
$updated = 0;
$skipped = 0;
$details = [];

DB::beginTransaction();
try {
    foreach ($records as $record) {
        $faaliyetler = $record->faaliyetler;
        if (is_string($faaliyetler)) {
            $faaliyetler = json_decode($faaliyetler, true);
        }
        if (! is_array($faaliyetler) || $faaliyetler === []) {
            $skipped++;
            continue;
        }

        $reportHafta = normalizeHafta($record->hafta) ?? '1';
        $groups = [];
        foreach ($faaliyetler as $row) {
            if (! is_array($row)) {
                continue;
            }
            $h = normalizeHafta($row['hafta'] ?? null) ?? $reportHafta;
            $groups[$h][] = $row;
        }

        if (count($groups) <= 1) {
            // Tek hafta: rapor.hafta ile satırları hizala.
            $onlyHafta = array_key_first($groups) ?? $reportHafta;
            $rows = stampHafta($groups[$onlyHafta] ?? $faaliyetler, $onlyHafta);
            if ((string) $record->hafta !== $onlyHafta || json_encode($rows) !== json_encode($faaliyetler)) {
                if (! $dryRun) {
                    DB::table('aylik_faaliyets')->where('id', $record->id)->update([
                        'ay' => normalizeAy($record->ay),
                        'hafta' => $onlyHafta,
                        'faaliyetler' => json_encode($rows, JSON_UNESCAPED_UNICODE),
                        'updated_at' => $record->updated_at,
                    ]);
                }
                $updated++;
                $details[] = "align id={$record->id} -> hafta={$onlyHafta}";
            } else {
                $skipped++;
            }
            continue;
        }

        // Birden fazla hafta: orijinal kaydı "öncelikli" haftada tut, diğerlerini yeni rapor yap.
        $preferred = normalizeHafta($record->hafta);
        if ($preferred === null || ! isset($groups[$preferred])) {
            // En küçük hafta no; aylik varsa onu tercih etme eğer haftalık da varsa.
            $keys = array_keys($groups);
            $numeric = array_values(array_filter($keys, fn ($k) => ctype_digit((string) $k)));
            sort($numeric, SORT_NUMERIC);
            $preferred = $numeric[0] ?? $keys[0];
        }

        $ay = normalizeAy($record->ay);
        $keepRows = stampHafta($groups[$preferred], $preferred);

        if (! $dryRun) {
            DB::table('aylik_faaliyets')->where('id', $record->id)->update([
                'ay' => $ay,
                'hafta' => $preferred,
                'faaliyetler' => json_encode($keepRows, JSON_UNESCAPED_UNICODE),
            ]);
        }
        $updated++;
        $details[] = "split-keep id={$record->id} user={$record->user_id} {$record->yil}-{$ay} hafta={$preferred} rows=".count($keepRows);

        foreach ($groups as $hafta => $rows) {
            if ((string) $hafta === (string) $preferred) {
                continue;
            }
            $rows = stampHafta($rows, (string) $hafta);

            $exists = DB::table('aylik_faaliyets')
                ->where('user_id', $record->user_id)
                ->where('yil', $record->yil)
                ->where('ay', $ay)
                ->where('hafta', $hafta)
                ->first();

            if ($exists) {
                $existingFaal = is_string($exists->faaliyetler)
                    ? json_decode($exists->faaliyetler, true)
                    : $exists->faaliyetler;
                if (! is_array($existingFaal)) {
                    $existingFaal = [];
                }
                $merged = array_values(array_merge($existingFaal, $rows));
                if (! $dryRun) {
                    DB::table('aylik_faaliyets')->where('id', $exists->id)->update([
                        'faaliyetler' => json_encode($merged, JSON_UNESCAPED_UNICODE),
                    ]);
                }
                $updated++;
                $details[] = "split-merge into id={$exists->id} hafta={$hafta} +".count($rows);
                continue;
            }

            if (! $dryRun) {
                DB::table('aylik_faaliyets')->insert([
                    'user_id' => $record->user_id,
                    'yil' => $record->yil,
                    'ay' => $ay,
                    'hafta' => $hafta,
                    'memur' => $record->memur,
                    'sozlesmeli_memur' => $record->sozlesmeli_memur,
                    'kadrolu_isci' => $record->kadrolu_isci,
                    'sirket_personeli' => $record->sirket_personeli,
                    'gecici_isci' => $record->gecici_isci,
                    'faaliyetler' => json_encode($rows, JSON_UNESCAPED_UNICODE),
                    'created_at' => $record->created_at,
                    'updated_at' => $record->updated_at,
                    'son_tarih' => $record->son_tarih,
                    'gecikme_gerekcesi' => $record->gecikme_gerekcesi,
                    'durum' => $record->durum,
                    'vice_mayor_notu' => $record->vice_mayor_notu,
                    'vice_mayor_onay_tarihi' => $record->vice_mayor_onay_tarihi,
                ]);
            }
            $created++;
            $details[] = "split-create user={$record->user_id} {$record->yil}-{$ay} hafta={$hafta} rows=".count($rows)." (from id={$record->id})";
        }
    }

    if ($dryRun) {
        DB::rollBack();
        echo "Dry-run rollback.\n";
    } else {
        DB::commit();
    }
} catch (Throwable $e) {
    DB::rollBack();
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}

echo "updated={$updated} created={$created} skipped={$skipped}\n";
foreach ($details as $line) {
    echo $line."\n";
}

echo "\n=== After counts ===\n";
$rows = DB::table('aylik_faaliyets as a')
    ->join('users as u', 'u.id', '=', 'a.user_id')
    ->selectRaw('u.name, a.user_id, COUNT(*) as c')
    ->groupBy('u.name', 'a.user_id')
    ->orderByDesc('c')
    ->orderBy('u.name')
    ->get();
foreach ($rows as $r) {
    echo "{$r->c}\t{$r->name} (user {$r->user_id})\n";
}
echo 'TOTAL '.DB::table('aylik_faaliyets')->count()."\n";

$kultur = DB::table('aylik_faaliyets as a')
    ->join('users as u', 'u.id', '=', 'a.user_id')
    ->where('u.name', 'like', '%ültür%')
    ->orderBy('a.yil')->orderBy('a.ay')->orderBy('a.hafta')
    ->get(['a.id', 'a.yil', 'a.ay', 'a.hafta', 'u.name']);
echo "\n=== Kültür ===\n";
foreach ($kultur as $r) {
    echo "id={$r->id} {$r->yil}-{$r->ay} hafta={$r->hafta}\n";
}
