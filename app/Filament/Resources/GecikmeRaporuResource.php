<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GecikmeRaporuResource\Pages;
use App\Models\AylikFaaliyet;
use App\Support\AylikFaaliyetEscalation;
use App\Support\QuerySafety;
use App\Support\ReportDirectorateScope;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GecikmeRaporuResource extends Resource
{
    protected static ?string $model = AylikFaaliyet::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationLabel = 'Gecikme Raporu';

    protected static ?string $pluralModelLabel = 'Gecikme Raporları';

    protected static ?string $modelLabel = 'Gecikme Raporu';

    protected static ?string $navigationGroup = 'Yönetim';

    public static function canViewAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        if (! QuerySafety::shouldApplyFilters($query)) {
            return $query;
        }

        return ReportDirectorateScope::constrain($query);
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
                    ->getStateUsing(fn ($record) => $record->yil.' / '.$record->ay),

                TextColumn::make('geciken_ozeti')
                    ->label('Üst yönetim / Gecikme / Sapma')
                    ->html()
                    ->getStateUsing(function ($record) {
                        $isler = is_string($record->faaliyetler) ? json_decode($record->faaliyetler, true) : $record->faaliyetler;
                        $output = '';
                        if (is_array($isler)) {
                            foreach ($isler as $is) {
                                if (! is_array($is)) {
                                    continue;
                                }
                                $line = AylikFaaliyetEscalation::describeItemForManagement($is);
                                if ($line !== null) {
                                    $output .= '• '.e($line).'<br>';
                                }
                            }
                        }

                        return $output !== '' ? $output : '<span class="text-gray-400">Bildirim gerektiren satır yok</span>';
                    }),
            ])
            ->filters([
                // MÜDÜRLÜK SEÇME FİLTRESİ
                SelectFilter::make('user_id')
                    ->label('Müdürlük Seç')
                    ->relationship(
                        name: 'user',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query->onlyMudurlukReportingAccounts(),
                    )
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
                TextEntry::make('user.name')
                    ->label('Müdürlük')
                    ->formatStateUsing(fn ($state): string => static::normalizeInfolistTextState($state)),
                TextEntry::make('geciken_detaylari')
                    ->label('Üst yönetim / Gecikme / Sapma')
                    ->html()
                    ->getStateUsing(function ($record) {
                        $isler = is_string($record->faaliyetler) ? json_decode($record->faaliyetler, true) : $record->faaliyetler;
                        $liste = '';
                        if (is_array($isler)) {
                            foreach ($isler as $is) {
                                if (! is_array($is)) {
                                    continue;
                                }
                                $line = AylikFaaliyetEscalation::describeItemForManagement($is);
                                if ($line !== null) {
                                    $liste .= '<div style="margin-bottom:10px; border-bottom:1px solid #eee;">'.e($line).'</div>';
                                }
                            }
                        }

                        return $liste !== '' ? $liste : 'Bildirim gerektiren satır yok.';
                    })->columnSpanFull(),
            ]),
        ]);
    }

    private static function normalizeInfolistTextState(mixed $state): string
    {
        if (is_array($state)) {
            $items = [];

            foreach ($state as $item) {
                if ($item === null) {
                    continue;
                }

                if (is_scalar($item)) {
                    $text = trim((string) $item);
                    if ($text !== '') {
                        $items[] = $text;
                    }

                    continue;
                }

                if (is_array($item)) {
                    $json = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if (is_string($json) && $json !== '') {
                        $items[] = $json;
                    }

                    continue;
                }

                if (is_object($item) && method_exists($item, '__toString')) {
                    $text = trim((string) $item);
                    if ($text !== '') {
                        $items[] = $text;
                    }
                }
            }

            if ($items === []) {
                return '—';
            }

            return implode(', ', array_slice($items, 0, 20));
        }

        if ($state === null) {
            return '—';
        }

        $text = trim((string) $state);

        return $text === '' ? '—' : $text;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGecikmeRaporus::route('/'),
        ];
    }
}
