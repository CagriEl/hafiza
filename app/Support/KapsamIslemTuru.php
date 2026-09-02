<?php

namespace App\Support;

final class KapsamIslemTuru
{
    public const SUREC = 'surec_gerektir';

    public const ANLIK = 'anlik';

    public const GUNLUK = 'gunluk';

    public const IMZADA = 'imzada';

    /**
     * @return array<string, string>
     */
    public static function options(bool $includeImzada = false): array
    {
        $options = [
            self::SUREC => 'Süreç gerektirir',
            self::ANLIK => 'Anlık',
            self::GUNLUK => 'Günlük',
        ];

        if ($includeImzada) {
            $options[self::IMZADA] = 'İmzada';
        }

        return $options;
    }

    public static function normalize(mixed $value, bool $allowImzada = false): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $key = trim($value);

        return array_key_exists($key, self::options($allowImzada)) ? $key : null;
    }

    public static function requiresProcessDateRange(?string $tur): bool
    {
        return $tur === self::SUREC;
    }

    public static function requiresDailyDate(?string $tur): bool
    {
        return $tur === self::GUNLUK;
    }

    public static function isImzada(?string $tur): bool
    {
        return $tur === self::IMZADA;
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

        return self::options(true)[$tur] ?? null;
    }
}
