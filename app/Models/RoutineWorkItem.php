<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutineWorkItem extends Model
{
    public const STATUS_IN_PROGRESS = 'devam_ediyor';

    public const STATUS_DONE = 'bitti';

    public const STATUS_PLANNED = 'baslanacak';

    protected $fillable = [
        'user_id',
        'work_date',
        'work_item',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            if (auth()->check() && ! $item->user_id) {
                $item->user_id = (int) auth()->id();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
