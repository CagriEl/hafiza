<?php

namespace App\Support;

final class KapsamIslemTuru
{
    public const SUREC = 'surec_gerektir';

    public const ANLIK = 'anlik';

    public const GUNLUK = 'gunluk';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::SUREC => 'Süreç gerektirir',
            self::ANLIK => 'Anlık',
            self::GUNLUK => 'Günlük',
        ];
    }

    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $key = trim($value);

        return array_key_exists($key, self::options()) ? $key : null;
    }

    public static function requiresProcessDateRange(?string $tur): bool
    {
        return $tur === self::SUREC;
    }

    public static function requiresDailyDate(?string $tur): bool
    {
        return $tur === self::GUNLUK;
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

        return self::options()[$tur] ?? null;
    }
}
