<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GecikmeRaporuResource\Pages;
use App\Models\AylikFaaliyet;
use App\Models\User; // Müdürlükleri çekmek için
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\Section as InfoSection;
use Carbon\Carbon;

class GecikmeRaporuResource extends Resource
{
    protected static ?string $model = AylikFaaliyet::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';
    protected static ?string $navigationLabel = 'Gecikme Raporu';
    protected static ?string $pluralModelLabel = 'Gecikme Raporları';
    protected static ?string $modelLabel = 'Gecikme Raporu';
    protected static ?string $navigationGroup = 'Yönetim';

    public static function canViewAny(): bool
    {
        return auth()->id() === 1;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Müdürlük / Birim')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('donem')
                    ->label('Dönem')
                    ->getStateUsing(fn ($record) => $record->yil . ' / ' . $record->ay),

                TextColumn::make('geciken_ozeti')
                    ->label('Geciken İşler')
                    ->html()
                    ->getStateUsing(function ($record) {
                        $isler = is_string($record->faaliyetler) ? json_decode($record->faaliyetler, true) : $record->faaliyetler;
                        $output = "";
                        if (is_array($isler)) {
                            foreach ($isler as $is) {
                                if (isset($is['son_tarih']) && Carbon::parse($is['son_tarih'])->isPast() && ($is['durum'] ?? '') !== 'tamam') {
                                    $output .= "• " . e($is['konu']) . "<br>";
                                }
                            }
                        }
                        return $output ?: '<span class="text-gray-400">Gecikme yok</span>';
                    }),
            ])
            ->filters([
                // MÜDÜRLÜK SEÇME FİLTRESİ
                SelectFilter::make('user_id')
                    ->label('Müdürlük Seç')
                    ->relationship('user', 'name') // User modeliyle olan ilişkiden isimleri çeker
                    ->searchable()
                    ->preload(),
                
                // YIL FİLTRESİ (Opsiyonel ama yararlı olur)
                SelectFilter::make('yil')
                    ->label('Yıl Seç')
                    ->options([
                        2024 => '2024',
                        2025 => '2025',
                        2026 => '2026',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Detay'),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        // ... (Önceki infolist kodun aynı kalacak)
        return $infolist->schema([
            InfoSection::make('Detaylar')->schema([
                TextEntry::make('user.name')->label('Müdürlük'),
                TextEntry::make('geciken_detaylari')
                    ->label('Geciken İşler')
                    ->html()
                    ->getStateUsing(function ($record) {
                        $isler = is_string($record->faaliyetler) ? json_decode($record->faaliyetler, true) : $record->faaliyetler;
                        $liste = "";
                        if (is_array($isler)) {
                            foreach ($isler as $is) {
                                if (isset($is['son_tarih']) && Carbon::parse($is['son_tarih'])->isPast() && ($is['durum'] ?? '') !== 'tamam') {
                                    $gerekce = $is['gecikme_gerekcesi'] ?? 'Gerekçe girilmemiş!';
                                    $liste .= "<div style='margin-bottom:10px; border-bottom:1px solid #eee;'><b>{$is['konu']}</b><br>Gerekçe: {$gerekce}</div>";
                                }
                            }
                        }
                        return $liste ?: 'Gecikme yok.';
                    })->columnSpanFull(),
            ])
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGecikmeRaporus::route('/'),
        ];
    }
}