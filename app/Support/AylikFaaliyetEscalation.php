<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Haftalık operasyonel rapor satırlarında üst yönetimin bilgilendirilmesi gereken durumlar.
 * Eski şema (son_tarih / durum / konu) ile yeni şema (hedef / gerceklesen / sapma_nedeni) birlikte değerlendirilir.
 */
final class AylikFaaliyetEscalation
{
    /**
     * @param  array<string, mixed>  $item  Repeater tek satırı
     */
    public static function itemNeedsUpperManagementAttention(array $item): bool
    {
        if (self::legacyItemIsDelayed($item)) {
            return true;
        }

        if (self::sapmaNedeniFilled($item)) {
            return true;
        }

        if (self::kpiUnderTarget($item)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $faaliyetler
     */
    public static function recordHasEscalation(?array $faaliyetler): bool
    {
        if (! is_array($faaliyetler)) {
            return false;
        }
        foreach ($faaliyetler as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (self::itemNeedsUpperManagementAttention($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function legacyItemIsDelayed(array $item): bool
    {
        if (empty($item['son_tarih'])) {
            return false;
        }
        try {
            $due = Carbon::parse($item['son_tarih']);
        } catch (\Throwable) {
            return false;
        }
        if (! $due->isPast()) {
            return false;
        }

        return ($item['durum'] ?? '') !== 'tamam';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function sapmaNedeniFilled(array $item): bool
    {
        $s = trim((string) ($item['sapma_nedeni'] ?? ''));

        return $s !== '';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function kpiUnderTarget(array $item): bool
    {
        if (! isset($item['hedef'], $item['gerceklesen'])) {
            return false;
        }
        if (! is_numeric($item['hedef']) || ! is_numeric($item['gerceklesen'])) {
            return false;
        }

        return (float) $item['gerceklesen'] < (float) $item['hedef'];
    }

    /**
     * Gecikme raporu / PDF için tek satır özet metni.
     *
     * @param  array<string, mixed>  $item
     */
    public static function describeItemForManagement(array $item): ?string
    {
        if (! self::itemNeedsUpperManagementAttention($item)) {
            return null;
        }

        $parts = [];
        $label = trim((string) ($item['faaliyet_kodu'] ?? ''));
        if ($label === '') {
            $label = trim((string) ($item['konu'] ?? 'Faaliyet'));
        }

        if (self::legacyItemIsDelayed($item)) {
            $gg = trim((string) ($item['gecikme_gerekcesi'] ?? ''));
            $parts[] = $gg !== ''
                ? 'Planlı gecikme: '.$gg
                : 'Planlı iş gecikmesi (eski takip)';
        }
        if (self::kpiUnderTarget($item)) {
            $parts[] = 'Hedef altı gerçekleşme';
        }
        if (self::sapmaNedeniFilled($item)) {
            $parts[] = 'Sapma nedeni: '.mb_strimwidth($item['sapma_nedeni'], 0, 200, '…', 'UTF-8');
        }

        return $label.': '.implode(' | ', $parts);
    }
}
