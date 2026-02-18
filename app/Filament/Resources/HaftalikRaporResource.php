<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HaftalikRaporResource\Pages;
use App\Models\HaftalikRapor;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf; // PDF İşlemleri
use Illuminate\Support\Str;     // Dosya isimlendirme için

// Form Bileşenleri
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Get;
use Filament\Forms\Set;

// Tablo Bileşenleri
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\Action; // PDF Butonu İçin

class HaftalikRaporResource extends Resource
{
    protected static ?string $model = HaftalikRapor::class;
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationLabel = 'Haftalık Raporlar';
    protected static ?string $navigationGroup = 'Kurumsal Hafıza';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // --- BÖLÜM 1: TARİH VE DÖNEM SEÇİMİ ---
                Section::make('Rapor Dönemi')
                    ->description('Raporun ait olduğu haftayı seçiniz.')
                    ->schema([
                        // 1. AY SEÇİMİ (Filtre Amaçlı - Veritabanına Kaydedilmez)
                        Select::make('rapor_ayi')
                            ->label('Ay Seçiniz (2026)')
                            ->options([
                                1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
                                5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
                                9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık',
                            ])
                            ->reactive()
                            ->afterStateUpdated(fn (Set $set) => $set('rapor_secimi', null))
                            ->dehydrated(false) 
                            ->columnSpan(1),

                        // 2. HAFTA SEÇİMİ (Hesaplama Amaçlı - Veritabanına Kaydedilmez)
                        Select::make('rapor_secimi')
                            ->label('Hafta Seçiniz')
                            ->options(function (Get $get) {
                                $selectedMonth = $get('rapor_ayi');
                                if (! $selectedMonth) return [];

                                $weeks = [];
                                $date = Carbon::create(2026, $selectedMonth, 1);

                                // Ayın ilk pazartesini bul
                                if (!$date->isMonday()) {
                                    $date->next(Carbon::MONDAY);
                                }

                                // Ay bitene kadar haftaları listele
                                while ($date->month == $selectedMonth) {
                                    $monday = $date->copy();
                                    $thursday = $date->copy()->addDays(3); // Pazartesi - Perşembe
                                    $weeks[$monday->format('Y-m-d')] = $monday->format('d.m.Y') . ' - ' . $thursday->format('d.m.Y') . ' Haftası';
                                    $date->addWeek();
                                }
                                return $weeks;
                            })
                            ->required()
                            ->searchable()
                            ->reactive()
                            ->afterStateUpdated(function ($state, Set $set) {
                                if ($state) {
                                    $baslangic = Carbon::parse($state);
                                    $bitis = $baslangic->copy()->addDays(3);
                                    // Hidden alanları doldur
                                    $set('baslangic_tarihi', $baslangic->format('Y-m-d'));
                                    $set('bitis_tarihi', $bitis->format('Y-m-d'));
                                }
                            })
                            ->dehydrated(false) 
                            ->columnSpan(1),

                        // Gizli Alanlar (Veritabanına Giden Asıl Veriler)
                        Hidden::make('baslangic_tarihi'),
                        Hidden::make('bitis_tarihi'),

                        // Kullanıcıya Görsel Geri Bildirim
                        Placeholder::make('onizleme')
                            ->label('Rapor Bilgisi')
                            ->content(fn (Get $get) => 
                                auth()->user()->name . ' / ' . 
                                ($get('baslangic_tarihi') ? Carbon::parse($get('baslangic_tarihi'))->format('d.m.Y') : '...') . ' - ' . 
                                ($get('bitis_tarihi') ? Carbon::parse($get('bitis_tarihi'))->format('d.m.Y') : '...') . ' Haftası'
                            )
                            ->columnSpanFull(),
                    ])->columns(2),

                // --- BÖLÜM 2: PERSONEL SAYILARI ---
                Section::make('Personel Durumu')
                    ->schema([
                        TextInput::make('memur_sayisi')->label('Memur Sayısı')->numeric()->default(0),
                        TextInput::make('sozlesmeli_memur_sayisi')->label('Sözleşmeli Memur')->numeric()->default(0),
                        TextInput::make('kadrolu_isci_sayisi')->label('Kadrolu İşçi')->numeric()->default(0),
                        TextInput::make('sirket_personeli_sayisi')->label('Şirket Personeli')->numeric()->default(0),
                    ])->columns(4),

                // --- BÖLÜM 3: ŞİKAYETLER (Otomatik Toplama) ---
                Section::make('Talep ve Şikayet İstatistikleri')
                    ->schema([
                        TextInput::make('cimer_sayisi')->label('CİMER')->numeric()->default(0)->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => $set('toplam_sikayet', (int)$get('cimer_sayisi') + (int)$get('acikkapi_sayisi') + (int)$get('belediye_sayisi'))),
                        
                        TextInput::make('acikkapi_sayisi')->label('AÇIKKAPI')->numeric()->default(0)->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => $set('toplam_sikayet', (int)$get('cimer_sayisi') + (int)$get('acikkapi_sayisi') + (int)$get('belediye_sayisi'))),
                        
                        TextInput::make('belediye_sayisi')->label('BELEDİYE')->numeric()->default(0)->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => $set('toplam_sikayet', (int)$get('cimer_sayisi') + (int)$get('acikkapi_sayisi') + (int)$get('belediye_sayisi'))),
                        
                        // Toplam Alanı
                        TextInput::make('toplam_sikayet')
                            ->label('GENEL TOPLAM')
                            ->numeric()
                            ->default(0)
                            ->readOnly()
                            ->dehydrated(), // Veri kaybını önler
                    ])->columns(4),

                // --- BÖLÜM 4: FAALİYETLER ---
                Section::make('FAALİYETLER (Bu Hafta Yapılan İşler)')
                    ->schema([
                        Repeater::make('faaliyetler')
                            ->label(' ')
                            ->schema([
                                TextInput::make('sira_no')
                                    ->label('Sıra No')
                                    ->numeric()
                                    ->default(function (Get $get) {
                                        $mevcut = $get('../../faaliyetler') ?? [];
                                        $max = 0;
                                        foreach ($mevcut as $satir) {
                                            $no = intval($satir['sira_no'] ?? 0);
                                            if ($no > $max) $max = $no;
                                        }
                                        return $max + 1;
                                    })
                                    ->readOnly()->dehydrated()->columnSpan(1),

                                TextInput::make('konusu')->label('Konusu / Yapılan İş')->columnSpan(3),
                                DatePicker::make('baslama_tarihi')->label('Başlama Tarihi'),
                                DatePicker::make('bitis_tarihi')->label('Bitiş Tarihi'),
                                TextInput::make('durum')->label('Durum / Safahat')->columnSpan(2),
                            ])->columns(8)->addActionLabel('Yeni Faaliyet Ekle'),
                    ]),

                // --- BÖLÜM 5: PLANLANAN FAALİYETLER ---
                Section::make('PLANLANAN FAALİYETLER (Gelecek Dönem)')
                    ->schema([
                        Repeater::make('detayli_planlanan_faaliyetler')
                            ->label(' ')
                            ->schema([
                                TextInput::make('sira_no')
                                    ->label('No')
                                    ->numeric()
                                    ->default(function (Get $get) {
                                        $mevcut = $get('../../detayli_planlanan_faaliyetler') ?? [];
                                        $max = 0;
                                        foreach ($mevcut as $satir) {
                                            $no = intval($satir['sira_no'] ?? 0);
                                            if ($no > $max) $max = $no;
                                        }
                                        return $max + 1;
                                    })
                                    ->readOnly()->dehydrated()->columnSpan(1),

                                TextInput::make('konusu')->label('Konusu')->columnSpan(3),
                                DatePicker::make('baslama_tarihi')->label('Başlama T.'),
                                DatePicker::make('bitis_tarihi')->label('Tahmini Bitiş'),
                                TextInput::make('durum')->label('Durum')->columnSpan(2),
                            ])->columns(8)->addActionLabel('Plan Ekle'),
                    ]),

                // --- BÖLÜM 6: İHALELER ---
                Section::make('YAPILAN İHALELER VE DOĞRUDAN TEMİNLER')
                    ->schema([
                        Repeater::make('ihaleler')
                            ->label(' ')
                            ->schema([
                                TextInput::make('sira_no')
                                    ->label('No')
                                    ->default(function (Get $get) {
                                        $mevcut = $get('../../ihaleler') ?? [];
                                        $max = 0;
                                        foreach ($mevcut as $satir) {
                                            $no = intval($satir['sira_no'] ?? 0);
                                            if ($no > $max) $max = $no;
                                        }
                                        return $max + 1;
                                    })
                                    ->readOnly()->dehydrated(),

                                TextInput::make('isin_adi')->label('İşin Adı'),
                                TextInput::make('yasal_dayanak')->label('Yasal Dayanak'),
                                DatePicker::make('ihale_tarihi')->label('İhale Tar.'),
                                DatePicker::make('sozlesme_tarihi')->label('Sözleşme Tar.'),
                                TextInput::make('sozlesme_tutari')->label('Tutar')->prefix('₺'),
                                DatePicker::make('yer_teslim_tarihi')->label('Yer Teslim'),
                                DatePicker::make('bitis_tarihi')->label('Bitiş'),
                            ])->columns(4)->addActionLabel('İhale/Alım Ekle'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // 1. SÜTUN: Rapor Adı (Müdürlük Adı + Tarih)
                // Modeldeki tamRaporAdi Accessor'ını kullanır.
                TextColumn::make('tam_rapor_adi')
                    ->label('Rapor Başlığı')
                    // Admin için arama: Tarih veya Müdürlük adına göre arama yapılabilir
                    ->searchable(['baslangic_tarihi', 'user.name']) 
                    ->wrap(), // Uzun isimleri alt satıra geçir

                // 2. SÜTUN: Şikayet İstatistikleri
                TextColumn::make('toplam_sikayet')
                    ->label('Şikayet')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),

                // 3. SÜTUN: Oluşturulma Tarihi
                TextColumn::make('created_at')
                    ->label('Oluşturulma')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('baslangic_tarihi', 'desc')
            ->actions([
                // --- PDF İNDİRME BUTONU ---
                Action::make('pdf_indir')
                    ->label('PDF İndir')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success') // Yeşil renk
                    ->action(function (HaftalikRapor $record) {
                        
                        // 1. Müdürlük (User) ilişkisini yükle
                        $record->load('user');

                        // 2. Dosya İsmini Formatla
                        $baslangic = $record->baslangic_tarihi ? $record->baslangic_tarihi->format('d.m.Y') : 'tarihsiz';
                        $bitis = $record->bitis_tarihi ? $record->bitis_tarihi->format('d.m.Y') : 'tarihsiz';
                        
                        // Müdürlük adını dosya ismine uygun hale getir (Türkçe karakter temizle)
                        $mudurluk = $record->user ? Str::slug($record->user->name) : 'personel';

                        // Örn: 01.01.2026-04.01.2026-fen-isleri.pdf
                        $dosyaAdi = "{$baslangic}-{$bitis}-{$mudurluk}.pdf";

                        // 3. İndirme İşlemi
                        return response()->streamDownload(function () use ($record) {
                            echo Pdf::loadView('pdf.haftalik-rapor', ['record' => $record])->output();
                        }, $dosyaAdi);
                    }),
                // --------------------------

                ViewAction::make()->label('İncele'),
                DeleteAction::make()->label('Sil'),
            ]);
    }

    // --- YETKİLENDİRME SORGUSU ---
    // Bu kısım, kimin hangi raporu göreceğini belirler.
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Eğer kullanıcı ID'si 1 DEĞİLSE (Yani Admin değilse)
        // Sadece kendi ID'sine ait raporları listele
        if (auth()->id() !== 1) { 
            $query->where('user_id', auth()->id());
        }
        
        // Admin (ID:1) hepsini görür.
        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHaftalikRapors::route('/'),
            'create' => Pages\CreateHaftalikRapor::route('/create'),
        ];
    }
}