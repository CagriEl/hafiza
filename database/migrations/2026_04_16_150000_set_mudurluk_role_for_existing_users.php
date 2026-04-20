<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Başkan yardımcısı olarak atanmış user_id'leri çek
        $viceMayorUserIds = DB::table('vice_mayors')->pluck('user_id')->toArray();

        // Admin (id=1), Denetim Ekibi ve ViceMayor kullanıcıları hariç,
        // role alanı boş olan tüm kullanıcılara 'Müdürlük' rolü ata
        // MySQL'de NULL != 'x' NULL döndürür (falsy), bu yüzden NULL ve boş string ayrı ayrı ele alınır.
        $base = DB::table('users')
            ->where('id', '!=', 1)
            ->when(count($viceMayorUserIds) > 0, fn ($q) => $q->whereNotIn('id', $viceMayorUserIds));

        (clone $base)->whereNull('role')->update(['role' => 'Müdürlük']);
        (clone $base)->where('role', '')->update(['role' => 'Müdürlük']);
    }

    public function down(): void
    {
        // Geri alma: bu migration tarafından 'Müdürlük' olarak set edilenleri tekrar boşalt.
        // (Önceden el ile atananlar etkilenmez çünkü up() sadece boş olanları güncelledi)
        DB::table('users')
            ->where('id', '!=', 1)
            ->where('role', 'Müdürlük')
            ->update(['role' => '']);
    }
};
