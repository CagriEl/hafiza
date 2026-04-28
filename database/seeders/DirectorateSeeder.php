<?php

namespace Database\Seeders;

use App\Models\Directorate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DirectorateSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('directorates')) {
            $this->command?->warn('directorates tablosu bulunamadı, tohumlama atlandı.');

            return;
        }

        $hasShortName = Schema::hasColumn('directorates', 'short_name');
        $hasCode = Schema::hasColumn('directorates', 'code');

        $directorates = [
            'Afet İşleri ve Risk Yönetimi Müdürlüğü' => 'AİRYM',
            'Bilgi İşlem Müdürlüğü' => 'BİM',
            'Destek Hizmetleri Müdürlüğü' => 'DHM',
            'Fen İşleri Müdürlüğü' => 'FİM',
            'Gelirler Müdürlüğü' => 'GM',
            'Hukuk İşleri Müdürlüğü' => 'HİM',
            'İklim Değişikliği ve Sıfır Atık Müdürlüğü' => 'İDSAM',
            'İmar ve Şehircilik Müdürlüğü' => 'İŞM',
            'İnsan Kaynakları ve Eğitim Müdürlüğü' => 'İKEM',
            'İtfaiye Müdürlüğü' => 'İM',
            'Kültür, Sanat ve Sosyal İşler Müdürlüğü' => 'KSSİM',
            'Makine İkmal Bakım ve Onarım Müdürlüğü' => 'MİBOM',
            'Mali Hizmetler Müdürlüğü' => 'MHM',
            'Mezarlıklar Müdürlüğü' => 'MM',
            'Özel Kalem Müdürlüğü' => 'ÖKM',
            'Su ve Kanalizasyon Müdürlüğü' => 'SKM',
            'Temizlik İşleri Müdürlüğü' => 'TİM',
            'Ulaşım Hizmetleri Müdürlüğü' => 'UHM',
            'Veteriner İşleri Müdürlüğü' => 'VİM',
            'Yazı İşleri Müdürlüğü' => 'YİM',
            'Zabıta Müdürlüğü' => 'ZM',
        ];

        $created = 0;
        $updated = 0;

        foreach ($directorates as $name => $shortName) {
            $slug = Str::slug($name);
            $payload = [
                'name' => $name,
                'slug' => $slug,
            ];

            if ($hasShortName) {
                $payload['short_name'] = $shortName;
            }

            if ($hasCode) {
                $payload['code'] = Str::upper(Str::ascii($shortName));
            }

            $record = Directorate::query()
                ->where('name', $name)
                ->orWhere('slug', $slug)
                ->first();

            if ($record) {
                $record->fill($payload);
                $record->save();
            } else {
                Directorate::query()->create($payload);
            }

            if ($record) {
                $updated++;
            } else {
                $created++;
            }
        }

        $this->command?->info("DirectorateSeeder tamamlandı: {$created} eklendi, {$updated} güncellendi.");
    }
}
