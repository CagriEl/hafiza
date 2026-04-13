<?php

namespace App\Support;

use App\Filament\Resources\ActivityReportResource;
use App\Filament\Resources\SwotAnalizResource;

/**
 * Panele giriş yapmış kullanıcılar için kısa rehber (faaliyet + SWOT).
 */
final class UsageGuideData
{
    /**
     * @return array{intro: array<string, string>, sections: list<array<string, mixed>>}
     */
    public static function forFilamentPanel(): array
    {
        return [
            'intro' => [
                'title' => 'Bu rehberde neler var?',
                'text' => 'İki konu: aylık faaliyet raporu girişi ve SWOT analizi. Menüde hangi adlarla göründükleri kurulumunuza göre aşağıda yer alır.',
            ],
            'sections' => self::sections(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function sections(): array
    {
        $raporlar = ActivityReportResource::getNavigationLabel();
        $swot = SwotAnalizResource::getNavigationLabel();
        $swotGroup = SwotAnalizResource::getNavigationGroup() ?? 'Menü';

        return [
            array_merge(self::accentUi('amber'), [
                'key' => 'faaliyet',
                'accent' => 'amber',
                'title' => 'Faaliyet raporu',
                'badge' => 'Raporlar',
                'lead' => 'Her ay müdürlüğünüzün faaliyet ve performans bilgilerini bu ekrandan girersiniz.',
                'menu' => 'Sol menüden «'.$raporlar.'» veya «Aylık Rapor» üzerine tıklayın. İkisi aynı tür kayıt içindir; kurumunuz hangi menüyü kullanıyorsa onu seçin.',
                'steps' => [
                    'Yeni ay için «Yeni faaliyet raporu oluştur» ile başlayın; o ayın kaydı varsa listeden «Düzenle» deyin.',
                    'Yıl ve ayı seçin.',
                    '«İş listesi»nde satır ekleyin. «Faaliyet tanımı (katalog)» listesinden size tanımlı işi seçin; kod ve birim çoğunlukla otomatik gelir.',
                    'Çoğu iş için «Operasyonel» yeterlidir. Başka müdürlüklerle ortak işte «Koordinasyon» seçin; işbirliği müdürlükleri ve ihtiyaç metnini doldurun.',
                    'Haftalık hedef ve gerçekleşen değerlerinizi girin. Hedefin altındaysanız «Sapma nedeni» istenebilir.',
                    'Gerekirse risk / üst yönetim kararı ihtiyacı alanlarını kullanın ve kaydedin.',
                ],
                'notes' => [
                    '«Tüm Raporlarım»: o döneme ait tüm satırlarınız. «Talep Ettiklerim»: sizin koordinasyon başlattığınız kayıtlar. «Gelen koordinasyonlar»: başka birimin sizi ortak seçtiği kayıtlar.',
                    'Gelen koordinasyonda «Hangi ihtiyaç» gibi bazı alanlar talep eden müdürlükte yazıldığı için sizde salt okunur olabilir.',
                ],
            ]),
            array_merge(self::accentUi('violet'), [
                'key' => 'swot',
                'accent' => 'violet',
                'title' => 'SWOT analizi',
                'badge' => 'Kurumsal hafıza',
                'lead' => 'Stratejik durumunuzu dört başlıkta metin olarak kaydedersiniz.',
                'menu' => 'Sol menüde «'.$swotGroup.'» grubundan «'.$swot.'» öğesini açın.',
                'steps' => [
                    '«Yeni» veya oluştur ile kayıt başlatın.',
                    'Analiz başlığı ve yılını yazın.',
                    'Güçlü yönler, zayıf yönler, fırsatlar ve tehditler kutularını doldurun; liste veya paragraf kullanabilirsiniz.',
                    'Kaydedin; sonra listeden açıp güncelleyebilirsiniz.',
                ],
                'notes' => [
                    'Kayıtlar hesabınıza bağlıdır; başka rollere özel ekranlar kurum ayarına göre farklı olabilir.',
                ],
            ]),
        ];
    }

    /**
     * @return array{ui: array{border_left: string, icon_bg: string, icon_soft: string, step_badge: string}}
     */
    private static function accentUi(string $accent): array
    {
        $isAmber = $accent === 'amber';

        return [
            'ui' => [
                'border_left' => $isAmber ? 'border-l-amber-500' : 'border-l-violet-500',
                'icon_bg' => $isAmber ? 'bg-amber-500 dark:bg-amber-600' : 'bg-violet-600 dark:bg-violet-500',
                'icon_soft' => $isAmber ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200' : 'bg-violet-100 text-violet-800 dark:bg-violet-950/40 dark:text-violet-200',
                'step_badge' => $isAmber ? 'bg-amber-500 text-white dark:bg-amber-600' : 'bg-violet-600 text-white dark:bg-violet-500',
            ],
        ];
    }
}
