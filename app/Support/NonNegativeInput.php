<?php

namespace App\Support;

/**
 * Form ve JSON’da sayı alanlarında yalnızca ≥ 0 değerlere izin verir; «-», «+.» vb. geçersiz girişleri temizler.
 */
final class NonNegativeInput
{
    /**
     * Canlı form: kullanıcı yazarken geçersiz veya negatif değeri düzeltir (Livewire state).
     */
    public static function coerceLiveState(mixed $state): mixed
    {
        if ($state === null || $state === false) {
            return $state;
        }
        if ($state === '') {
            return null;
        }
        if (is_string($state)) {
            $t = trim(str_replace("\u{00A0}", '', $state));
            if ($t === '' || self::isIncompleteSignedNumber($t)) {
                return null;
            }
            $state = $t;
        }
        if (! is_numeric($state)) {
            return $state;
        }
        $f = (float) $state;

        return $f < 0 ? 0 : $state;
    }

    /**
     * Dehydrate / kayıt: skaları ≥ 0 sayı veya null yapar (yalnız «-» vb. → null).
     */
    public static function normalizeScalar(mixed $v): mixed
    {
        if ($v === null || $v === '' || $v === false) {
            return null;
        }
        if (is_string($v)) {
            $t = trim(str_replace("\u{00A0}", '', $v));
            if ($t === '' || self::isIncompleteSignedNumber($t)) {
                return null;
            }
            $v = $t;
        }
        if (! is_numeric($v)) {
            return null;
        }
        $f = (float) $v;
        if ($f < 0) {
            return 0;
        }
        if ($f === (float) (int) $f) {
            return (int) $f;
        }

        return $f;
    }

    private static function isIncompleteSignedNumber(string $t): bool
    {
        return $t === '-' || $t === '+' || $t === '.' || $t === '-.' || $t === '+.' || $t === '-+' || $t === '+-';
    }
}
