<?php

namespace App\Support;

/**
 * raporlama_modeli "Raporlama Şekli" metnini activity_catalogs.raporlama_sikligi değerine çevirir.
 */
final class ReportingFrequencyNormalizer
{
    public static function fromReportingStyle(?string $style): ?string
    {
        $style = trim((string) $style);
        if ($style === '') {
            return null;
        }

        $exact = self::exactStyleMap()[$style] ?? null;
        if ($exact !== null) {
            return $exact;
        }

        $normalized = mb_strtolower($style, 'UTF-8');

        if (str_contains($normalized, 'her hafta') && str_contains($normalized, 'olay')) {
            return 'Haftalık / olay anında';
        }

        if (str_contains($normalized, 'haftalık') && str_contains($normalized, 'aylık')) {
            return 'Haftalık / Aylık';
        }

        if (str_contains($normalized, 'haftalık') || str_contains($normalized, 'haftalik')) {
            return 'Haftalık';
        }

        if (str_contains($normalized, 'aylık') || str_contains($normalized, 'aylik')) {
            return 'Aylık';
        }

        if (str_contains($normalized, 'yıllık') || str_contains($normalized, 'yillik')) {
            return 'Yıllık';
        }

        return 'Haftalık';
    }

    /**
     * raporlama_modeli_temiz.csv içindeki sabit “Raporlama Şekli” metinleri.
     *
     * @return array<string, string>
     */
    private static function exactStyleMap(): array
    {
        return [
            'Haftalık sayısal KPI + sapma özeti' => 'Haftalık',
            'Gelen-sonuçlanan-bekleyen + SLA' => 'Haftalık',
            'Denetim sayısı + uygunsuzluk + kapanış' => 'Haftalık',
            'Haftalık yalnız kilometre taşı, gecikme ve karar ihtiyacı' => 'Haftalık',
            'Sadece hacimli veya kritik olanlar haftalık; diğerleri aylık' => 'Haftalık / Aylık',
            'Haftalık yalnız tamamlanan kritik çıktı veya gecikme' => 'Haftalık',
            'Her hafta ayrı blokta olay bazlı' => 'Haftalık / olay anında',
            'Hacim + memnuniyet + açık kritik konu' => 'Haftalık',
        ];
    }

    public static function fromKategori(string $kategori): ?string
    {
        $style = ReportingModelReader::reportingStyleForKategori($kategori);

        return self::fromReportingStyle($style);
    }
}
