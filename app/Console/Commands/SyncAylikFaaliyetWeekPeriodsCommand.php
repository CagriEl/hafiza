<?php

namespace App\Console\Commands;

use App\Models\AylikFaaliyet;
use App\Support\ReportPeriodWeeks;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncAylikFaaliyetWeekPeriodsCommand extends Command
{
    protected $signature = 'aylik-faaliyet:sync-week-periods
                            {--dry-run : Değişiklikleri kaydetmeden yalnızca özet göster}
                            {--yil= : Yalnızca belirtilen yıl}
                            {--ay= : Yalnızca belirtilen ay (01-12)}';

    protected $description = 'Mevcut aylık faaliyet kayıtlarındaki hafta numarası ve hafta tarih aralıklarını yeni Pzt–Paz takvimine göre günceller.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $filterYil = $this->option('yil') !== null ? (int) $this->option('yil') : null;
        $filterAy = $this->option('ay') !== null
            ? str_pad((string) preg_replace('/\D/', '', (string) $this->option('ay')), 2, '0', STR_PAD_LEFT)
            : null;

        $updatedReports = 0;
        $updatedRows = 0;
        $updatedKayitlar = 0;

        $query = AylikFaaliyet::query()->orderBy('id');
        if ($filterYil !== null && $filterYil > 0) {
            $query->where('yil', $filterYil);
        }
        if ($filterAy !== null && $filterAy !== '') {
            $query->where('ay', $filterAy);
        }

        $query->chunkById(50, function ($records) use ($dryRun, &$updatedReports, &$updatedRows, &$updatedKayitlar): void {
            foreach ($records as $record) {
                /** @var AylikFaaliyet $record */
                $yil = (int) ($record->yil ?? 0);
                $ay = (int) preg_replace('/\D/', '', (string) ($record->ay ?? ''));
                if ($yil <= 0 || $ay < 1 || $ay > 12) {
                    continue;
                }

                $rows = is_array($record->faaliyetler) ? $record->faaliyetler : [];
                if ($rows === []) {
                    continue;
                }

                $changed = false;
                $referenceDate = optional($record->updated_at)?->copy()
                    ?? optional($record->created_at)?->copy()
                    ?? now();

                foreach ($rows as $i => $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $rowChanged = false;
                    $hafta = $row['hafta'] ?? null;

                    if (! ReportPeriodWeeks::isMonthlyPeriod($hafta)
                        && (! filled($hafta) || ! is_numeric($hafta) || (int) $hafta < 1 || (int) $hafta > ReportPeriodWeeks::WEEK_COUNT)
                    ) {
                        $frequency = trim((string) ($row['raporlama_sikligi'] ?? ''));
                        if (ReportPeriodWeeks::isMonthlyReportingFrequency($frequency)
                            && ! ReportPeriodWeeks::isWeeklyReportingFrequency($frequency)
                        ) {
                            $hafta = ReportPeriodWeeks::MONTHLY_VALUE;
                        } else {
                            $hafta = ReportPeriodWeeks::resolveWeekForReportPeriod($yil, $ay, $referenceDate);
                        }
                        $row['hafta'] = $hafta;
                        $rowChanged = true;
                        $updatedRows++;
                    }

                    if (! ReportPeriodWeeks::isMonthlyPeriod($hafta) && is_numeric($hafta)) {
                        if (array_key_exists('hafta_baslangic', $row) || array_key_exists('hafta_bitis', $row)) {
                            unset($row['hafta_baslangic'], $row['hafta_bitis']);
                            $rowChanged = true;
                            $updatedRows++;
                        }
                    } else {
                        if (array_key_exists('hafta_baslangic', $row) || array_key_exists('hafta_bitis', $row)) {
                            unset($row['hafta_baslangic'], $row['hafta_bitis']);
                            $rowChanged = true;
                            $updatedRows++;
                        }
                    }

                    $kapsam = is_array($row['kapsam_verileri'] ?? null) ? $row['kapsam_verileri'] : [];
                    foreach ($kapsam as $k => $kapsamRow) {
                        if (! is_array($kapsamRow)) {
                            continue;
                        }
                        $kayitlar = is_array($kapsamRow['haftalik_kayitlar'] ?? null)
                            ? $kapsamRow['haftalik_kayitlar']
                            : [];
                        foreach ($kayitlar as $ki => $kayit) {
                            if (! is_array($kayit)) {
                                continue;
                            }
                            $tarih = $kayit['yapilma_tarihi'] ?? null;
                            if (! filled($tarih)) {
                                continue;
                            }
                            try {
                                $parsed = Carbon::parse((string) $tarih)->startOfDay();
                            } catch (\Throwable) {
                                continue;
                            }
                            $resolved = ReportPeriodWeeks::resolveWeekForReportPeriod($yil, $ay, $parsed);
                            if ((int) ($kayit['hafta'] ?? 0) !== $resolved) {
                                $kayitlar[$ki]['hafta'] = $resolved;
                                $rowChanged = true;
                                $updatedKayitlar++;
                            }
                        }
                        $kapsam[$k]['haftalik_kayitlar'] = $kayitlar;
                    }
                    $row['kapsam_verileri'] = $kapsam;

                    if ($rowChanged) {
                        $rows[$i] = $row;
                        $changed = true;
                    }
                }

                if (! $changed) {
                    continue;
                }

                $updatedReports++;
                if ($dryRun) {
                    $this->line("DRY #{$record->id} {$record->yil}/{$record->ay}");

                    continue;
                }

                $record->faaliyetler = array_values($rows);
                $record->save();
            }
        });

        $this->info(($dryRun ? '[dry-run] ' : '')."Rapor: {$updatedReports}, satır: {$updatedRows}, haftalık kayıt: {$updatedKayitlar}");

        return self::SUCCESS;
    }
}
