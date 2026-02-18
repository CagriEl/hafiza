<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SwotAnalizResource\Pages;
use App\Models\SwotAnaliz;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope; // Eğer soft delete kullanıyorsanız
use Barryvdh\DomPDF\Facade\Pdf; // PDF Kütüphanesi
use Illuminate\Support\Str;     // Dosya isimlendirme için

// Form Bileşenleri
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Grid;

// Tablo Bileşenleri
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\DeleteAction;

class SwotAnalizResource extends Resource
{
    protected static ?string $model = SwotAnaliz::class;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-bar';
    protected static ?string $navigationLabel = 'SWOT Analizleri';
    protected static ?string $navigationGroup = 'Kurumsal Hafıza';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                
                // --- BÖLÜM 1: GENEL BİLGİLER (Başlık ve Yıl) ---
                Section::make('Dönem ve Başlık Bilgisi')
                    ->description('Analiz için açıklayıcı bir başlık ve yıl seçiniz.')
                    ->schema([
                        
                        // YENİ EKLENEN BAŞLIK ALANI
                        TextInput::make('baslik')
                            ->label('Analiz Başlığı')
                            ->placeholder('Örn: 2026 Bilgi İşlem Stratejik Planı')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(), // Tüm satırı kaplasın

                        // YIL SEÇİMİ
                        Select::make('yil')
                            ->label('Analiz Yılı')
                            ->options([
                                2024 => '2024',
                                2025 => '2025',
                                2026 => '2026',
                                2027 => '2027',
                                2028 => '2028',
                            ])
                            ->default(now()->year)
                            ->required()
                            ->native(false),
                    ]),

                // --- BÖLÜM 2: SWOT MATRİSİ (2 SÜTUNLU YAPI) ---
                Section::make('Stratejik Analiz Matrisi')
                    ->description('Lütfen maddeleri alt alta giriniz. Editördeki liste özelliklerini kullanabilirsiniz.')
                    ->schema([
                        Grid::make(2) // Yan yana 2 kutu düzeni
                            ->schema([
                                // 1. GÜÇLÜ YÖNLER (Sol Üst)
                                RichEditor::make('guclu_yonler')
                                    ->label('GÜÇLÜ YÖNLER (Strengths)')
                                    ->toolbarButtons(['bold', 'bulletList', 'orderedList', 'undo', 'redo'])
                                    ->columnSpan(1),

                                // 2. ZAYIF YÖNLER (Sağ Üst)
                                RichEditor::make('zayif_yonler')
                                    ->label('ZAYIF YÖNLER (Weaknesses)')
                                    ->toolbarButtons(['bold', 'bulletList', 'orderedList', 'undo', 'redo'])
                                    ->columnSpan(1),
                                
                                // 3. FIRSATLAR (Sol Alt)
                                RichEditor::make('firsatlar')
                                    ->label('FIRSATLAR (Opportunities)')
                                    ->toolbarButtons(['bold', 'bulletList', 'orderedList', 'undo', 'redo'])
                                    ->columnSpan(1),

                                // 4. TEHDİTLER (Sağ Alt)
                                RichEditor::make('tehditler')
                                    ->label('TEHDİTLER (Threats)')
                                    ->toolbarButtons(['bold', 'bulletList', 'orderedList', 'undo', 'redo'])
                                    ->columnSpan(1),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // 1. Müdürlük Adı
                TextColumn::make('user.name')
                    ->label('Müdürlük')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                // 2. Rapor Başlığı (YENİ)
                TextColumn::make('baslik')
                    ->label('Rapor Başlığı')
                    ->searchable()
                    ->limit(40) // Çok uzunsa sonuna ... koyar
                    ->tooltip(fn (SwotAnaliz $record): string => $record->baslik ?? ''),

                // 3. Yıl
                TextColumn::make('yil')
                    ->label('Dönem')
                    ->sortable()
                    ->badge(),

                // 4. Tarih
                TextColumn::make('created_at')
                    ->label('Oluşturulma')
                    ->dateTime('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true), // Varsayılan gizli
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                
                // --- PDF İNDİRME BUTONU ---
                Action::make('pdf_indir')
                    ->label('PDF İndir')
                    ->icon('heroicon-o-arrow-down-tray') // İndirme ikonu
                    ->color('success') // Yeşil Buton
                    ->action(function (SwotAnaliz $record) {
                        
                        // İlişkiyi Yükle (Müdürlük adını PDF'te kullanmak için)
                        $record->load('user');

                        // Dosya Adını Oluştur: swot-bilgi-islem-2026.pdf gibi
                        $mudurluk = $record->user ? Str::slug($record->user->name) : 'genel';
                        $yil = $record->yil ?? now()->year;
                        $dosyaAdi = "swot-{$mudurluk}-{$yil}.pdf";

                        // PDF Oluşturma ve İndirme
                        return response()->streamDownload(function () use ($record) {
                            echo Pdf::loadView('pdf.swot-rapor', ['record' => $record])
                                ->setPaper('a4', 'portrait') // Dikey A4
                                ->output();
                        }, $dosyaAdi);
                    }),
                // ---------------------------

                ViewAction::make()->label('İncele'),
                EditAction::make()->label('Düzenle'),
                DeleteAction::make()->label('Sil'),
            ]);
    }

    // --- YETKİLENDİRME ---
    // Eğer kullanıcı Admin (ID: 1) değilse sadece kendi kayıtlarını görür.
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (auth()->id() !== 1) { 
            $query->where('user_id', auth()->id());
        }
        
        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSwotAnalizs::route('/'),
            'create' => Pages\CreateSwotAnaliz::route('/create'),
            'edit' => Pages\EditSwotAnaliz::route('/{record}/edit'),
        ];
    }
}