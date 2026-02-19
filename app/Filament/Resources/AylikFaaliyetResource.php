<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AylikFaaliyetResource\Pages;
use App\Models\AylikFaaliyet;
use App\Models\PersonelDurum;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Carbon\Carbon;

class AylikFaaliyetResource extends Resource
{
    protected static ?string $model = AylikFaaliyet::class;
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationLabel = 'Aylık Plan ve Faaliyet';
    protected static ?string $navigationGroup = 'Raporlama';

    public static function form(Form $form): Form
    {
        $personelDurum = PersonelDurum::where('user_id', auth()->id())->first();

        return $form
            ->schema([
                // --- 1. BÖLÜM: DÖNEM BİLGİLERİ ---
                Section::make('Dönem Bilgisi')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('yil')
                                ->label('Yıl')
                                ->options([2024 => '2024', 2025 => '2025', 2026 => '2026', 2027 => '2027'])
                                ->default(now()->year)
                                ->required(),
                            Select::make('ay')
                                ->label('Ay')
                                ->options(['01' => 'Ocak', '02' => 'Şubat', '03' => 'Mart', '04' => 'Nisan', '05' => 'Mayıs', '06' => 'Haziran', '07' => 'Temmuz', '08' => 'Ağustos', '09' => 'Eylül', '10' => 'Ekim', '11' => 'Kasım', '12' => 'Aralık'])
                                ->default(now()->format('m'))
                                ->required(),
                        ]),
                    ]),

                // --- 2. BÖLÜM: PERSONEL DURUMU ---
                Section::make('Mevcut Personel Sayıları')
                    ->schema([
                        Grid::make(5)->schema([
                            TextInput::make('memur')->label('Memur')->numeric()->default($personelDurum->memur ?? 0)->readOnly(),
                            TextInput::make('sozlesmeli_memur')->label('Sözleşmeli P.')->numeric()->default($personelDurum->sozlesmeli_memur ?? 0)->readOnly(),
                            TextInput::make('kadrolu_isci')->label('Kadrolu İşçi')->numeric()->default($personelDurum->kadrolu_isci ?? 0)->readOnly(),
                            TextInput::make('sirket_personeli')->label('Şirket Pers.')->numeric()->default($personelDurum->sirket_personeli ?? 0)->readOnly(),
                            TextInput::make('gecici_isci')->label('Geçici İşçi')->numeric()->default($personelDurum->gecici_isci ?? 0)->readOnly(),
                        ]),
                    ]),

                // --- 3. BÖLÜM: FAALİYET PLANI ---
                Section::make('Faaliyet Planı ve Gerçekleşmeler')
                    ->description('Ay içindeki haftalara göre yapacağınız işleri planlayın.')
                    ->schema([
                        Repeater::make('faaliyetler')
                            ->label('İş Listesi')
                            ->schema([
                                // ÜST SATIR: Hafta, Durum ve Son Tarih
                                Grid::make(3)->schema([
                                    Select::make('hafta')
                                        ->label('Hafta')
                                        ->options([1 => '1. Hafta', 2 => '2. Hafta', 3 => '3. Hafta', 4 => '4. Hafta', 5 => '5. Hafta'])
                                        ->required(),

                                    Select::make('durum')
                                        ->label('İşin Durumu')
                                        ->options([
                                            'bekliyor' => '⏳ Planlandı / Bekliyor',
                                            'devam' => '⚙️ Devam Ediyor',
                                            'tamam' => '✅ Tamamlandı',
                                        ])
                                        ->default('bekliyor')
                                        ->required()
                                        ->live(),

                                    DatePicker::make('son_tarih')
                                        ->label('Son Tarih')
                                        ->required()
                                        ->native(false)
                                        ->displayFormat('d/m/Y')
                                        ->minDate(now()->startOfDay()) // Geçmiş tarih seçilemez
                                        ->live(),
                                ]), // <--- HATA BURADAYDI: Grid burada kapanmalıydı.

                                // ALT SATIRLAR: Konu ve Gerekçeler
                                TextInput::make('konu')
                                    ->label('Yapılacak İş / Konu')
                                    ->required()
                                    ->columnSpanFull(),

                                // GECİKME GEREKÇESİ
                                Textarea::make('gecikme_gerekcesi')
                                    ->label('Gecikme Gerekçesi')
                                    ->placeholder('İşin süresi geçtiği için nedenini yazmak zorunludur.')
                                    ->required(fn (Forms\Get $get) => 
                                        $get('son_tarih') && 
                                        Carbon::parse($get('son_tarih'))->isPast() && 
                                        $get('durum') !== 'tamam'
                                    )
                                    ->visible(fn (Forms\Get $get) => 
                                        $get('son_tarih') && 
                                        Carbon::parse($get('son_tarih'))->isPast() && 
                                        $get('durum') !== 'tamam'
                                    )
                                    ->columnSpanFull(),

                                TextInput::make('aciklama')
                                    ->label('Gerçekleşme Sonucu')
                                    ->columnSpanFull(),
                            ])
                            ->defaultItems(1)
                            ->reorderableWithButtons()
                            ->collapsible(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('yil')->label('Yıl')->badge(),
                Tables\Columns\TextColumn::make('ay')
                    ->label('Ay')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        '01' => 'Ocak', '02' => 'Şubat', '03' => 'Mart', '04' => 'Nisan', '05' => 'Mayıs', '06' => 'Haziran',
                        '07' => 'Temmuz', '08' => 'Ağustos', '09' => 'Eylül', '10' => 'Ekim', '11' => 'Kasım', '12' => 'Aralık', default => $state,
                    }),
                Tables\Columns\TextColumn::make('user.name')->label('Müdürlük')->sortable()->searchable(),
                
                Tables\Columns\TextColumn::make('geciken_isler')
                    ->label('Gecikme Durumu')
                    ->badge()
                    ->getStateUsing(function ($record) {
                        $isler = is_string($record->faaliyetler) ? json_decode($record->faaliyetler, true) : $record->faaliyetler;
                        if (!is_array($isler)) return 'Sorun Yok';
                        
                        $gecikenSayisi = 0;
                        foreach ($isler as $is) {
                            if (isset($is['son_tarih']) && Carbon::parse($is['son_tarih'])->isPast() && ($is['durum'] ?? '') !== 'tamam') {
                                $gecikenSayisi++;
                            }
                        }
                        return $gecikenSayisi > 0 ? "$gecikenSayisi İş Gecikti" : 'Zamanında';
                    })
                    ->color(fn ($state) => str_contains($state, 'Gecikti') ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('created_at')->label('Oluşturulma')->date('d.m.Y'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()->label('Planı Güncelle'),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        if (auth()->id() !== 1) { 
            $query->where('user_id', auth()->id());
        }
        return $query;
    }

    public static function canCreate(): bool { return auth()->id() !== 1; }
    public static function canEdit($record): bool { return auth()->id() !== 1; }
    public static function canDelete($record): bool { return auth()->id() !== 1; }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAylikFaaliyets::route('/'),
            'create' => Pages\CreateAylikFaaliyet::route('/create'),
            'edit' => Pages\EditAylikFaaliyet::route('/{record}/edit'),
        ];
    }
}