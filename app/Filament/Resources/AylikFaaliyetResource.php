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
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Get;
use Carbon\Carbon;

class AylikFaaliyetResource extends Resource
{
    protected static ?string $model = AylikFaaliyet::class;
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationLabel = 'Aylık Plan ve Faaliyet';
    protected static ?string $navigationGroup = 'Raporlama';

    public static function hasBlockingOverdueTasks(): bool
    {
        if (auth()->id() === 1) return false;
        $userRecords = AylikFaaliyet::where('user_id', auth()->id())->get();
        foreach ($userRecords as $record) {
            $isler = is_string($record->faaliyetler) ? json_decode($record->faaliyetler, true) : $record->faaliyetler;
            if (!is_array($isler)) continue;
            foreach ($isler as $is) {
                $isPast = !empty($is['son_tarih']) && Carbon::parse($is['son_tarih'])->isPast();
                $hasNote = !empty($is['gerceklesme_notu']);
                $isCompleted = (bool)($is['is_completed'] ?? false);
                if ($isPast && !$hasNote && !$isCompleted) { return true; }
            }
        }
        return false;
    }

    public static function form(Form $form): Form
    {
        $personelDurum = PersonelDurum::where('user_id', auth()->id())->first();
        $isBlocked = static::hasBlockingOverdueTasks();

        return $form
            ->schema([
                Section::make('⚠️ SİSTEM KİLİTLENDİ')
                    ->description('Gerekçesi yazılmamış gecikmiş işleriniz var!')
                    ->schema([
                        Forms\Components\Placeholder::make('warning_status')
                            ->label('DURUM:')
                            ->content(new \Illuminate\Support\HtmlString('<b style="color:red;">Geçmiş raporları tamamlamadan yeni planlama yapılamaz.</b>'))
                    ])
                    ->visible($isBlocked),

                Section::make('Dönem Bilgisi')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('yil')
                                ->options([2025 => '2025', 2026 => '2026'])
                                ->default(now()->year)->required(),
                            Select::make('ay')
                                ->options(['01' => 'Ocak', '02' => 'Şubat', '03' => 'Mart', '04' => 'Nisan', '05' => 'Mayıs', '06' => 'Haziran', '07' => 'Temmuz', '08' => 'Ağustos', '09' => 'Eylül', '10' => 'Ekim', '11' => 'Kasım', '12' => 'Aralık'])
                                ->default(now()->format('m'))->required(),
                        ]),
                    ])->disabled($isBlocked),

                Section::make('İş Planlama Listesi')
                    ->schema([
                        Repeater::make('faaliyetler')
                            ->schema([
                                Grid::make(3)->schema([
                                    Select::make('hafta')
                                        ->label('Hafta')
                                        ->options([
                                            1 => '1. Hafta', 
                                            2 => '2. Hafta', 
                                            3 => '3. Hafta', 
                                            4 => '4. Hafta' // İstediğin gibi 4 haftaya düşürüldü
                                        ])
                                        ->required(),
                                    DatePicker::make('son_tarih')
                                        ->label('Hedef Bitiş')
                                        ->required()
                                        ->native(false)
                                        ->displayFormat('d/m/Y')
                                        // Kesin engelleme:
                                        ->minDate(now()->startOfDay()) 
                                        ->validationMessages(['min' => 'Geçmiş bir tarih planlanamaz.']),
                                    Checkbox::make('is_completed')
                                        ->label('Tamamlandı')
                                        ->live()
                                        ->hidden(fn ($livewire) => $livewire instanceof Pages\CreateAylikFaaliyet),
                                ]),

                                TextInput::make('konu')->required()->columnSpanFull(),

                                Textarea::make('gerceklesme_notu')
                                    ->label('Gerekçe / Sonuç')
                                    ->required(fn (Get $get) => $get('is_completed') || ($get('son_tarih') && Carbon::parse($get('son_tarih'))->isPast()))
                                    ->visible(fn (Get $get) => $get('is_completed') || ($get('son_tarih') && Carbon::parse($get('son_tarih'))->isPast()))
                                    ->columnSpanFull(),
                            ])
                            ->addable(!$isBlocked) 
                            ->deletable(!$isBlocked)
                            ->reorderableWithButtons()
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
                
                // YENİ EKLENEN HAFTA SÜTUNU:
                Tables\Columns\TextColumn::make('planlanan_haftalar')
                    ->label('Haftalar')
                    ->badge()
                    ->color('gray')
                    ->getStateUsing(function ($record) {
                        $isler = is_string($record->faaliyetler) ? json_decode($record->faaliyetler, true) : $record->faaliyetler;
                        if (!is_array($isler)) return '-';
                        return collect($isler)->pluck('hafta')->unique()->sort()->implode(', ') ?: '-';
                    }),

                Tables\Columns\TextColumn::make('user.name')->label('Müdürlük')->searchable(),
                
                Tables\Columns\TextColumn::make('analiz')
                    ->label('Durum')
                    ->badge()
                    ->getStateUsing(function ($record) {
                        $isler = is_string($record->faaliyetler) ? json_decode($record->faaliyetler, true) : $record->faaliyetler;
                        $geciken = 0;
                        if (is_array($isler)) {
                            foreach ($isler as $is) {
                                if (empty($is['gerceklesme_notu']) && !empty($is['son_tarih']) && Carbon::parse($is['son_tarih'])->isPast()) {
                                    $geciken++;
                                }
                            }
                        }
                        return $geciken > 0 ? "$geciken Gecikme (KİLİTLİ)" : 'Sorun Yok';
                    })
                    ->color(fn ($state) => str_contains($state, 'KİLİTLİ') ? 'danger' : 'success'),
            ])
            ->actions([
                Tables\Actions\Action::make('gecikmeBildir')
                    ->label('Raporla')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->modalHeading('Gecikme Gerekçelerini Girin')
                    ->form(function ($record) {
                        $isler = is_string($record->faaliyetler) ? json_decode($record->faaliyetler, true) : $record->faaliyetler;
                        $fields = [];
                        foreach ($isler as $index => $is) {
                            if (empty($is['gerceklesme_notu']) && !empty($is['son_tarih']) && Carbon::parse($is['son_tarih'])->isPast()) {
                                $fields[] = Section::make("İş: " . ($is['konu'] ?? 'Tanımsız'))
                                    ->schema([
                                        Textarea::make("updates.{$index}.gerceklesme_notu")->label('Gecikme Nedeni')->required(),
                                    ]);
                            }
                        }
                        return $fields;
                    })
                    ->action(function (AylikFaaliyet $record, array $data) {
                        $current = $record->faaliyetler;
                        if (isset($data['updates'])) {
                            foreach ($data['updates'] as $index => $up) {
                                $current[$index]['gerceklesme_notu'] = $up['gerceklesme_notu'];
                            }
                            $record->update(['faaliyetler' => $current]);
                        }
                    })
                    ->visible(fn ($record) => auth()->id() !== 1 && static::hasBlockingOverdueTasks()),

                Tables\Actions\EditAction::make()->modal(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        if (auth()->id() !== 1) { $query->where('user_id', auth()->id()); }
        return $query;
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