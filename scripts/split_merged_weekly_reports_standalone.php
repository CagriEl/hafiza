<?php

/**
 * PHP 8.1 uyumlu — Laravel/Composer yüklemez.
 * Canlıda:
 *   php scripts/split_merged_weekly_reports_standalone.php
 *   php scripts/split_merged_weekly_reports_standalone.php --dry-run
 *
 * .env içinden DB_* okur (proje kökünde çalıştırın).
 */

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

$dryRun = in_array('--dry-run', $argv ?? [], true);

function loadEnv(string $path): array
{
    if (! is_file($path)) {
        throw new RuntimeException('.env bulunamadı: '.$path);
    }
    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (
            (str_starts_with($v, '"') && str_ends_with($v, '"'))
            || (str_starts_with($v, "'") && str_ends_with($v, "'"))
        ) {
            $v = substr($v, 1, -1);
        }
        $vars[$k] = $v;
    }

    return $vars;
}

function normalizeAy($ay): string
{
    $digits = preg_replace('/\D/', '', (string) $ay) ?: '';
    $n = (int) $digits;

    return ($n >= 1 && $n <= 12)
        ? str_pad((string) $n, 2, '0', STR_PAD_LEFT)
        : trim((string) $ay);
}

function normalizeHafta($hafta): ?string
{
    if ($hafta === null || $hafta === '') {
        return null;
    }
    $s = strtolower(trim((string) $hafta));
    if (in_array($s, ['aylik', 'aylık', 'monthly'], true)) {
        return 'aylik';
    }
    if (is_numeric($hafta)) {
        $w = (int) $hafta;
        if ($w >= 1 && $w <= 5) {
            return (string) $w;
        }
    }

    return null;
}

function stampHafta(array $rows, string $hafta): array
{
    foreach ($rows as $i => $row) {
        if (! is_array($row)) {
            continue;
        }
        $rows[$i]['hafta'] = ($hafta === 'aylik') ? 'aylik' : (int) $hafta;
    }

    return $rows;
}

$env = loadEnv($root.'/.env');
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$db = $env['DB_DATABASE'] ?? '';
$user = $env['DB_USERNAME'] ?? 'root';
$pass = $env['DB_PASSWORD'] ?? '';

if ($db === '') {
    fwrite(STDERR, "DB_DATABASE boş.\n");
    exit(1);
}

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "Database: {$db}".($dryRun ? " (dry-run)\n" : "\n");

// Ay bazlı unique varsa kaldır.
try {
    $pdo->exec('ALTER TABLE aylik_faaliyets DROP INDEX aylik_faaliyets_user_period_unique');
    echo "Dropped aylik_faaliyets_user_period_unique\n";
} catch (Throwable $e) {
    echo 'Period unique: '.$e->getMessage()."\n";
}

$records = $pdo->query('SELECT * FROM aylik_faaliyets ORDER BY id')->fetchAll();
$created = 0;
$updated = 0;
$skipped = 0;

$pdo->beginTransaction();
try {
    foreach ($records as $record) {
        $faaliyetler = $record['faaliyetler'];
        if (is_string($faaliyetler)) {
            $faaliyetler = json_decode($faaliyetler, true);
        }
        if (! is_array($faaliyetler) || $faaliyetler === []) {
            $skipped++;
            continue;
        }

        $reportHafta = normalizeHafta($record['hafta']) ?? '1';
        $groups = [];
        foreach ($faaliyetler as $row) {
            if (! is_array($row)) {
                continue;
            }
            $h = normalizeHafta($row['hafta'] ?? null) ?? $reportHafta;
            $groups[$h][] = $row;
        }

        $ay = normalizeAy($record['ay']);

        if (count($groups) <= 1) {
            $onlyHafta = (string) (array_key_first($groups) ?? $reportHafta);
            $rows = stampHafta($groups[$onlyHafta] ?? $faaliyetler, $onlyHafta);
            if ((string) $record['hafta'] !== $onlyHafta || json_encode($rows, JSON_UNESCAPED_UNICODE) !== json_encode($faaliyetler, JSON_UNESCAPED_UNICODE)) {
                if (! $dryRun) {
                    $stmt = $pdo->prepare('UPDATE aylik_faaliyets SET ay=?, hafta=?, faaliyetler=? WHERE id=?');
                    $stmt->execute([$ay, $onlyHafta, json_encode($rows, JSON_UNESCAPED_UNICODE), $record['id']]);
                }
                $updated++;
                echo "align id={$record['id']} -> hafta={$onlyHafta}\n";
            } else {
                $skipped++;
            }
            continue;
        }

        $preferred = normalizeHafta($record['hafta']);
        if ($preferred === null || ! isset($groups[$preferred])) {
            $keys = array_keys($groups);
            $numeric = array_values(array_filter($keys, static fn ($k) => ctype_digit((string) $k)));
            sort($numeric, SORT_NUMERIC);
            $preferred = (string) ($numeric[0] ?? $keys[0]);
        } else {
            $preferred = (string) $preferred;
        }

        $keepRows = stampHafta($groups[$preferred], $preferred);
        if (! $dryRun) {
            $stmt = $pdo->prepare('UPDATE aylik_faaliyets SET ay=?, hafta=?, faaliyetler=? WHERE id=?');
            $stmt->execute([$ay, $preferred, json_encode($keepRows, JSON_UNESCAPED_UNICODE), $record['id']]);
        }
        $updated++;
        echo "split-keep id={$record['id']} user={$record['user_id']} {$record['yil']}-{$ay} hafta={$preferred} rows=".count($keepRows)."\n";

        foreach ($groups as $hafta => $rows) {
            if ((string) $hafta === (string) $preferred) {
                continue;
            }
            $rows = stampHafta($rows, (string) $hafta);

            $find = $pdo->prepare('SELECT id, faaliyetler FROM aylik_faaliyets WHERE user_id=? AND yil=? AND ay=? AND hafta=? LIMIT 1');
            $find->execute([$record['user_id'], $record['yil'], $ay, $hafta]);
            $exists = $find->fetch();

            if ($exists) {
                $existingFaal = is_string($exists['faaliyetler'])
                    ? json_decode($exists['faaliyetler'], true)
                    : $exists['faaliyetler'];
                if (! is_array($existingFaal)) {
                    $existingFaal = [];
                }
                $merged = array_values(array_merge($existingFaal, $rows));
                if (! $dryRun) {
                    $stmt = $pdo->prepare('UPDATE aylik_faaliyets SET faaliyetler=? WHERE id=?');
                    $stmt->execute([json_encode($merged, JSON_UNESCAPED_UNICODE), $exists['id']]);
                }
                $updated++;
                echo "split-merge into id={$exists['id']} hafta={$hafta} +".count($rows)."\n";
                continue;
            }

            if (! $dryRun) {
                $ins = $pdo->prepare(
                    'INSERT INTO aylik_faaliyets
                    (user_id, yil, ay, hafta, memur, sozlesmeli_memur, kadrolu_isci, sirket_personeli, gecici_isci,
                     faaliyetler, created_at, updated_at, son_tarih, gecikme_gerekcesi, durum, vice_mayor_notu, vice_mayor_onay_tarihi)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $ins->execute([
                    $record['user_id'],
                    $record['yil'],
                    $ay,
                    $hafta,
                    $record['memur'] ?? 0,
                    $record['sozlesmeli_memur'] ?? 0,
                    $record['kadrolu_isci'] ?? 0,
                    $record['sirket_personeli'] ?? 0,
                    $record['gecici_isci'] ?? 0,
                    json_encode($rows, JSON_UNESCAPED_UNICODE),
                    $record['created_at'],
                    $record['updated_at'],
                    $record['son_tarih'],
                    $record['gecikme_gerekcesi'],
                    $record['durum'] ?? 'planlandi',
                    $record['vice_mayor_notu'],
                    $record['vice_mayor_onay_tarihi'],
                ]);
            }
            $created++;
            echo "split-create user={$record['user_id']} {$record['yil']}-{$ay} hafta={$hafta} rows=".count($rows)." (from id={$record['id']})\n";
        }
    }

    if ($dryRun) {
        $pdo->rollBack();
        echo "Dry-run rollback.\n";
    } else {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}

$total = (int) $pdo->query('SELECT COUNT(*) FROM aylik_faaliyets')->fetchColumn();
echo "updated={$updated} created={$created} skipped={$skipped}\n";
echo "TOTAL={$total}\n";
