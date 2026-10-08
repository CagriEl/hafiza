<?php

namespace App\Support;

/**
 * Kapsam satırı için ölçü birimini kalem metni ve faaliyet ölçü biriminden çıkarır.
 * Örn. "müdürlük / evrak / dosya / talep" → kalem başına uygun parça.
 */
final class OlcuBirimiForKapsam
{
    public static function resolve(string $kalem, string $olcuBirimi, int $kalemIndex = 0): string
    {
        $kalem = trim($kalem);
        $olcuBirimi = trim($olcuBirimi);

        if ($olcuBirimi === '') {
            return self::inferFromKalem($kalem);
        }

        $parts = self::splitParts($olcuBirimi);
        if ($parts === []) {
            return $olcuBirimi;
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        $matched = self::matchPartInKalem($kalem, $parts);
        if ($matched !== null) {
            return $matched;
        }

        if ($kalemIndex >= 0 && $kalemIndex < count($parts)) {
            return $parts[$kalemIndex];
        }

        return $parts[min($kalemIndex, count($parts) - 1)];
    }

    /**
     * @return list<string>
     */
    public static function splitParts(string $olcuBirimi): array
    {
        $chunks = preg_split('#\s*/\s*#u', trim($olcuBirimi)) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $part): string => trim($part), $chunks),
            static fn (string $part): bool => $part !== ''
        ));
    }

    /**
     * @param  list<string>  $parts
     */
    private static function matchPartInKalem(string $kalem, array $parts): ?string
    {
        $kalemLower = mb_strtolower($kalem);

        $keywords = [
            'müdürlük' => ['müdürlük', 'mudurluk'],
            'evrak' => ['evrak'],
            'dosya' => ['dosya', 'klasör', 'klasor'],
            'talep' => ['talep', 'erişim', 'erisim'],
            'işlem' => ['işlem', 'islem', 'imha', 'nikâh', 'nikah'],
        ];

        foreach ($parts as $part) {
            $partLower = mb_strtolower(trim($part));
            if ($partLower === '') {
                continue;
            }
            if (str_contains($kalemLower, $partLower)) {
                return trim($part);
            }
            foreach ($keywords as $canonical => $aliases) {
                if (! in_array($partLower, $aliases, true) && $partLower !== mb_strtolower($canonical)) {
                    continue;
                }
                foreach ($aliases as $alias) {
                    if (str_contains($kalemLower, $alias)) {
                        return trim($part);
                    }
                }
            }
        }

        return null;
    }

    private static function inferFromKalem(string $kalem): string
    {
        $kalemLower = mb_strtolower($kalem);
        if (preg_match('/(\d+)\s*$/u', $kalem, $m)) {
            return 'adet';
        }
        if (str_contains($kalemLower, 'sayısı') || str_contains($kalemLower, 'sayisi')) {
            return 'adet';
        }

        return 'adet';
    }
}
