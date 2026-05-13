<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class RoutineWorkWindow extends Model
{
    private const OPEN_ANNOUNCEMENT_TITLE = 'Rutin İşler Modülü Veri Girişi Açıldı';

    protected $fillable = [
        'start_date',
        'end_date',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }

    public static function isEntryOpenForDate(?string $date = null): bool
    {
        $window = static::current();
        if (! $window || ! $window->is_active || ! $window->start_date || ! $window->end_date) {
            return false;
        }

        $targetDate = $date ? Carbon::parse($date)->startOfDay() : now()->startOfDay();
        $startDate = $window->start_date->copy()->startOfDay();
        $endDate = $window->end_date->copy()->endOfDay();

        return $targetDate->betweenIncluded($startDate, $endDate);
    }

    protected static function booted(): void
    {
        static::saved(function (self $window): void {
            $becameInactive = $window->wasChanged('is_active') && ! $window->is_active;
            if ($becameInactive) {
                Announcement::query()
                    ->where('title', self::OPEN_ANNOUNCEMENT_TITLE)
                    ->where('is_active', true)
                    ->update([
                        'is_active' => false,
                        'expires_at' => now(),
                    ]);

                return;
            }

            $createdAsActive = $window->wasRecentlyCreated && $window->is_active;
            $becameActive = $window->wasChanged('is_active') && $window->is_active;
            $activeRangeUpdated = $window->is_active && ($window->wasChanged('start_date') || $window->wasChanged('end_date'));

            if (! $createdAsActive && ! $becameActive && ! $activeRangeUpdated) {
                return;
            }

            Announcement::query()
                ->where('title', self::OPEN_ANNOUNCEMENT_TITLE)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'expires_at' => now(),
                ]);

            Announcement::query()->create([
                'title' => self::OPEN_ANNOUNCEMENT_TITLE,
                'content' => sprintf(
                    'Rutin İşler modülü %s - %s tarihleri arasında veri girişine açılmıştır.',
                    optional($window->start_date)->format('d.m.Y'),
                    optional($window->end_date)->format('d.m.Y')
                ),
                'type' => Announcement::TYPE_INFO,
                'is_active' => true,
                'is_popup' => true,
                'published_at' => now(),
                'expires_at' => optional($window->end_date)?->copy()->endOfDay(),
            ]);
        });
    }
}
