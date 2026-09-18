<?php

namespace App\Support;

final class MaliHizmetlerOdemeTalep
{
    /**
     * @param  array<string, mixed>  $item
     * @return array{
     *     aciklama: string,
     *     tutar: float,
     *     firma_arandi: bool,
     *     tarih: string|null,
     *     arayan_personel: string|null
     * }
     */
    public static function normalizeRow(array $item): array
    {
        $aciklama = trim((string) ($item['aciklama'] ?? ''));
        $tutar = is_numeric($item['tutar'] ?? null) ? (float) $item['tutar'] : 0.0;

        $firmaArandi = self::resolveFirmaArandi($item);
        $tarih = self::resolveTarih($item);
        $arayanPersonel = trim((string) ($item['arayan_personel'] ?? ''));

        return [
            'aciklama' => $aciklama,
            'tutar' => $tutar,
            'firma_arandi' => $firmaArandi,
            'tarih' => $tarih,
            'arayan_personel' => $arayanPersonel !== '' ? $arayanPersonel : null,
        ];
    }

    /**
     * Form doldurma için kayıtlı satırı UI alanlarına çevirir.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function toFormRow(array $item): array
    {
        $normalized = self::normalizeRow($item);

        return [
            'aciklama' => $normalized['aciklama'],
            'tutar' => $normalized['tutar'],
            'firma_arandi' => $normalized['firma_arandi'],
            'tarih' => $normalized['tarih'],
            'arayan_personel' => $normalized['arayan_personel'] ?? '',
        ];
    }

    public static function isBekleyen(array $item): bool
    {
        return ! self::resolveFirmaArandi($item);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function resolveFirmaArandi(array $item): bool
    {
        $value = $item['firma_arandi'] ?? null;
        if ($value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on') {
            return true;
        }

        $legacyDurum = mb_strtolower(trim((string) ($item['durum'] ?? '')));

        return str_contains($legacyDurum, 'arand');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function resolveTarih(array $item): ?string
    {
        $tarih = $item['tarih'] ?? $item['talep_tarihi'] ?? null;
        if (! filled($tarih)) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse((string) $tarih)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
