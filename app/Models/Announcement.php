<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Announcement extends Model
{
    public const TYPE_INFO = 'bilgi';

    public const TYPE_WARNING = 'uyari';

    public const TYPE_CRITICAL = 'kritik';

    protected $fillable = [
        'title',
        'content',
        'type',
        'is_active',
        'is_popup',
        'published_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_popup' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Announcement>  $query
     * @return Builder<Announcement>
     */
    public function scopeActive(Builder $query): Builder
    {
        if (! $query->getModel() || $query->getModel()->getTable() === '') {
            return $query;
        }

        $isActiveColumn = $query->qualifyColumn('is_active');
        $publishedAtColumn = $query->qualifyColumn('published_at');
        $query = $query
            ->where($isActiveColumn, true)
            ->where(function (Builder $inner) use ($publishedAtColumn): void {
                $inner
                    ->whereNull($publishedAtColumn)
                    ->orWhere($publishedAtColumn, '<=', now());
            });

        if (Schema::hasColumn($query->getModel()->getTable(), 'expires_at')) {
            $expiresAtColumn = $query->qualifyColumn('expires_at');
            $query->where(function (Builder $inner) use ($expiresAtColumn): void {
                $inner
                    ->whereNull($expiresAtColumn)
                    ->orWhere($expiresAtColumn, '>', now());
            });
        }

        return $query;
    }

    /**
     * @param  Builder<Announcement>  $query
     * @return Builder<Announcement>
     */
    public function scopePopup(Builder $query): Builder
    {
        if (! $query->getModel() || $query->getModel()->getTable() === '') {
            return $query;
        }

        return $query->where($query->qualifyColumn('is_popup'), true);
    }

    /**
     * @return Builder<Announcement>
     */
    public static function queryActiveAnnouncements(): Builder
    {
        $query = static::query()->active();

        return $query
            ->orderByDesc($query->qualifyColumn('published_at'))
            ->orderByDesc($query->qualifyColumn('id'));
    }

    public static function latestActivePopup(): ?self
    {
        return static::queryActiveAnnouncements()
            ->popup()
            ->first();
    }
}
