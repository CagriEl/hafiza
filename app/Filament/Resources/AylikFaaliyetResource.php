<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AylikFaaliyetResource\Pages;
use App\Models\AylikFaaliyet;
use App\Models\PersonelDurum; // Personel sayılarını çekmek için gerekli
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

// Form Bileşenleri
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Grid;

class AylikFaaliyetResource extends Resource
{
    protected static ?string $model = AylikFaaliyet::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationLabel = 'Aylık Plan ve Faaliyet';
    protected static ?string $navigationGroup = 'Raporlama';

    public static function form(Form $form): Form
    {
        // Müdürlüğün güncel personel sayısını veritabanından bulalım
        // Eğer henüz giriş yapmadılarsa hata vermemesi için boş bir nesne oluşturuyoruz.
        $personelDurum = PersonelDurum::where('user_id', auth()->id())->first();

        return $form
            ->schema([
                
                // --- 1. BÖLÜM: DÖNEM BİLGİLERİ ---
                Section::make('Dönem Bilgisi')
                    ->description('Raporlamak istediğiniz Yıl ve Ayı seçiniz.')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('yil')
                                ->label('Yıl')
                                ->options([
                                    2024 => '2024',
                                    2025 => '2025',
                                    2026 => '2026',
                                    2027 => '2027',
                                ])
                                ->default(now()->year)
                                ->required(),

                            Select::make('ay')
                                ->label('Ay')
                                ->options([
                                    '01' => 'Ocak', '02' => 'Şubat', '03' => 'Mart',
                                    '04' => 'Nisan', '05' => 'Mayıs', '06' => 'Haziran',
                                    '07' => 'Temmuz', '08' => 'Ağustos', '09' => 'Eylül',
                                    '10' => 'Ekim', '11' => 'Kasım', '12' => 'Aralık'
                                ])
                                ->default(now()->format('m'))
                                ->required(),
                        ]),
                    ]),

                // --- 2. BÖLÜM: PERSONEL DURUMU (OTOMATİK GELİR) ---
                Section::make('Mevcut Personel Sayıları')
                    ->description('Bu sayılar "Personel Sayıları" ekranından otomatik çekilmiştir.')
                    ->schema([
                        Grid::make(5)->schema([ // 5 sütun yan yana
                            TextInput::make('memur')
                                ->label('Memur')
                                ->numeric()
                                ->default($personelDurum->memur ?? 0) // Veritabanından gelen sayı
                                ->readOnly(), // Değiştirilemez (kilitli)

                            TextInput::make('sozlesmeli_memur')
                                ->label('Sözleşmeli P.')
                                ->numeric()
                                ->default($personelDurum->sozlesmeli_memur ?? 0)
                                ->readOnly(),

                            TextInput::make('kadrolu_isci')
                                ->label('Kadrolu İşçi')
                                ->numeric()
                                ->default($personelDurum->kadrolu_isci ?? 0)
                                ->readOnly(),

                            TextInput::make('sirket_personeli')
                                ->label('Şirket Pers.')
                                ->numeric()
                                ->default($personelDurum->sirket_personeli ?? 0)
                                ->readOnly(),

                            TextInput::make('gecici_isci')
                                ->label('Geçici İşçi')
                                ->numeric()
                                ->default($personelDurum->gecici_isci ?? 0)
                                ->readOnly(),
                        ]),
                    ]),

                // --- 3. BÖLÜM: HAFTALIK PLANLAMA ---
                Section::make('Faaliyet Planı ve Gerçekleşmeler')
                    ->description('Ay içindeki haftalara göre yapacağınız işleri planlayın.')
                    ->schema([
                        Repeater::make('faaliyetler')
                            ->label('İş Listesi')
                            ->schema([
                                // 1. Satır: Hafta ve Durum
                                Grid::make(2)->schema([
                                    Select::make('hafta')
                                        ->label('Planlanan Hafta')
                                        ->options([
                                            1 => '1. Hafta',
                                            2 => '2. Hafta',
                                            3 => '3. Hafta',
                                            4 => '4. Hafta',
                                            5 => '5. Hafta',
                                        ])
                                        ->required(),

                                    Select::make('durum')
                                        ->label('İşin Durumu')
                                        ->options([
                                            'bekliyor' => '⏳ Planlandı / Bekliyor',
                                            'devam' => '⚙️ Devam Ediyor',
                                            'tamam' => '✅ Tamamlandı',
                                            'iptal' => '❌ İptal Edildi',
                                        ])
                                        ->default('bekliyor')
                                        ->required(),
                                ]),

                                // 2. Satır: Konu
                                TextInput::make('konu')
                                    ->label('Yapılacak İş / Konu')
                                    ->required()
                                    ->placeholder('Örn: Personel maaşlarının hesaplanması')
                                    ->columnSpanFull(),

                                // 3. Satır: Açıklama (Sonuç)
                                TextInput::make('aciklama')
                                    ->label('Gerçekleşme Sonucu (Tamamlandığında Doldurunuz)')
                                    ->placeholder('İş tamamlandıysa detayını buraya yazın.')
                                    ->columnSpanFull(),
                            ])
                            ->columns(1) // Repeater içindeki elemanlar tek sütun aksın (Grid ile böldük zaten)
                            ->defaultItems(1) // Açılışta 1 tane boş gelsin
                            ->reorderableWithButtons() // Sıralama butonları
                            ->collapsible(), // Küçültülebilir olsun
                    ]),
            ]);
    }

   public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('yil')
                    ->label('Yıl')
                    ->sortable()
                    ->badge(),
                
                Tables\Columns\TextColumn::make('ay')
                    ->label('Ay')
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        '01' => 'Ocak', '02' => 'Şubat', '03' => 'Mart',
                        '04' => 'Nisan', '05' => 'Mayıs', '06' => 'Haziran',
                        '07' => 'Temmuz', '08' => 'Ağustos', '09' => 'Eylül',
                        '10' => 'Ekim', '11' => 'Kasım', '12' => 'Aralık',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Müdürlük')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Oluşturulma')
                    ->date('d.m.Y'),
                
                // --- DÜZELTİLEN KISIM BURASI ---
                Tables\Columns\TextColumn::make('faaliyetler')
                    ->label('İş Yükü')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(function ($state, $record) {
                        // Veriyi güvenli hale getiriyoruz
                        $isler = $record->faaliyetler;
                        
                        // Eğer metin olarak geldiyse diziye çevir
                        if (is_string($isler)) {
                            $isler = json_decode($isler, true);
                        }

                        // Eğer boşsa 0, değilse sayısını yaz
                        return is_array($isler) ? count($isler) . ' İş' : '0 İş';
                    }),
                // -------------------------------
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()->label('Planı Güncelle'),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    // --- YETKİLENDİRME ---
    // Admin (ID:1) herkesi görür, diğerleri sadece kendi raporlarını görür.
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (auth()->id() !== 1) { 
            $query->where('user_id', auth()->id());
        }
        
        return $query;
    }

    // --- YETKİ KISITLAMALARI ---

    // Admin rapor OLUŞTURAMAZ
    public static function canCreate(): bool
    {
        return auth()->id() !== 1;
    }

    // Admin raporu DÜZENLEYEMEZ
    public static function canEdit($record): bool
    {
        return auth()->id() !== 1;
    }

    // Admin raporu SİLEMEZ (İsterseniz bunu true yapıp silmesine izin verebilirsiniz)
    public static function canDelete($record): bool
    {
        return auth()->id() !== 1;
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