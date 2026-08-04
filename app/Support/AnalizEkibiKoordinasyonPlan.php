<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Koordinasyon takip ekranı: canlı portföy + operasyon rehberi.
 */
final class AnalizEkibiKoordinasyonPlan
{
    /**
     * @return list<array{id: int, name: string, mudurluk_sayisi: int, mudurlukler: list<array{id: int, name: string}>}>
     */
    public static function portfolio(): array
    {
        /** @var Collection<int, User> $users */
        $users = User::query()
            ->where('role', User::ROLE_ANALIZ_EKIBI)
            ->orderBy('name')
            ->get(['id', 'name']);

        $out = [];
        foreach ($users as $user) {
            $dirs = $user->assignedDirectorates()
                ->orderBy('users.name')
                ->get(['users.id', 'users.name']);

            $out[] = [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'mudurluk_sayisi' => $dirs->count(),
                'mudurlukler' => $dirs->map(fn (User $d): array => [
                    'id' => (int) $d->id,
                    'name' => (string) $d->name,
                ])->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function playbook(): array
    {
        return [
            'roller' => [
                'Koordinasyon müdürü: haftalık ritim, karar, kırmızı maddelerin sahipsiz kalmaması.',
                'Analiz ekibi: portföy sahipliği, ekran kontrolü ve gerektiğinde saha teyidi.',
            ],
            'ritim' => [
                ['gun' => 'Pazartesi', 'is' => '15 dk sync — kırmızı liste + engeller'],
                ['gun' => 'Salı–Perşembe', 'is' => 'Portföy kontrolü; şüpheli kalemlerde saha / kaynak teyidi'],
                ['gun' => 'Cuma', 'is' => 'Kapanış — teyit / şüphe / düzeltme sayıları + açık kırmızılar'],
            ],
            'renkler' => [
                ['kod' => 'teyit', 'etiket' => 'Yeşil', 'anlam' => 'Teyitli — sistem + kaynak uyumlu'],
                ['kod' => 'suphe', 'etiket' => 'Sarı', 'anlam' => 'Şüphe — spot check veya belge bekleniyor'],
                ['kod' => 'duzeltme', 'etiket' => 'Kırmızı', 'anlam' => 'Düzeltme talebi — müdürlüğe dönüş, kapanış zorunlu'],
            ],
            'saha_adimlari' => [
                'Sistem: Hafıza’dan kalem, hafta/ay, birim ve rakamları al.',
                'Kaynak: İş emri / puantaj / foto / tablet kaydı iste.',
                'Spot check: 3–5 örnek lokasyon veya en büyük kalemler.',
                'Birim: m² / adet / km tutarlı mı kontrol et.',
                'Sonuç: Yeşil / Sarı / Kırmızı olarak bu ekrana kaydet.',
            ],
            'saha_tetikleyicileri' => [
                'Açıkta kalan yüksek',
                'Gerçekleşen ≈ öngörülen ama sahada şüpheli',
                'Ani sıçrama veya birim tutarsızlığı',
                'Bilinçli 0 ile hiç girilmemiş ayrımı net değil',
            ],
            'toplanti_gundemi' => [
                'Portföy — itiraz varsa burada kapat (5 dk)',
                'Kırmızı / sarı liste (10 dk)',
                'Saha senaryosu örneği (15 dk)',
                'Haftalık ritim ve sahiplik (10 dk)',
                'Kararlar ve tarihler (5 dk)',
            ],
            'kpi' => [
                'Haftalık teyit edilen madde sayısı',
                'Açık sarı / kırmızı sayısı (hedef: düşürmek)',
                'Kırmızı → kapanış süresi',
            ],
        ];
    }
}
