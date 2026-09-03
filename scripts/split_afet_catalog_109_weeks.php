<?php

/**
 * Afet İşleri Md. raporlarında activity_catalog_id = 109 satırını
 * 1. ve 2. haftaya ayırır (aynı satırda birleşmişse).
 *
 * Kullanım:
 *   php scripts/split_afet_catalog_109_weeks.php
 *   php scripts/split_afet_catalog_109_weeks.php --dry-run
 *   php scripts/split_afet_catalog_109_weeks.php --rapor-id=123
 */

use App\Models\AylikFaaliyet;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$dryRun = in_array('--dry-run', $argv ?? [], true);
$raporIdFilter = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--rapor-id=')) {
        $raporIdFilter = (int) substr($arg, strlen('--rapor-id='));
    }
}

$catalogId = 109;
$targetWeeks = [1, 2];

$afetUsers = User::query()
    ->where(function ($q): void {
        $q->where('name', 'like', '%Afet İşleri%')
            ->orWhere('name', 'like', '%Afet%Risk%');
    })
    ->get(['id', 'name']);

if ($afetUsers->isEmpty()) {
    fwrite(STDERR, "Afet İşleri müdürlüğü kullanıcısı bulunamadı.\n");
    exit(1);
}

echo 'Afet kullanıcıları: '.$afetUsers->map(fn ($u) => $u->id.'='.$u->name)->implode(', ').PHP_EOL;

$query = AylikFaaliyet::query()
    ->whereIn('user_id', $afetUsers->pluck('id'))
    ->orderBy('id');

if ($raporIdFilter) {
    $query->whereKey($raporIdFilter);
}

$updated = 0;

foreach ($query->cursor() as $rapor) {
    $rows = is_array($rapor->faaliyetler) ? $rapor->faaliyetler : [];
    if ($rows === []) {
        continue;
    }

    $newRows = [];
    $changed = false;

    foreach ($rows as $row) {
        if (! is_array($row)) {
            $newRows[] = $row;
            continue;
        }

        $cid = (int) ($row['activity_catalog_id'] ?? 0);
        if ($cid !== $catalogId) {
            $newRows[] = $row;
            continue;
        }

        $rowWeek = $row['hafta'] ?? null;
        $kayitWeeks = [];
        foreach ($row['kapsam_verileri'] ?? [] as $kapsam) {
            if (! is_array($kapsam)) {
                continue;
            }
            foreach ($kapsam['haftalik_kayitlar'] ?? [] as $kayit) {
                if (! is_array($kayit)) {
                    continue;
                }
                if (is_numeric($kayit['hafta'] ?? null)) {
                    $kayitWeeks[(int) $kayit['hafta']] = true;
                }
            }
        }
        $kayitWeekList = array_keys($kayitWeeks);
        sort($kayitWeekList);

        $needsSplit = false;
        // Aynı satırda 1 ve 2 kaydı var
        if (in_array(1, $kayitWeekList, true) && in_array(2, $kayitWeekList, true)) {
            $needsSplit = true;
        }
        // Satır haftası tek ama kullanıcı 1+2 birleşik görüyor: hem 1 hem 2 hedef
        if ($needsSplit === false && in_array((int) $rowWeek, $targetWeeks, true) === false && $kayitWeekList === []) {
            // Sadece tek satır, hafta belirsiz/yanlış — 1 ve 2 olarak çoğalt
            $needsSplit = true;
        }

        if (! $needsSplit) {
            // Zaten tek hafta satırıysa dokunma
            $newRows[] = $row;
            continue;
        }

        echo sprintf(
            "Rapor #%d (%s-%s): katalog %d ayrılıyor (satır hafta=%s, kayıt haftaları=%s)\n",
            $rapor->id,
            $rapor->yil,
            $rapor->ay,
            $catalogId,
            json_encode($rowWeek),
            implode(',', $kayitWeekList)
        );

        foreach ($targetWeeks as $week) {
            $clone = $row;
            $clone['hafta'] = $week;

            if (isset($clone['kapsam_verileri']) && is_array($clone['kapsam_verileri'])) {
                foreach ($clone['kapsam_verileri'] as $ki => $kapsam) {
                    if (! is_array($kapsam)) {
                        continue;
                    }
                    $kayitlar = is_array($kapsam['haftalik_kayitlar'] ?? null)
                        ? $kapsam['haftalik_kayitlar']
                        : [];
                    $filtered = [];
                    $done = 0.0;
                    foreach ($kayitlar as $kayit) {
                        if (! is_array($kayit)) {
                            continue;
                        }
                        $kh = is_numeric($kayit['hafta'] ?? null) ? (int) $kayit['hafta'] : null;
                        if ($kh === $week) {
                            $filtered[] = $kayit;
                            $done += (float) ($kayit['miktar'] ?? 0);
                        }
                    }
                    $clone['kapsam_verileri'][$ki]['haftalik_kayitlar'] = $filtered;
                    if ($kayitlar !== []) {
                        $clone['kapsam_verileri'][$ki]['gerceklesen'] = $done;
                        $plan = (float) ($kapsam['ongorulen'] ?? $kapsam['deger'] ?? 0);
                        $clone['kapsam_verileri'][$ki]['acikta_kalan'] = max(0, $plan - $done);
                    }
                }
            }

            $newRows[] = $clone;
        }

        $changed = true;
    }

    if (! $changed) {
        continue;
    }

    if ($dryRun) {
        echo "[dry-run] Rapor #{$rapor->id} güncellenmeyecek.\n";
        continue;
    }

    $rapor->faaliyetler = array_values($newRows);
    $rapor->save();
    $updated++;
    echo "Rapor #{$rapor->id} kaydedildi.\n";
}

echo $dryRun
    ? "Dry-run tamam. Değişiklik yazılmadı.\n"
    : "Tamam. Güncellenen rapor sayısı: {$updated}\n";
