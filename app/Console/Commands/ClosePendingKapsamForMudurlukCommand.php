<?php

namespace App\Console\Commands;

use App\Models\AylikFaaliyet;
use App\Models\User;
use App\Support\AylikFaaliyetPeriodMerge;
use App\Support\AylikFaaliyetRepeaterLock;
use App\Support\AylikFaaliyetWeeklyCarryover;
use App\Support\ReportPeriodWeeks;
use Illuminate\Console\Command;

/**
 * Belirli müdürlük / dönem raporlarında açıkta kalan işleri not ile kapatır.
 */
class ClosePendingKapsamForMudurlukCommand extends Command
{
    protected $signature = 'aylik-faaliyet:close-pending
                            {mudurluk : Müdürlük adı (kısmi eşleşme)}
                            {--yil= : Yıl filtresi}
                            {--ay= : Ay filtresi (01-12)}
                            {--note=Sistem hatası nedeniyle toplu kapatıldı : Kapatma notu}
                            {--apply : Gerçekten kaydet (yoksa dry-run)}';

    protected $description = 'Müdürlük raporlarındaki açıkta kalan kapsam kalemlerini not ile kapatır.';

    public function handle(): int
    {
        $needle = trim((string) $this->argument('mudurluk'));
        $yil = $this->option('yil') !== null ? (int) $this->option('yil') : null;
        $ay = $this->option('ay') !== null
            ? AylikFaaliyetPeriodMerge::normalizeAy((string) $this->option('ay'))
            : null;
        $note = trim((string) $this->option('note'));
        $apply = (bool) $this->option('apply');

        if ($needle === '' || $note === '') {
            $this->error('Müdürlük adı ve not zorunludur.');

            return self::FAILURE;
        }

        $userIds = User::query()
            ->where('name', 'like', '%'.$needle.'%')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($userIds === []) {
            $this->error('Müdürlük bulunamadı: '.$needle);

            return self::FAILURE;
        }

        $query = AylikFaaliyet::query()->whereIn('user_id', $userIds)->orderBy('id');
        if ($yil !== null && $yil > 0) {
            $query->where('yil', $yil);
        }
        if ($ay !== null && $ay !== '') {
            $query->whereIn('ay', AylikFaaliyet::ayQueryVariants($ay));
        }

        $closedLines = 0;
        $touchedReports = 0;
        $today = ReportPeriodWeeks::systemRecordDateString();

        foreach ($query->cursor() as $report) {
            if (! $report instanceof AylikFaaliyet) {
                continue;
            }

            $rows = is_array($report->faaliyetler) ? $report->faaliyetler : [];
            if ($rows === []) {
                continue;
            }

            $changed = false;
            foreach ($rows as $i => $row) {
                if (! is_array($row) || ! is_array($row['kapsam_verileri'] ?? null)) {
                    continue;
                }

                foreach ($row['kapsam_verileri'] as $j => $line) {
                    if (! is_array($line) || (bool) ($line['acikta_kapatildi'] ?? false)) {
                        continue;
                    }

                    $pending = AylikFaaliyetWeeklyCarryover::kapsamPendingAmount($line);
                    if ($pending <= 0.0) {
                        continue;
                    }

                    $week = AylikFaaliyetWeeklyCarryover::resolveWeekForFaaliyetRow($row, [
                        'yil' => $report->yil,
                        'ay' => $report->ay,
                        'hafta' => $report->hafta,
                    ]);

                    $kayitlar = is_array($line['haftalik_kayitlar'] ?? null)
                        ? array_values($line['haftalik_kayitlar'])
                        : [];
                    $oncekiNot = is_numeric($line['not_ile_kapatilan'] ?? null)
                        ? (float) $line['not_ile_kapatilan']
                        : 0.0;

                    $kayitlar[] = [
                        'hafta' => $week,
                        'miktar' => 0,
                        'aciklama' => 'Açık iş kapanışı: '.$note,
                        'yapilma_tarihi' => $today,
                        'tip' => 'kapatma',
                        'kapatilan_acikta' => $pending,
                    ];

                    $rows[$i]['kapsam_verileri'][$j]['haftalik_kayitlar'] = $kayitlar;
                    $rows[$i]['kapsam_verileri'][$j]['not_ile_kapatilan'] = $oncekiNot + $pending;
                    $rows[$i]['kapsam_verileri'][$j]['acikta_kalan'] = 0;
                    $rows[$i]['kapsam_verileri'][$j]['acikta_revize_notu'] = $note;
                    $rows[$i]['kapsam_verileri'][$j]['acikta_revize_tarihi'] = $today;
                    $rows[$i]['kapsam_verileri'][$j]['acikta_kapatma_notu'] = $note;
                    $rows[$i]['kapsam_verileri'][$j]['acikta_kapatildi'] = true;
                    $changed = true;
                    $closedLines++;

                    $this->line(sprintf(
                        'Rapor #%d %s/%s/%s · %s · %s (%.2f)',
                        $report->id,
                        $report->yil,
                        $report->ay,
                        $report->hafta,
                        (string) ($row['faaliyet_kodu'] ?? '—'),
                        (string) ($line['kalem'] ?? '—'),
                        $pending
                    ));
                }
            }

            if (! $changed) {
                continue;
            }

            $touchedReports++;
            if (! $apply) {
                continue;
            }

            $synced = AylikFaaliyetRepeaterLock::syncRowAySonuTotalsFromKapsamVerileri(['faaliyetler' => $rows]);
            $report->faaliyetler = $synced['faaliyetler'] ?? $rows;
            $report->save();
        }

        $this->newLine();
        $this->info("Açık satır: {$closedLines}, rapor: {$touchedReports}".($apply ? ' (kaydedildi)' : ' (dry-run; --apply ile kaydedin)'));

        return self::SUCCESS;
    }
}
