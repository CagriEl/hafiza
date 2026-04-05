<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AylikFaaliyetResource\Pages;
use App\Models\AylikFaaliyet;
use App\Models\ActivityCatalog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Grid;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Carbon\Carbon;

class AylikFaaliyetResource extends Resource
{
    protected static ?string $model = AylikFaaliyet::class;
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';
    protected static ?string $navigationLabel = 'Haftalık Operasyonel Rapor';
    protected static ?string $navigationGroup = 'Raporlama';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // DÖNEM SEÇİMİ
                Section::make('Rapor Dönemi')
                    ->schema([
                        Grid::make(2)->schema([
                            Forms\Components\Select::make('yil')
                                ->options([2025 => '2025', 2026 => '2026'])
                                ->default(now()->year)->required(),
                            Forms\Components\Select::make('ay')
                                ->options(['01' => 'Ocak', '02' => 'Şubat', '03' => 'Mart', '04' => 'Nisan', '05' => 'Mayıs', '06' => 'Haziran', '07' => 'Temmuz', '08' => 'Ağustos', '09' => 'Eylül', '10' => 'Ekim', '11' => 'Kasım', '12' => 'Aralık'])
                                ->default(now()->format('m'))->required(),
                        ]),
                    ])->compact(),

                // ANA RAPORLAMA ALANI
                Section::make('Faaliyet ve Performans Takip Listesi')
                    ->description('Katalogdan faaliyet seçerek haftalık hedeflerinizi ve gerçekleşen rakamları giriniz.')
                    ->schema([
                        Repeater::make('faaliyetler')
                            ->label('İş Listesi')
                            ->schema([
                                // 1. SATIR: KATALOG VE TEMEL BİLGİLER
                                Grid::make(4)->schema([
                                    Forms\Components\Select::make('activity_catalog_id')
                                        ->label('Faaliyet Tanımı (Katalog)')
                                        ->options(function () {
                                            // Kullanıcının kendi müdürlüğüne ait faaliyetleri getirir
                                            return ActivityCatalog::where('mudurluk', auth()->user()->name)
                                                ->pluck('faaliyet_ailesi', 'id');
                                        })
                                        ->reactive()
                                        ->afterStateUpdated(function (Set $set, $state) {
                                            $catalog = ActivityCatalog::find($state);
                                            if ($catalog) {
                                                $set('olcu_birimi', $catalog->olcu_birimi);
                                                $set('faaliyet_kodu', $catalog->faaliyet_kodu);
                                            }
                                        })
                                        ->required()
                                        ->columnSpan(2),
                                    
                                    Forms\Components\TextInput::make('faaliyet_kodu')
                                        ->label('Kod')
                                        ->readOnly()
                                        ->extraAttributes(['class' => 'bg-gray-50']),

                                    Forms\Components\TextInput::make('olcu_birimi')
                                        ->label('Birim')
                                        ->readOnly()
                                        ->extraAttributes(['class' => 'bg-gray-50']),
                                ]),

                                // 2. SATIR: SAYISAL KPI VERİLERİ
                                Grid::make(4)->schema([
                                    Forms\Components\Select::make('hafta')
                                        ->label('Rapor Haftası')
                                        ->options([1 => '1. Hafta', 2 => '2. Hafta', 3 => '3. Hafta', 4 => '4. Hafta'])
                                        ->required(),

                                    Forms\Components\TextInput::make('hedef')
                                        ->label('Haftalık Hedef')
                                        ->numeric()
                                        ->placeholder('Örn: 450')
                                        ->live(),

                                    Forms\Components\TextInput::make('gerceklesen')
                                        ->label('Gerçekleşen')
                                        ->numeric()
                                        ->placeholder('Örn: 395')
                                        ->live(),

                                    Forms\Components\TextInput::make('bekleyen_is')
                                        ->label('Açık/Bekleyen İş')
                                        ->numeric()
                                        ->placeholder('Örn: 18'),
                                ]),

                                // 3. SATIR: SAPMA VE ANALİZ
                                Grid::make(2)->schema([
                                    Forms\Components\Textarea::make('sapma_nedeni')
                                        ->label('Sapma Nedeni')
                                        ->placeholder('Hedef gerçekleşmediyse nedenini yazınız...')
                                        ->rows(2)
                                        ->visible(fn (Get $get) => filled($get('hedef')) && $get('gerceklesen') < $get('hedef')),

                                    Forms\Components\Textarea::make('risk_engel')
                                        ->label('Risk / Engel')
                                        ->placeholder('İşin önündeki engelleri belirtiniz...')
                                        ->rows(2),
                                ]),

                                // 4. SATIR: KARAR VE ÜST YÖNETİM NOTU
                                Grid::make(1)->schema([
                                    Forms\Components\TextInput::make('karar_ihtiyaci')
                                        ->label('📌 Üst Yönetim Karar İhtiyacı')
                                        ->placeholder('Başkanlık makamından beklenen karar veya destek nedir?'),
                                    
                                    Forms\Components\Textarea::make('vice_mayor_notu')
                                        ->label('Başkan Yardımcısı Değerlendirmesi')
                                        ->placeholder('Başkan yardımcısı buraya görüşünü yazacak...')
                                        ->rows(2)
                                        ->disabled(function () {
                                            $isViceMayor = \App\Models\ViceMayor::where('user_id', auth()->id())->exists();
                                            return auth()->id() !== 1 && !$isViceMayor;
                                        })
                                        ->extraAttributes(['class' => 'bg-green-50 border-l-4 border-green-500']),
                                ]),
                            ])
                            ->itemLabel(fn (array $state): ?string => $state['faaliyet_kodu'] ?? 'Yeni Faaliyet Girişi')
                            ->collapsible()
                            ->defaultItems(1),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('yil')->label('Yıl')->badge(),
                Tables\Columns\TextColumn::make('ay')->label('Ay'),
                Tables\Columns\TextColumn::make('user.name')->label('Müdürlük')->searchable(),
                
                // Performans Özeti Sütunu
                Tables\Columns\TextColumn::make('performans_ozeti')
                    ->label('Haftalık Verimlilik')
                    ->getStateUsing(function ($record) {
                        $isler = is_string($record->faaliyetler) ? json_decode($record->faaliyetler, true) : $record->faaliyetler;
                        if (!is_array($isler)) return '-';
                        
                        $toplamHedef = collect($isler)->sum('hedef');
                        $toplamGerceklesen = collect($isler)->sum('gerceklesen');
                        
                        if ($toplamHedef == 0) return 'Sayısal Veri Yok';
                        $oran = round(($toplamGerceklesen / $toplamHedef) * 100);
                        return "% {$oran} Başarı";
                    })
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        str_contains($state, '100') => 'success',
                        str_contains($state, '80') => 'warning',
                        default => 'danger',
                    }),

                // Karar İhtiyacı İkonu
                Tables\Columns\IconColumn::make('karar_bekleyen')
                    ->label('Karar İhtiyacı')
                    ->boolean()
                    ->getStateUsing(function ($record) {
                        $isler = is_string($record->faaliyetler) ? json_decode($record->faaliyetler, true) : $record->faaliyetler;
                        return collect($isler)->whereNotNull('karar_ihtiyaci')->count() > 0;
                    })
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('danger'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('yil')->options([2025 => '2025', 2026 => '2026']),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Detay / Denetim'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAylikFaaliyets::route('/'),
            'create' => Pages\CreateAylikFaaliyet::route('/create'),
            'edit' => Pages\EditAylikFaaliyet::route('/{record}/edit'),
        ];
    }
}