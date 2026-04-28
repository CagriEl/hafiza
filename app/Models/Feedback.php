<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Feedback extends Model
{
    protected $table = 'feedbacks';

    public const CATEGORY_BUG = 'Hata Bildirimi';

    public const CATEGORY_SUGGESTION = 'Öneri';

    public const CATEGORY_REQUEST = 'Talep';

    public const CATEGORY_OTHER = 'Diğer';

    public const STATUS_NEW = 'Yeni';

    public const STATUS_REVIEWING = 'İnceleniyor';

    public const STATUS_RESOLVED = 'Çözüldü';

    public const STATUS_REJECTED = 'Reddedildi';

    protected $fillable = [
        'user_id',
        'directorate_id',
        'subject',
        'category',
        'message',
        'status',
        'admin_note',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function directorate(): BelongsTo
    {
        return $this->belongsTo(Directorate::class);
    }
}
