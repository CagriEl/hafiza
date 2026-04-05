<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'sorumlu_ad_soyad', // Eklendi
        'sorumlu_unvan',    // Eklendi
        'sorumlu_dahili',
        'vice_mayor_id',
        'vekalet_baslangic',
        'vekalet_bitis',
        'vekalet_tam_yetki',
        'vekalet_mudurluk_user_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'vekalet_baslangic' => 'date',
            'vekalet_bitis' => 'date',
            'vekalet_tam_yetki' => 'boolean',
        ];
    }

    public function viceMayor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ViceMayor::class);
    }

    public function vekaletMudurlukUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'vekalet_mudurluk_user_id');
    }

    /**
     * Sistem yöneticisi (id=1): tüm müdürlük verilerine erişim.
     */
    public function isReportingSuperAdmin(): bool
    {
        return (int) $this->id === 1;
    }

    /**
     * Raporlarda görülebilecek müdürlük kullanıcı id listesi.
     * null = kısıtlama yok (yalnızca süper admin).
     *
     * @return list<int>|null
     */
    public function reportAudienceUserIds(): ?array
    {
        if ($this->isReportingSuperAdmin()) {
            return null;
        }

        $viceMayorKayit = ViceMayor::query()->where('user_id', $this->id)->first();
        if ($viceMayorKayit) {
            return self::query()
                ->where('vice_mayor_id', $viceMayorKayit->id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        $ids = [(int) $this->id];
        if ($this->hasActiveVekaletFullAuthority()) {
            $mudurlukId = (int) $this->vekalet_mudurluk_user_id;
            if ($mudurlukId > 0) {
                $ids[] = $mudurlukId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 7+ gün vekalette tam yetki: süre kuralı sağlanmıyorsa ek rapor erişimi verilmez (veri silinmez).
     */
    public function hasActiveVekaletFullAuthority(): bool
    {
        if (! $this->vekalet_tam_yetki) {
            return false;
        }
        if (! $this->vekalet_baslangic || ! $this->vekalet_bitis || ! $this->vekalet_mudurluk_user_id) {
            return false;
        }

        $days = $this->vekaletInclusiveCalendarDays();
        if ($days === null || $days < 7) {
            return false;
        }

        $start = \Carbon\Carbon::parse($this->vekalet_baslangic)->startOfDay();
        $end = \Carbon\Carbon::parse($this->vekalet_bitis)->endOfDay();

        return now()->between($start, $end);
    }

    public function vekaletInclusiveCalendarDays(): ?int
    {
        if (! $this->vekalet_baslangic || ! $this->vekalet_bitis) {
            return null;
        }
        $a = \Carbon\Carbon::parse($this->vekalet_baslangic)->startOfDay();
        $b = \Carbon\Carbon::parse($this->vekalet_bitis)->startOfDay();

        return (int) $a->diffInDays($b) + 1;
    }

    public function canViewReportDataForOwnerId(int $ownerUserId): bool
    {
        $audience = $this->reportAudienceUserIds();
        if ($audience === null) {
            return true;
        }

        return in_array($ownerUserId, $audience, true);
    }

    /**
     * Başkan yardımcılığı hesabı (ViceMayor kaydı).
     */
    public function isViceMayorAccount(): bool
    {
        return ViceMayor::query()->where('user_id', $this->id)->exists();
    }

    /**
     * Rapor oluşturan müdürlük hesabı: süper admin ve başkan yardımcısı değil.
     */
    public function isMudurlukReportingAccount(): bool
    {
        if ($this->isReportingSuperAdmin()) {
            return false;
        }

        return ! $this->isViceMayorAccount();
    }
}
