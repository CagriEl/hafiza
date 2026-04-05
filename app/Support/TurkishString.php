<?php

namespace App\Support;

/**
 * Müdürlük ve metin eşlemesi: trim, Unicode NFKC (varsa), görünmez boşluk temizliği,
 * ardından tr-TR ile uyumlu küçük harf (İ→i, I→ı).
 */
final class TurkishString
{
    /**
     * Gizli boşluk, NBSP ve fazla boşlukları giderip tr-TR küçük harfe indirger.
     * Karşılaştırmalar için tek anahtar üretir.
     */
    public static function normalizeForFuzzyMatch(string $value): string
    {
        $value = (string) $value;
        $value = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00A0}]/u', '', $value);
        $value = str_replace("\xc2\xa0", ' ', $value);

        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if (is_string($n)) {
                $value = $n;
            }
        }

        $value = preg_replace('/\s+/u', ' ', $value);

        return self::trimToLowerTr($value);
    }

    /**
     * trim + tr-TR küçük harf (İ→i, I→ı, ardından mb_strtolower UTF-8).
     */
    public static function trimToLowerTr(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = str_replace(['İ', 'I'], ['i', 'ı'], $value);

        return mb_strtolower($value, 'UTF-8');
    }

    public static function same(?string $a, ?string $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }

        return self::normalizeForFuzzyMatch($a) === self::normalizeForFuzzyMatch($b);
    }

    public static function sameFuzzy(?string $a, ?string $b): bool
    {
        return self::same($a, $b);
    }
}
