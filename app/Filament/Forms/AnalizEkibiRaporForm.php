<?php

namespace App\Filament\Forms;

use App\Support\AnalizEkibiRaporVerileri;
use Filament\Forms;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Get;
use Filament\Forms\Set;

final class AnalizEkibiRaporForm
{
    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    public static function schema(): array
    {
        return [
            Forms\Components\Hidden::make('rapor_verileri.ozet.yapilan_is')->dehydrated(),
            Forms\Components\Hidden::make('rapor_verileri.ozet.acikta_bekleyen')->dehydrated(),
            Forms\Components\Hidden::make('rapor_verileri.ozet.tamamlanma_orani')->dehydrated(),
            Forms\Components\Hidden::make('rapor_verileri.ozet.revize_karar')->dehydrated(),
            Forms\Components\Hidden::make('rapor_verileri.ozet.gecen_ay_fark')->dehydrated(),
            Forms\Components\Hidden::make('rapor_verileri.ozet.kritik_kalem_notu')->dehydrated(),

            Section::make('Kalem Kalem Analiz')
                ->description('Müdürlüğün dönem raporundaki kapsam kalemleri otomatik gelir.')
                ->schema([
                    Repeater::make('rapor_verileri.kalem_analizi')
                        ->label('')
                        ->schema([
                            Forms\Components\TextInput::make('kalem')->label('Kalem')->required(),
                            Forms\Components\TextInput::make('gerceklesen')->label('Gerçekleşen')->numeric()->minValue(0),
                            Forms\Components\TextInput::make('acikta')->label('Açıkta')->numeric()->minValue(0),
                            Forms\Components\Select::make('durum')
                                ->label('Durum')
                                ->options([
                                    'Tamamlandı' => 'Tamamlandı',
                                    'Kısmi' => 'Kısmi',
                                    'Riskli' => 'Riskli',
                                    'Veri Eksik' => 'Veri Eksik',
                                ]),
                            Forms\Components\Textarea::make('sapma_not')->label('Sapma / Not')->rows(2)->columnSpanFull(),
                            Forms\Components\TextInput::make('son_tarih')->label('Son Yapılma Tarihi'),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Kalem ekle')
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => filled($state['kalem'] ?? null) ? (string) $state['kalem'] : 'Kalem'),
                ]),

            Section::make('Öncelikli Aksiyon Listesi')
                ->description('Açıkta kalan kalemlerden otomatik önerilir; düzenleyebilirsiniz.')
                ->schema([
                    Repeater::make('rapor_verileri.aksiyonlar')
                        ->label('')
                        ->schema([
                            Forms\Components\TextInput::make('oncelik')->label('Öncelik'),
                            Forms\Components\Textarea::make('aksiyon')->label('Aksiyon')->rows(2)->required()->columnSpanFull(),
                            Forms\Components\TextInput::make('kalem')->label('İlgili Kalem'),
                            Forms\Components\TextInput::make('sorumlu')->label('Sorumlu'),
                            Forms\Components\TextInput::make('hedef_tarih')->label('Hedef Tarih (GG.AA.YYYY)'),
                            Forms\Components\TextInput::make('durum')->label('Durum'),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Aksiyon ekle')
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => filled($state['aksiyon'] ?? null) ? mb_substr((string) $state['aksiyon'], 0, 40) : 'Aksiyon'),
                ]),

            Section::make('Olgunluk Göstergeleri (%)')
                ->description('Kalem verilerinden otomatik hesaplanır.')
                ->schema([
                    Forms\Components\Grid::make(2)->schema(self::olgunlukField('veri_kalitesi', 'Veri Kalitesi')),
                    Forms\Components\Grid::make(2)->schema(self::olgunlukField('zamaninda_kapanis', 'Zamanında Kapanış')),
                    Forms\Components\Grid::make(2)->schema(self::olgunlukField('risk_yonetimi', 'Risk Yönetimi')),
                    Forms\Components\Grid::make(2)->schema(self::olgunlukField('aksiyon_kapanis', 'Aksiyon Kapanış Disiplini')),
                ]),

            Section::make('Risk Isı Haritası')
                ->description('Kalem durumlarından otomatik oluşturulur.')
                ->schema([
                    Repeater::make('rapor_verileri.risk_haritasi')
                        ->label('')
                        ->schema([
                            Forms\Components\TextInput::make('kalem')->label('Kalem')->disabled()->dehydrated(),
                            Forms\Components\TextInput::make('seviye')->label('Seviye')->disabled()->dehydrated(),
                            Forms\Components\Textarea::make('aciklama')->label('Açıklama')->rows(2)->disabled()->dehydrated()->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => filled($state['kalem'] ?? null)
                            ? (string) $state['kalem'].' — '.(string) ($state['seviye'] ?? '')
                            : 'Risk'),
                ]),
        ];
    }

    public static function applyPrefill(Set $set, Get $get): void
    {
        if ((int) ($get('directorate_user_id') ?? 0) <= 0) {
            return;
        }

        $set('rapor_verileri', AnalizEkibiRaporVerileri::buildPrefillFromForm($get));
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    private static function olgunlukField(string $key, string $label): array
    {
        return [
            Forms\Components\TextInput::make('rapor_verileri.olgunluk.'.$key.'.deger')
                ->label($label.' (%)')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->disabled()
                ->dehydrated(),
            Forms\Components\TextInput::make('rapor_verileri.olgunluk.'.$key.'.not')
                ->label($label.' — Not')
                ->disabled()
                ->dehydrated(),
        ];
    }
}
