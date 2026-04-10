<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ControlTeamAuditNote extends Model
{
    protected $fillable = [
        'user_id',
        'directorate_user_id',
        'activity_catalog_id',
        'note',
        'audit_date',
    ];

    protected $casts = [
        'audit_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function directorate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'directorate_user_id');
    }

    public function activityCatalog(): BelongsTo
    {
        return $this->belongsTo(ActivityCatalog::class, 'activity_catalog_id');
    }
}
