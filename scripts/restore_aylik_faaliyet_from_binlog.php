<?php

/**
 * Restores aylik_faaliyets rows deleted by the month-unique merge migration.
 * Source: mysqlbinlog DECODE-ROWS dump of the merge transaction.
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$binlogDump = '/tmp/aylik_merge_binlog.txt';
if (! is_file($binlogDump)) {
    fwrite(STDERR, "Missing binlog dump: {$binlogDump}\n");
    exit(1);
}

$lines = file($binlogDump, FILE_IGNORE_NEW_LINES);
$events = [];
$current = null;
$mode = null;

foreach ($lines as $line) {
    if (preg_match('/Table_map:\s+`[^`]+`\.`([^`]+)`/', $line, $tm)
        && ($tm[1] ?? '') !== 'aylik_faaliyets') {
        if ($current !== null) {
            $events[] = $current;
            $current = null;
            $mode = null;
        }
        continue;
    }

    if (str_contains($line, '### UPDATE `hafiza`.`aylik_faaliyets`')) {
        if ($current !== null) {
            $events[] = $current;
        }
        $current = ['type' => 'UPDATE', 'where' => [], 'set' => []];
        $mode = 'WHERE';
        continue;
    }
    if (str_contains($line, '### DELETE FROM `hafiza`.`aylik_faaliyets`')) {
        if ($current !== null) {
            $events[] = $current;
        }
        $current = ['type' => 'DELETE', 'where' => []];
        $mode = 'WHERE';
        continue;
    }
    if ($current === null) {
        continue;
    }
    if (trim($line) === '### WHERE') {
        $mode = 'WHERE';
        continue;
    }
    if (trim($line) === '### SET') {
        $mode = 'SET';
        continue;
    }
    if (preg_match('/^###\s+@(\d+)=(.*)$/', $line, $m)) {
        $idx = (int) $m[1];
        $value = parseBinlogValue($m[2]);
        if ($mode === 'WHERE') {
            $current['where'][$idx] = $value;
        } elseif ($mode === 'SET') {
            $current['set'][$idx] = $value;
        }
    }
}
if ($current !== null) {
    $events[] = $current;
}

function parseBinlogValue(string $raw): mixed
{
    if ($raw === 'NULL') {
        return null;
    }
    if (preg_match("/^'(.*)'$/s", $raw, $m)) {
        return stripcslashes($m[1]);
    }
    if (is_numeric($raw)) {
        return str_contains($raw, '.') ? (float) $raw : (int) $raw;
    }

    return $raw;
}

function tsToDatetime(mixed $v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }

    return date('Y-m-d H:i:s', (int) $v);
}

function dateOrNull(mixed $v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    // unix timestamp in binlog for date columns sometimes
    if (is_int($v) || (is_string($v) && ctype_digit($v))) {
        return date('Y-m-d', (int) $v);
    }

    return (string) $v;
}

function rowFromCols(array $cols): array
{
    // Pre-hafta layout at merge time.
    return [
        'id' => $cols[1] ?? null,
        'user_id' => $cols[2] ?? null,
        'yil' => $cols[3] ?? null,
        'ay' => $cols[4] ?? null,
        'memur' => $cols[5] ?? 0,
        'sozlesmeli_memur' => $cols[6] ?? 0,
        'kadrolu_isci' => $cols[7] ?? 0,
        'sirket_personeli' => $cols[8] ?? 0,
        'gecici_isci' => $cols[9] ?? 0,
        'faaliyetler' => $cols[10] ?? '[]',
        'coordination_target_user_ids' => $cols[11] ?? null,
        'created_at' => tsToDatetime($cols[12] ?? null),
        'updated_at' => tsToDatetime($cols[13] ?? null),
        'son_tarih' => dateOrNull($cols[14] ?? null),
        'gecikme_gerekcesi' => $cols[15] ?? null,
        'durum' => $cols[16] ?? 'planlandi',
        'vice_mayor_notu' => $cols[17] ?? null,
        'vice_mayor_onay_tarihi' => tsToDatetime($cols[18] ?? null),
    ];
}

function withHafta(array $row, string $hafta): array
{
    $row['hafta'] = $hafta;
    $faaliyetler = $row['faaliyetler'];
    if (is_string($faaliyetler)) {
        $decoded = json_decode($faaliyetler, true);
        if (is_array($decoded)) {
            foreach ($decoded as &$item) {
                if (is_array($item)) {
                    $item['hafta'] = (int) $hafta;
                }
            }
            unset($item);
            $row['faaliyetler'] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }
    }

    return $row;
}

$toRestoreDeletes = [];
$toRestoreUpdates = [];

foreach ($events as $event) {
    $type = $event['type'] ?? '';
    if ($type === 'DELETE') {
        $row = rowFromCols($event['where']);
        $id = (int) $row['id'];
        if (in_array($id, [20, 21, 22, 24], true)) {
            $toRestoreDeletes[$id] = $row;
        }
    }
    if ($type === 'UPDATE') {
        $row = rowFromCols($event['where']);
        $id = (int) $row['id'];
        if (in_array($id, [19, 23], true) && ! empty($event['where'])) {
            // Keep first WHERE image (pre-merge).
            if (! isset($toRestoreUpdates[$id])) {
                $toRestoreUpdates[$id] = $row;
            }
        }
    }
}

echo 'Parsed events: '.count($events)."\n";
echo 'Deletes to restore: '.implode(',', array_keys($toRestoreDeletes))."\n";
echo 'Updates to restore: '.implode(',', array_keys($toRestoreUpdates))."\n";

$haftaMap = [
    19 => '1',
    20 => '2',
    21 => '3',
    22 => '4',
    23 => '1',
    24 => '2',
];

if ($toRestoreDeletes === [] && $toRestoreUpdates === []) {
    fwrite(STDERR, "Nothing parsed to restore.\n");
    exit(1);
}

// Month-only unique blocks multiple weekly reports; keep week unique only.
try {
    DB::statement('ALTER TABLE aylik_faaliyets DROP INDEX aylik_faaliyets_user_period_unique');
    echo "Dropped aylik_faaliyets_user_period_unique\n";
} catch (Throwable $e) {
    echo 'Period unique drop skipped: '.$e->getMessage()."\n";
}

DB::beginTransaction();
try {
    foreach ($toRestoreUpdates as $id => $row) {
        $payload = withHafta($row, $haftaMap[$id]);
        DB::table('aylik_faaliyets')->where('id', $id)->update([
            'faaliyetler' => $payload['faaliyetler'],
            'hafta' => $payload['hafta'],
            'memur' => $payload['memur'],
            'sozlesmeli_memur' => $payload['sozlesmeli_memur'],
            'kadrolu_isci' => $payload['kadrolu_isci'],
            'sirket_personeli' => $payload['sirket_personeli'],
            'gecici_isci' => $payload['gecici_isci'],
            'coordination_target_user_ids' => $payload['coordination_target_user_ids'],
            'updated_at' => $payload['updated_at'],
            'durum' => $payload['durum'],
        ]);
        echo "Restored UPDATE id={$id} hafta={$payload['hafta']}\n";
    }

    foreach ($toRestoreDeletes as $id => $row) {
        $payload = withHafta($row, $haftaMap[$id]);
        $exists = DB::table('aylik_faaliyets')->where('id', $id)->exists();
        if ($exists) {
            echo "Skip INSERT id={$id} (already exists)\n";
            continue;
        }
        DB::table('aylik_faaliyets')->insert([
            'id' => $payload['id'],
            'user_id' => $payload['user_id'],
            'yil' => $payload['yil'],
            'ay' => $payload['ay'],
            'hafta' => $payload['hafta'],
            'memur' => $payload['memur'],
            'sozlesmeli_memur' => $payload['sozlesmeli_memur'],
            'kadrolu_isci' => $payload['kadrolu_isci'],
            'sirket_personeli' => $payload['sirket_personeli'],
            'gecici_isci' => $payload['gecici_isci'],
            'faaliyetler' => $payload['faaliyetler'],
            'coordination_target_user_ids' => $payload['coordination_target_user_ids'],
            'created_at' => $payload['created_at'],
            'updated_at' => $payload['updated_at'],
            'son_tarih' => $payload['son_tarih'],
            'gecikme_gerekcesi' => $payload['gecikme_gerekcesi'],
            'durum' => $payload['durum'],
            'vice_mayor_notu' => $payload['vice_mayor_notu'],
            'vice_mayor_onay_tarihi' => $payload['vice_mayor_onay_tarihi'],
        ]);
        echo "Restored DELETE id={$id} hafta={$payload['hafta']}\n";
    }

    DB::commit();
} catch (Throwable $e) {
    DB::rollBack();
    fwrite(STDERR, $e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(1);
}

$rows = DB::table('aylik_faaliyets')->orderBy('id')->get(['id', 'user_id', 'yil', 'ay', 'hafta', 'durum']);
echo "\nCurrent reports:\n";
foreach ($rows as $r) {
    echo "{$r->id}\tuser={$r->user_id}\t{$r->yil}-{$r->ay}\thafta={$r->hafta}\t{$r->durum}\n";
}
echo 'Total: '.$rows->count()."\n";
