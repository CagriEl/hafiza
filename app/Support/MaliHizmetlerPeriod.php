<?php

namespace App\Support;

use App\Models\MaliHizmetlerRapor;
use App\Models\User;

final class MaliHizmetlerPeriod
{
    /**
     * @return array{yil: int, ay: string, hafta: int}
     */
    public static function currentWeekAttributes(): array
    {
        $yil = (int) now()->year;
        $ay = now()->format('m');

        return [
            'yil' => $yil,
            'ay' => $ay,
            'hafta' => ReportPeriodWeeks::resolveWeekForReportPeriod($yil, (int) $ay),
        ];
    }

    public static function currentWeekLabel(): string
    {
        $attrs = self::currentWeekAttributes();

        return self::periodLabel($attrs['yil'], $attrs['ay'], $attrs['hafta']);
    }

    public static function periodLabel(int $yil, string|int $ay, int $hafta): string
    {
        $ayPadded = str_pad(trim((string) $ay), 2, '0', STR_PAD_LEFT);
        $weekLabel = ReportPeriodWeeks::weekLabelForRecord($yil, (int) $ayPadded, $hafta);

        return $yil.' / '.ReportPeriodWeeks::turkishMonthName((int) $ayPadded)
            .' · '.($weekLabel ?? ('Hafta '.$hafta));
    }

    public static function resolveRecordForUser(User $user): ?MaliHizmetlerRapor
    {
        $attrs = self::currentWeekAttributes();

        return self::resolveRecordForUserAndPeriod(
            $user,
            $attrs['yil'],
            $attrs['ay'],
            $attrs['hafta'],
        );
    }

    public static function resolveRecordForUserAndPeriod(
        User $user,
        int $yil,
        string|int $ay,
        int $hafta,
    ): ?MaliHizmetlerRapor {
        $ayPadded = str_pad(trim((string) $ay), 2, '0', STR_PAD_LEFT);
        if ($yil <= 0 || $ayPadded === '' || $hafta < 1) {
            return null;
        }

        return MaliHizmetlerRapor::query()
            ->where('user_id', $user->id)
            ->where('yil', $yil)
            ->where('ay', $ayPadded)
            ->where('hafta', $hafta)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{yil: int, ay: string, hafta: int}
     */
    public static function normalizePeriodAttributes(array $state): array
    {
        $yil = (int) ($state['yil'] ?? now()->year);
        $ay = str_pad(trim((string) ($state['ay'] ?? now()->format('m'))), 2, '0', STR_PAD_LEFT);
        $hafta = (int) ($state['hafta'] ?? 1);

        $weekOptions = ReportPeriodWeeks::periodSelectOptions($yil, (int) $ay, 'Haftalık');
        if ($weekOptions !== [] && ! array_key_exists($hafta, $weekOptions)) {
            $hafta = (int) array_key_first($weekOptions);
        }

        return [
            'yil' => $yil,
            'ay' => $ay,
            'hafta' => max(1, $hafta),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function resolveReportingRecordForPeriod(array $state): ?MaliHizmetlerRapor
    {
        $targetUser = MaliHizmetlerAccess::resolveReportingUser(auth()->user());
        if ($targetUser === null) {
            return null;
        }

        $period = self::normalizePeriodAttributes($state);

        return self::resolveRecordForUserAndPeriod(
            $targetUser,
            $period['yil'],
            $period['ay'],
            $period['hafta'],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeOdemeTalepleri(array $data): array
    {
        $items = $data['odeme_talepleri'] ?? null;
        if (! is_array($items)) {
            $data['odeme_talepleri'] = [];

            return $data;
        }

        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $row = MaliHizmetlerOdemeTalep::normalizeRow($item);
            if ($row['aciklama'] === '' && $row['tutar'] <= 0) {
                continue;
            }
            $normalized[] = $row;
        }

        $data['odeme_talepleri'] = $normalized;

        return $data;
    }
}
