<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Directorate extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'short_name',
        'code',
        'mudurluk_user_id',
    ];

    public function mudurlukUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mudurluk_user_id');
    }
}
