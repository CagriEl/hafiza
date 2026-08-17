<?php

namespace App\Support;

use App\Models\ControlTeamAuditNote;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analiz notundaki haftalık faaliyet raporunun PDF çıktısı.
 */
final class AnalizEkibiHaftalikFaaliyetPdf
{
    public static function downloadForNote(ControlTeamAuditNote $note): StreamedResponse
    {
        return self::stream(self::htmlForNote($note), self::filenameForNote($note));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function downloadFromFormState(array $state, ?User $viewer = null): StreamedResponse
    {
        $screen = self::screenFromFormState($state, $viewer);

        return self::stream(self::htmlFromFormState($state, $viewer), self::filenameFromScreen($screen));
    }

    public static function htmlForNote(ControlTeamAuditNote $note): string
    {
        $note->loadMissing(['directorate', 'user']);

        return view('pdf.analiz-ekibi-haftalik-rapor', [
            'screen' => AnalizEkibiHaftalikFaaliyetEkrani::fromNote($note),
            'meta' => AnalizEkibiRaporVerileri::metaFromNote($note),
            'noteText' => trim((string) ($note->note ?? '')),
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function htmlFromFormState(array $state, ?User $viewer = null): string
    {
        $viewer ??= auth()->user() instanceof User ? auth()->user() : null;
        $screen = self::screenFromFormState($state, $viewer);
        $ay = str_pad(preg_replace('/\D/', '', (string) ($state['ay'] ?? '')) ?: '', 2, '0', STR_PAD_LEFT);
        $hafta = ReportPeriodWeeks::normalizeReportHafta($state['hafta'] ?? null);

        return view('pdf.analiz-ekibi-haftalik-rapor', [
            'screen' => $screen,
            'meta' => [
                'mudurluk' => (string) ($screen['mudurluk_adi'] ?? '—'),
                'analiz_eden' => trim((string) ($viewer?->name ?? '—')),
                'analiz_tarihi' => now()->format('d.m.Y'),
                'donem' => ($state['yil'] ?? '').($ay !== '' ? '-'.$ay : ''),
                'rapor_haftasi' => $hafta !== null
                    ? (ReportPeriodWeeks::periodLabelForRecord((int) ($state['yil'] ?? 0), $ay, $hafta) ?? $hafta)
                    : '—',
            ],
            'noteText' => trim((string) ($state['note'] ?? '')),
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function screenFromFormState(array $state, ?User $viewer = null): array
    {
        $viewer ??= auth()->user() instanceof User ? auth()->user() : null;

        return AnalizEkibiHaftalikFaaliyetEkrani::build(
            $viewer,
            (int) ($state['directorate_user_id'] ?? 0),
            (int) ($state['yil'] ?? 0),
            (string) ($state['ay'] ?? ''),
            $state['hafta'] ?? null
        );
    }

    /**
     * @param  array<string, mixed>  $screen
     */
    public static function filenameFromScreen(array $screen): string
    {
        $mudurluk = Str::slug((string) ($screen['mudurluk_adi'] ?? 'mudurluk')) ?: 'mudurluk';
        $hafta = (string) ($screen['hafta'] ?? '');

        return 'analiz_raporu_'.$mudurluk.'_'.(int) ($screen['yil'] ?? 0).'_'.(string) ($screen['ay'] ?? '00')
            .($hafta !== '' ? '_h'.$hafta : '').'.pdf';
    }

    public static function filenameForNote(ControlTeamAuditNote $note): string
    {
        $note->loadMissing('directorate');
        $mudurluk = Str::slug($note->directorate?->name ?? 'mudurluk') ?: 'mudurluk';
        $ay = str_pad(preg_replace('/\D/', '', (string) ($note->ay ?? '')) ?: '00', 2, '0', STR_PAD_LEFT);
        $hafta = ReportPeriodWeeks::normalizeReportHafta($note->hafta)
            ?? ReportPeriodWeeks::normalizeReportHafta($note->aylikFaaliyet?->hafta);

        return 'analiz_raporu_'.$mudurluk.'_'.(int) ($note->yil ?? 0).'_'.$ay
            .($hafta !== null ? '_h'.$hafta : '').'.pdf';
    }

    private static function stream(string $html, string $filename): StreamedResponse
    {
        $pdf = Pdf::loadHTML($html)
            ->setPaper('a4', 'landscape')
            ->setWarnings(false);

        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf->output();
        }, $filename);
    }
}
