<?php

namespace App\Support;

final class KapsamIslemTuru
{
    public const SUREC = 'surec_gerektir';

    public const ANLIK = 'anlik';

    public const GUNLUK = 'gunluk';

    public const HAFTALIK = 'haftalik';

    public const IMZADA = 'imzada';

    /**
     * @return array<string, string>
     */
    public static function options(bool $includeImzada = false, bool $includeHaftalik = false): array
    {
        $options = [
            self::SUREC => 'Süreç gerektirir',
            self::ANLIK => 'Anlık',
        ];

        if ($includeHaftalik) {
            $options[self::HAFTALIK] = 'Haftalık';
        } else {
            $options[self::GUNLUK] = 'Günlük';
        }

        if ($includeImzada) {
            $options[self::IMZADA] = 'İmzada';
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function allKnownOptions(): array
    {
        return [
            self::SUREC => 'Süreç gerektirir',
            self::ANLIK => 'Anlık',
            self::GUNLUK => 'Günlük',
            self::HAFTALIK => 'Haftalık',
            self::IMZADA => 'İmzada',
        ];
    }

    public static function normalize(mixed $value, bool $allowImzada = false, bool $allowHaftalik = false): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $key = trim($value);
        if ($key === self::IMZADA) {
            return $allowImzada ? self::IMZADA : null;
        }
        if ($key === self::HAFTALIK) {
            return $allowHaftalik ? self::HAFTALIK : null;
        }

        return array_key_exists($key, self::options(false, false)) ? $key : null;
    }

    /**
     * Kayıtlı değeri korumak için (birleştirme / PDF); UI kısıtlaması uygulanmaz.
     */
    public static function normalizeStored(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $key = trim($value);

        return array_key_exists($key, self::allKnownOptions()) ? $key : null;
    }

    public static function requiresProcessDateRange(?string $tur): bool
    {
        return $tur === self::SUREC || $tur === self::HAFTALIK;
    }

    public static function requiresDailyDate(?string $tur): bool
    {
        return $tur === self::GUNLUK;
    }

    public static function isImzada(?string $tur): bool
    {
        return $tur === self::IMZADA;
    }

    public static function isHaftalik(?string $tur): bool
    {
        return $tur === self::HAFTALIK;
    }

    public static function requiresDateRange(?string $tur): bool
    {
        return self::requiresProcessDateRange($tur) || self::requiresDailyDate($tur);
    }

    public static function label(?string $tur): ?string
    {
        if ($tur === null) {
            return null;
        }

        return self::allKnownOptions()[$tur] ?? null;
    }
}
