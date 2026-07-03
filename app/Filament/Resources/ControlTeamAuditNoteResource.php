<?php

namespace App\Filament\Resources;

use App\Filament\Forms\AnalizEkibiRaporForm;
use App\Filament\Resources\ControlTeamAuditNoteResource\Pages;
use App\Models\ActivityCatalog;
use App\Models\AylikFaaliyet;
use App\Models\ControlTeamAuditNote;
use App\Models\User;
use App\Support\ActivityCatalogFormatter;
use App\Support\QuerySafety;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ControlTeamAuditNoteResource extends Resource
{
    protected static ?string $model = ControlTeamAuditNote::class;

    protected static ?string $navigationLabel = 'Analiz Notları';

    protected static ?string $pluralModelLabel = 'Analiz Notları';

    protected static ?string $modelLabel = 'Analiz Notu';

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('directorate_user_id')
                    ->label('Koordiansyon Notu Eklenecek Birim')
                    ->options(function (): array {
                        $u = auth()->user();
                        if (! $u instanceof User) {
                            return [];
                        }

                        if ($u->isReportingSuperAdmin()) {
                            return User::queryMudurlukReportingAccounts()
                                ->pluck('name', 'id')
                                ->all();
                        }

                        if ($u->isControlTeam()) {
                            return $u->assignedDirectorates()
                                ->orderBy('name')
                                ->pluck('name', 'users.id')
                                ->all();
                        }

                        return [];
                    })
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                        if ((int) $state > 0) {
                            $latest = static::latestReportPeriodForDirectorate((int) $state);
                            $set('yil', $latest['yil']);
                            $set('ay', $latest['ay']);
                            $set('activity_catalog_id', null);
                        }
                        AnalizEkibiRaporForm::applyPrefill($set, $get);
                    }),
                Section::make('Rapor dönemi')
                    ->description('Önce içinde bulunulan ay varsayılır; faaliyet listesi yalnızca bu dönemdeki aylık rapor satırlarından gelir.')
                    ->schema([
                        Forms\Components\Select::make('yil')
                            ->label('Yıl')
                            ->options(fn (): array => static::reportYearOptions())
                            ->default(now()->year)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                $set('activity_catalog_id', null);
                                AnalizEkibiRaporForm::applyPrefill($set, $get);
                            }),
                        Forms\Components\Select::make('ay')
                            ->label('Ay')
                            ->options([
                                '01' => 'Ocak', '02' => 'Şubat', '03' => 'Mart', '04' => 'Nisan',
                                '05' => 'Mayıs', '06' => 'Haziran', '07' => 'Temmuz', '08' => 'Ağustos',
                                '09' => 'Eylül', '10' => 'Ekim', '11' => 'Kasım', '12' => 'Aralık',
                            ])
                            ->default(now()->format('m'))
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                $set('activity_catalog_id', null);
                                AnalizEkibiRaporForm::applyPrefill($set, $get);
                            }),
                    ])
                    ->columns(2),
                Forms\Components\Select::make('activity_catalog_id')
                    ->label('İlgili Faaliyet')
                    ->options(fn (Get $get, ?ControlTeamAuditNote $record): array => static::activitySelectOptions($get, $record))
                    ->searchable()
                    ->live()
                    ->helperText(function (Get $get, ?ControlTeamAuditNote $record): ?string {
                        if ((int) ($get('directorate_user_id') ?? 0) <= 0) {
                            return null;
                        }

                        return static::activitySelectOptions($get, $record) === []
                            ? 'Bu müdürlük ve dönem için aylık raporda faaliyet satırı yok; önce ilgili aylık raporu girin veya dönemi değiştirin.'
                            : null;
                    })
                    ->getOptionLabelUsing(fn ($value) => ActivityCatalogFormatter::labelForCatalogId((int) $value))
                    ->required()
                    ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                        AnalizEkibiRaporForm::applyPrefill($set, $get);
                    }),
                Section::make('Müdürlük / faaliyet performans özeti')
                    ->description('Seçilen müdürlük ve dönemin aylık rapor verilerinden otomatik gelir. Faaliyet seçildiğinde ilgili satır verileri kullanılır.')
                    ->schema([
                        Forms\Components\Placeholder::make('mudurluk_ozet_baslik')
                            ->label('Kapsam')
                            ->content(function (Get $get): string {
                                $activityId = (int) ($get('activity_catalog_id') ?? 0);

                                return $activityId > 0
                                    ? 'Seçili faaliyet'
                                    : 'Müdürlük geneli (tüm faaliyetler)';
                            }),
                        Forms\Components\Placeholder::make('ozet_yapilan')
                            ->label('Yapılan İş')
                            ->content(function (Get $get): string {
                                $activityId = (int) ($get('activity_catalog_id') ?? 0);
                                $summary = $activityId > 0
                                    ? static::activityProgressSummary($get)
                                    : static::mudurlukPeriodSummary($get);

                                return (string) (int) ($summary['gerceklesen'] ?? 0);
                            }),
                        Forms\Components\Placeholder::make('ozet_acikta')
                            ->label('Açıkta Bekleyen')
                            ->content(function (Get $get): string {
                                $activityId = (int) ($get('activity_catalog_id') ?? 0);
                                $summary = $activityId > 0
                                    ? static::activityProgressSummary($get)
                                    : static::mudurlukPeriodSummary($get);

                                return (string) (int) ($summary['kalan'] ?? 0);
                            }),
                        Forms\Components\Placeholder::make('ozet_toplam')
                            ->label('Toplam İş')
                            ->content(function (Get $get): string {
                                $activityId = (int) ($get('activity_catalog_id') ?? 0);
                                $summary = $activityId > 0
                                    ? static::activityProgressSummary($get)
                                    : static::mudurlukPeriodSummary($get);
                                $hedef = (int) ($summary['hedef'] ?? 0);
                                $gerceklesen = (int) ($summary['gerceklesen'] ?? 0);
                                $kalan = (int) ($summary['kalan'] ?? 0);
                                $toplam = $hedef > 0 ? $hedef : ($gerceklesen + $kalan);

                                return (string) $toplam;
                            }),
                        Forms\Components\Placeholder::make('ozet_tamamlanma')
                            ->label('Tamamlanma Oranı (%)')
                            ->content(function (Get $get): string {
                                $activityId = (int) ($get('activity_catalog_id') ?? 0);
                                $summary = $activityId > 0
                                    ? static::activityProgressSummary($get)
                                    : static::mudurlukPeriodSummary($get);
                                $gerceklesen = (int) ($summary['gerceklesen'] ?? 0);
                                $hedef = (int) ($summary['hedef'] ?? 0);
                                $kalan = (int) ($summary['kalan'] ?? 0);
                                $toplam = $hedef > 0 ? $hedef : ($gerceklesen + $kalan);
                                $pct = \App\Support\AnalizEkibiRaporVerileri::completionPercent($gerceklesen, $toplam);

                                return $pct === null ? '—' : '%'.$pct;
                            }),
                    ])
                    ->columns(3)
                    ->visible(fn (Get $get): bool => (int) ($get('directorate_user_id') ?? 0) > 0),

                ...AnalizEkibiRaporForm::schema(),

                Forms\Components\Textarea::make('note')
                    ->label('Analiz Notu ve Bulgular')
                    ->rows(6)
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\DatePicker::make('audit_date')
                    ->label('Analiz Tarihi')
                    ->default(now()->toDateString())
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('directorate.name')
                    ->label('Analiz Birim')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rapor_donemi')
                    ->label('Rapor dönemi')
                    ->getStateUsing(function (ControlTeamAuditNote $record): string {
                        if ($record->yil === null || $record->ay === null || $record->ay === '') {
                            return '—';
                        }

                        return (string) $record->yil.' / '.$record->ay;
                    }),
                Tables\Columns\TextColumn::make('activity_catalog_id')
                    ->label('İlgili Faaliyet')
                    ->wrap()
                    ->formatStateUsing(fn ($state) => ActivityCatalogFormatter::labelForCatalogId((int) $state) ?? '—'),
                Tables\Columns\TextColumn::make('audit_date')
                    ->label('Tarih')
                    ->date('d.m.Y'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Analiz Ekibi')
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Analiz Notunu Görüntüle'),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Rapor Bilgileri')
                ->schema([
                    TextEntry::make('directorate.name')->label('Müdürlük'),
                    TextEntry::make('user.name')->label('Analiz Ekibi'),
                    TextEntry::make('rapor_donemi')
                        ->label('Rapor Dönemi')
                        ->getStateUsing(function (ControlTeamAuditNote $record): string {
                            if ($record->yil === null || $record->ay === null || $record->ay === '') {
                                return '—';
                            }

                            return (string) $record->yil.' / '.$record->ay;
                        }),
                    TextEntry::make('activity_catalog_id')
                        ->label('İlgili Faaliyet')
                        ->getStateUsing(fn (ControlTeamAuditNote $record): string => ActivityCatalogFormatter::labelForCatalogId((int) $record->activity_catalog_id) ?? '—'),
                    TextEntry::make('audit_date')
                        ->label('Analiz Tarihi')
                        ->date('d.m.Y'),
                ])
                ->columns(2),

            InfolistSection::make('Özet Göstergeler')
                ->schema([
                    TextEntry::make('rapor_verileri.ozet.yapilan_is')->label('Yapılan İş'),
                    TextEntry::make('rapor_verileri.ozet.acikta_bekleyen')->label('Açıkta Bekleyen'),
                    TextEntry::make('rapor_verileri.ozet.tamamlanma_orani')->label('Tamamlanma (%)')->suffix('%'),
                    TextEntry::make('rapor_verileri.ozet.revize_karar')->label('Revize + Karar'),
                    TextEntry::make('rapor_verileri.ozet.gecen_ay_fark')->label('Geçen Ay Fark'),
                    TextEntry::make('rapor_verileri.ozet.kritik_kalem_notu')->label('Kritik Kalem Notu')->columnSpanFull(),
                ])
                ->columns(3),

            InfolistSection::make('Kalem Kalem Analiz')
                ->schema([
                    RepeatableEntry::make('rapor_verileri.kalem_analizi')
                        ->label('')
                        ->schema([
                            TextEntry::make('kalem')->label('Kalem'),
                            TextEntry::make('gerceklesen')->label('Gerçekleşen'),
                            TextEntry::make('acikta')->label('Açıkta'),
                            TextEntry::make('durum')->label('Durum')->badge(),
                            TextEntry::make('sapma_not')->label('Sapma / Not')->columnSpanFull(),
                            TextEntry::make('son_tarih')->label('Son Tarih'),
                        ])
                        ->columns(3),
                ]),

            InfolistSection::make('Öncelikli Aksiyonlar')
                ->schema([
                    RepeatableEntry::make('rapor_verileri.aksiyonlar')
                        ->label('')
                        ->schema([
                            TextEntry::make('oncelik')->label('Öncelik'),
                            TextEntry::make('aksiyon')->label('Aksiyon')->columnSpanFull(),
                            TextEntry::make('kalem')->label('Kalem'),
                            TextEntry::make('sorumlu')->label('Sorumlu'),
                            TextEntry::make('hedef_tarih')->label('Hedef Tarih'),
                            TextEntry::make('durum')->label('Durum'),
                        ])
                        ->columns(3),
                ]),

            InfolistSection::make('Olgunluk Göstergeleri')
                ->schema([
                    TextEntry::make('rapor_verileri.olgunluk.veri_kalitesi.deger')->label('Veri Kalitesi (%)')->suffix('%'),
                    TextEntry::make('rapor_verileri.olgunluk.zamaninda_kapanis.deger')->label('Zamanında Kapanış (%)')->suffix('%'),
                    TextEntry::make('rapor_verileri.olgunluk.risk_yonetimi.deger')->label('Risk Yönetimi (%)')->suffix('%'),
                    TextEntry::make('rapor_verileri.olgunluk.aksiyon_kapanis.deger')->label('Aksiyon Kapanış (%)')->suffix('%'),
                ])
                ->columns(4),

            InfolistSection::make('Risk Isı Haritası')
                ->schema([
                    RepeatableEntry::make('rapor_verileri.risk_haritasi')
                        ->label('')
                        ->schema([
                            TextEntry::make('kalem')->label('Kalem'),
                            TextEntry::make('seviye')->label('Seviye')->badge(),
                            TextEntry::make('aciklama')->label('Açıklama')->columnSpanFull(),
                        ])
                        ->columns(2),
                ]),

            InfolistSection::make('Analiz Notu ve Bulgular')
                ->schema([
                    TextEntry::make('note')
                        ->label('')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        if (! QuerySafety::shouldApplyFilters($query)) {
            return $query;
        }

        $u = auth()->user();
        if (! $u instanceof User) {
            return $query->whereRaw('0=1');
        }

        if ($u->isReportingSuperAdmin()) {
            return $query;
        }

        if ($u->isControlTeam()) {
            $allowedDirectorateIds = $u->assignedDirectorates()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
            if ($allowedDirectorateIds === []) {
                return $query->whereRaw('0=1');
            }

            return $query
                ->whereIn('directorate_user_id', $allowedDirectorateIds)
                ->where('user_id', $u->id);
        }

        if ($u->isViceMayorAccount()) {
            $audience = $u->reportAudienceUserIds();
            if ($audience === null) {
                return $query;
            }
            if ($audience === []) {
                return $query->whereRaw('0=1');
            }

            return $query->whereIn('directorate_user_id', $audience);
        }

        return $query->whereRaw('0=1');
    }

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return $u instanceof User && ($u->isReportingSuperAdmin() || $u->isControlTeam() || $u->isViceMayorAccount());
    }

    public static function canCreate(): bool
    {
        $u = auth()->user();

        return $u instanceof User && ($u->isReportingSuperAdmin() || $u->isControlTeam());
    }

    public static function canView(Model $record): bool
    {
        return static::getEloquentQuery()->whereKey($record->getKey())->exists();
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListControlTeamAuditNotes::route('/'),
            'create' => Pages\CreateControlTeamAuditNote::route('/create'),
            'view' => Pages\ViewControlTeamAuditNote::route('/{record}'),
        ];
    }

    /**
     * Aylık rapor kaydı: önce seçilen yıl/ay (ay değeri DB'de "04" veya "4" olabilir), yoksa takvim sırasına göre en güncel dönem.
     */
    public static function resolveAylikFaaliyetForDirectoratePeriod(int $directorateUserId, int $yil, string $ayRaw): ?AylikFaaliyet
    {
        if ($directorateUserId <= 0) {
            return null;
        }

        $ayNorm = str_pad(preg_replace('/\D/', '', $ayRaw) ?: '', 2, '0', STR_PAD_LEFT);

        if ($yil > 0 && strlen($ayNorm) === 2) {
            $variants = static::normalizeAyQueryVariants($ayNorm);
            $exact = AylikFaaliyet::query()
                ->where('user_id', $directorateUserId)
                ->where('yil', $yil)
                ->whereIn('ay', $variants)
                ->first();
            if ($exact instanceof AylikFaaliyet) {
                return $exact;
            }
        }

        return static::latestAylikFaaliyetForDirectorateUser($directorateUserId);
    }

    /**
     * Seçilen müdürlük ve rapor ayındaki aylık faaliyet satırlarına göre katalog seçenekleri (tam katalog değil).
     *
     * @return array<int, string>
     */
    protected static function activityOptionsForDirectoratePeriod(int $directorateUserId, mixed $yil, mixed $ay): array
    {
        if ($directorateUserId <= 0 || $yil === null || $yil === '' || $ay === null || $ay === '') {
            return [];
        }

        $yilInt = (int) $yil;
        $ayNorm = str_pad(preg_replace('/\D/', '', (string) $ay) ?: '', 2, '0', STR_PAD_LEFT);
        if (strlen($ayNorm) !== 2) {
            return [];
        }

        $variants = static::normalizeAyQueryVariants($ayNorm);

        $rapor = AylikFaaliyet::query()
            ->where('user_id', $directorateUserId)
            ->where('yil', $yilInt)
            ->whereIn('ay', $variants)
            ->first();

        if (! $rapor) {
            $rapor = static::latestAylikFaaliyetForDirectorateUser($directorateUserId);
        }

        if (! $rapor) {
            return [];
        }

        $rows = static::faaliyetlerRowsWithHydratedCatalogIds($rapor, $directorateUserId);
        if ($rows === []) {
            return [];
        }

        $ids = [];
        $codes = [];
        foreach ($rows as $satir) {
            if (! is_array($satir)) {
                continue;
            }
            $cid = (int) ($satir['activity_catalog_id'] ?? 0);
            if ($cid > 0) {
                $ids[$cid] = true;
            }
            $kod = trim((string) ($satir['faaliyet_kodu'] ?? ''));
            if ($kod !== '') {
                $codes[$kod] = true;
            }
        }

        if ($codes !== []) {
            $matchedByCode = ActivityCatalog::query()
                ->whereIn('faaliyet_kodu', array_keys($codes))
                ->get(['id'])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)
                ->all();
            foreach ($matchedByCode as $id) {
                $ids[$id] = true;
            }
        }

        $options = [];
        foreach (array_keys($ids) as $id) {
            $label = ActivityCatalogFormatter::labelForCatalogId($id);
            if ($label !== null) {
                $options[$id] = $label;
            }
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    protected static function activitySelectOptions(Get $get, ?ControlTeamAuditNote $record): array
    {
        $opts = static::activityOptionsForDirectoratePeriod(
            (int) ($get('directorate_user_id') ?? 0),
            $get('yil'),
            $get('ay')
        );

        $savedId = $record ? (int) $record->activity_catalog_id : 0;
        if ($savedId > 0 && ! array_key_exists($savedId, $opts)) {
            $label = ActivityCatalogFormatter::labelForCatalogId($savedId);
            if ($label !== null) {
                $opts[$savedId] = $label;
            }
        }

        natcasesort($opts);

        return $opts;
    }

    /**
     * @return array<int, string>
     */
    protected static function reportYearOptions(): array
    {
        $years = AylikFaaliyet::query()
            ->select('yil')
            ->whereNotNull('yil')
            ->distinct()
            ->orderBy('yil')
            ->pluck('yil')
            ->map(fn ($y) => (int) $y)
            ->filter(fn (int $y) => $y > 0)
            ->all();

        $years[] = (int) now()->year;
        $years = array_values(array_unique($years));
        sort($years);

        $options = [];
        foreach ($years as $year) {
            $options[$year] = (string) $year;
        }

        return $options;
    }

    /**
     * @return array{yil:int, ay:string}
     */
    protected static function latestReportPeriodForDirectorate(int $directorateUserId): array
    {
        $fallback = ['yil' => (int) now()->year, 'ay' => now()->format('m')];
        if ($directorateUserId <= 0) {
            return $fallback;
        }

        $rapor = static::latestAylikFaaliyetForDirectorateUser($directorateUserId);

        if (! $rapor) {
            return $fallback;
        }

        return [
            'yil' => (int) ($rapor->yil ?: $fallback['yil']),
            'ay' => str_pad((string) ($rapor->ay ?: $fallback['ay']), 2, '0', STR_PAD_LEFT),
        ];
    }

    /**
     * @return array{hedef:int, gerceklesen:int, kalan:int}
     */
    public static function activityProgressSummary(Get $get): array
    {
        $directorateUserId = (int) ($get('directorate_user_id') ?? 0);
        $activityCatalogId = (int) ($get('activity_catalog_id') ?? 0);
        $yil = $get('yil');
        $ay = $get('ay');

        if ($directorateUserId <= 0 || $activityCatalogId <= 0 || blank($yil) || blank($ay)) {
            return ['hedef' => 0, 'gerceklesen' => 0, 'kalan' => 0];
        }

        $yilInt = (int) $yil;
        $ayNorm = str_pad(preg_replace('/\D/', '', (string) $ay) ?: '', 2, '0', STR_PAD_LEFT);
        if (strlen($ayNorm) !== 2) {
            return ['hedef' => 0, 'gerceklesen' => 0, 'kalan' => 0];
        }

        $variants = static::normalizeAyQueryVariants($ayNorm);

        $rapor = AylikFaaliyet::query()
            ->where('user_id', $directorateUserId)
            ->where('yil', $yilInt)
            ->whereIn('ay', $variants)
            ->first();

        if (! $rapor) {
            $rapor = static::latestAylikFaaliyetForDirectorateUser($directorateUserId);
        }

        if (! $rapor) {
            return ['hedef' => 0, 'gerceklesen' => 0, 'kalan' => 0];
        }

        $faaliyetler = static::faaliyetlerRowsWithHydratedCatalogIds($rapor, $directorateUserId);
        if ($faaliyetler === []) {
            return ['hedef' => 0, 'gerceklesen' => 0, 'kalan' => 0];
        }

        $selectedCatalog = ActivityCatalog::query()
            ->find($activityCatalogId, ['id', 'faaliyet_kodu']);
        $selectedCode = trim((string) ($selectedCatalog?->faaliyet_kodu ?? ''));

        $hedef = 0;
        $gerceklesen = 0;
        $bekleyen = 0;

        foreach ($faaliyetler as $satir) {
            if (! is_array($satir)) {
                continue;
            }

            $rowCatalogId = (int) ($satir['activity_catalog_id'] ?? 0);
            $rowCode = trim((string) ($satir['faaliyet_kodu'] ?? ''));
            $matchesById = $rowCatalogId > 0 && $rowCatalogId === $activityCatalogId;
            $matchesByCode = $selectedCode !== '' && $rowCode !== '' && strcasecmp($rowCode, $selectedCode) === 0;
            if (! $matchesById && ! $matchesByCode) {
                continue;
            }

            $hedef += (int) ($satir['hedef'] ?? 0);
            $gerceklesen += (int) ($satir['gerceklesen'] ?? 0);
            $bekleyen += (int) ($satir['bekleyen_is'] ?? 0);
        }

        if ($hedef === 0 && $gerceklesen > 0) {
            $kalan = max(0, $bekleyen);
        } else {
            $kalan = max(0, $hedef - $gerceklesen);
            if ($bekleyen > 0) {
                $kalan = max($kalan, $bekleyen);
            }
        }

        return [
            'hedef' => $hedef,
            'gerceklesen' => $gerceklesen,
            'kalan' => $kalan,
        ];
    }

    /**
     * Müdürlük + dönem için tüm faaliyet satırlarının toplam performansı.
     *
     * @return array{hedef:int, gerceklesen:int, kalan:int, revize_karar:int, kritik_kalem_notu:string}
     */
    public static function mudurlukPeriodSummary(Get $get): array
    {
        $directorateUserId = (int) ($get('directorate_user_id') ?? 0);
        $yil = $get('yil');
        $ay = $get('ay');

        if ($directorateUserId <= 0 || blank($yil) || blank($ay)) {
            return ['hedef' => 0, 'gerceklesen' => 0, 'kalan' => 0, 'revize_karar' => 0, 'kritik_kalem_notu' => ''];
        }

        $rapor = static::resolveAylikFaaliyetForDirectoratePeriod(
            $directorateUserId,
            (int) $yil,
            (string) $ay
        );

        if (! $rapor) {
            return ['hedef' => 0, 'gerceklesen' => 0, 'kalan' => 0, 'revize_karar' => 0, 'kritik_kalem_notu' => ''];
        }

        $faaliyetler = static::faaliyetlerRowsWithHydratedCatalogIds($rapor, $directorateUserId);
        $hedef = 0;
        $gerceklesen = 0;
        $bekleyen = 0;
        $revizeKarar = 0;
        $allKalemler = [];

        foreach ($faaliyetler as $satir) {
            if (! is_array($satir)) {
                continue;
            }
            $hedef += (int) ($satir['hedef'] ?? 0);
            $gerceklesen += (int) ($satir['gerceklesen'] ?? 0);
            $bekleyen += (int) ($satir['bekleyen_is'] ?? 0);
            if ((bool) ($satir['gerekli_revize'] ?? false)) {
                $revizeKarar++;
            }
            if (filled($satir['karar_ihtiyaci'] ?? null)) {
                $revizeKarar++;
            }
            foreach (\App\Support\AnalizEkibiRaporVerileri::buildKalemAnalizi($satir) as $kalem) {
                $allKalemler[] = $kalem;
            }
        }

        if ($hedef === 0 && $gerceklesen > 0) {
            $kalan = max(0, $bekleyen);
        } else {
            $kalan = max(0, $hedef - $gerceklesen);
            if ($bekleyen > 0) {
                $kalan = max($kalan, $bekleyen);
            }
        }

        return [
            'hedef' => $hedef,
            'gerceklesen' => $gerceklesen,
            'kalan' => $kalan,
            'revize_karar' => $revizeKarar,
            'kritik_kalem_notu' => \App\Support\AnalizEkibiRaporVerileri::computeKritikKalemNotu($allKalemler),
        ];
    }

    public static function activityGecenAyFark(Get $get): ?int
    {
        $directorateUserId = (int) ($get('directorate_user_id') ?? 0);
        $activityCatalogId = (int) ($get('activity_catalog_id') ?? 0);
        $yil = (int) ($get('yil') ?? 0);
        $ayNorm = str_pad(preg_replace('/\D/', '', (string) ($get('ay') ?? '')) ?: '', 2, '0', STR_PAD_LEFT);

        if ($directorateUserId <= 0 || $activityCatalogId <= 0 || $yil <= 0 || strlen($ayNorm) !== 2) {
            return null;
        }

        $current = static::activityProgressSummary($get)['gerceklesen'] ?? 0;
        $prev = static::previousPeriod($yil, $ayNorm);
        $prevSummary = static::activityProgressSummaryForPeriod(
            $directorateUserId,
            $prev['yil'],
            $prev['ay'],
            $activityCatalogId
        );

        return (int) $current - (int) ($prevSummary['gerceklesen'] ?? 0);
    }

    public static function mudurlukGecenAyFark(Get $get): ?int
    {
        $directorateUserId = (int) ($get('directorate_user_id') ?? 0);
        $yil = (int) ($get('yil') ?? 0);
        $ayNorm = str_pad(preg_replace('/\D/', '', (string) ($get('ay') ?? '')) ?: '', 2, '0', STR_PAD_LEFT);

        if ($directorateUserId <= 0 || $yil <= 0 || strlen($ayNorm) !== 2) {
            return null;
        }

        $current = static::mudurlukPeriodSummary($get)['gerceklesen'] ?? 0;
        $prev = static::previousPeriod($yil, $ayNorm);
        $prevSummary = static::mudurlukPeriodSummaryForPeriod($directorateUserId, $prev['yil'], $prev['ay']);

        return (int) $current - (int) ($prevSummary['gerceklesen'] ?? 0);
    }

    /**
     * @return array{yil:int, ay:string}
     */
    public static function previousPeriod(int $yil, string $ayNorm): array
    {
        $month = (int) $ayNorm;
        if ($month <= 1) {
            return ['yil' => $yil - 1, 'ay' => '12'];
        }

        return [
            'yil' => $yil,
            'ay' => str_pad((string) ($month - 1), 2, '0', STR_PAD_LEFT),
        ];
    }

    /**
     * @return array{hedef:int, gerceklesen:int, kalan:int}
     */
    public static function activityProgressSummaryForPeriod(
        int $directorateUserId,
        int $yil,
        string $ayRaw,
        int $activityCatalogId
    ): array {
        if ($directorateUserId <= 0 || $activityCatalogId <= 0 || $yil <= 0) {
            return ['hedef' => 0, 'gerceklesen' => 0, 'kalan' => 0];
        }

        $rapor = static::resolveAylikFaaliyetForDirectoratePeriod($directorateUserId, $yil, $ayRaw);
        if (! $rapor) {
            return ['hedef' => 0, 'gerceklesen' => 0, 'kalan' => 0];
        }

        $faaliyetler = static::faaliyetlerRowsWithHydratedCatalogIds($rapor, $directorateUserId);
        $selectedCatalog = ActivityCatalog::query()->find($activityCatalogId, ['id', 'faaliyet_kodu']);
        $selectedCode = trim((string) ($selectedCatalog?->faaliyet_kodu ?? ''));

        $hedef = 0;
        $gerceklesen = 0;
        $bekleyen = 0;

        foreach ($faaliyetler as $satir) {
            if (! is_array($satir)) {
                continue;
            }
            $rowCatalogId = (int) ($satir['activity_catalog_id'] ?? 0);
            $rowCode = trim((string) ($satir['faaliyet_kodu'] ?? ''));
            $matchesById = $rowCatalogId > 0 && $rowCatalogId === $activityCatalogId;
            $matchesByCode = $selectedCode !== '' && $rowCode !== '' && strcasecmp($rowCode, $selectedCode) === 0;
            if (! $matchesById && ! $matchesByCode) {
                continue;
            }
            $hedef += (int) ($satir['hedef'] ?? 0);
            $gerceklesen += (int) ($satir['gerceklesen'] ?? 0);
            $bekleyen += (int) ($satir['bekleyen_is'] ?? 0);
        }

        if ($hedef === 0 && $gerceklesen > 0) {
            $kalan = max(0, $bekleyen);
        } else {
            $kalan = max(0, $hedef - $gerceklesen);
            if ($bekleyen > 0) {
                $kalan = max($kalan, $bekleyen);
            }
        }

        return ['hedef' => $hedef, 'gerceklesen' => $gerceklesen, 'kalan' => $kalan];
    }

    /**
     * @return array{hedef:int, gerceklesen:int, kalan:int, revize_karar:int, kritik_kalem_notu:string}
     */
    public static function mudurlukPeriodSummaryForPeriod(int $directorateUserId, int $yil, string $ayRaw): array
    {
        $rapor = static::resolveAylikFaaliyetForDirectoratePeriod($directorateUserId, $yil, $ayRaw);
        if (! $rapor) {
            return ['hedef' => 0, 'gerceklesen' => 0, 'kalan' => 0, 'revize_karar' => 0, 'kritik_kalem_notu' => ''];
        }

        $faaliyetler = static::faaliyetlerRowsWithHydratedCatalogIds($rapor, $directorateUserId);
        $hedef = 0;
        $gerceklesen = 0;
        $bekleyen = 0;
        $revizeKarar = 0;

        foreach ($faaliyetler as $satir) {
            if (! is_array($satir)) {
                continue;
            }
            $hedef += (int) ($satir['hedef'] ?? 0);
            $gerceklesen += (int) ($satir['gerceklesen'] ?? 0);
            $bekleyen += (int) ($satir['bekleyen_is'] ?? 0);
            if ((bool) ($satir['gerekli_revize'] ?? false)) {
                $revizeKarar++;
            }
            if (filled($satir['karar_ihtiyaci'] ?? null)) {
                $revizeKarar++;
            }
        }

        if ($hedef === 0 && $gerceklesen > 0) {
            $kalan = max(0, $bekleyen);
        } else {
            $kalan = max(0, $hedef - $gerceklesen);
            if ($bekleyen > 0) {
                $kalan = max($kalan, $bekleyen);
            }
        }

        return [
            'hedef' => $hedef,
            'gerceklesen' => $gerceklesen,
            'kalan' => $kalan,
            'revize_karar' => $revizeKarar,
            'kritik_kalem_notu' => '',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function activityMatchedFaaliyetRow(Get $get): ?array
    {
        $directorateUserId = (int) ($get('directorate_user_id') ?? 0);
        $activityCatalogId = (int) ($get('activity_catalog_id') ?? 0);
        $yil = $get('yil');
        $ay = $get('ay');

        if ($directorateUserId <= 0 || $activityCatalogId <= 0 || blank($yil) || blank($ay)) {
            return null;
        }

        $yilInt = (int) $yil;
        $ayNorm = str_pad(preg_replace('/\D/', '', (string) $ay) ?: '', 2, '0', STR_PAD_LEFT);
        if (strlen($ayNorm) !== 2) {
            return null;
        }

        $rapor = AylikFaaliyet::query()
            ->where('user_id', $directorateUserId)
            ->where('yil', $yilInt)
            ->whereIn('ay', static::normalizeAyQueryVariants($ayNorm))
            ->first();

        if (! $rapor) {
            $rapor = static::latestAylikFaaliyetForDirectorateUser($directorateUserId);
        }

        if (! $rapor) {
            return null;
        }

        $faaliyetler = static::faaliyetlerRowsWithHydratedCatalogIds($rapor, $directorateUserId);
        $selectedCatalog = ActivityCatalog::query()->find($activityCatalogId, ['id', 'faaliyet_kodu']);
        $selectedCode = trim((string) ($selectedCatalog?->faaliyet_kodu ?? ''));

        foreach ($faaliyetler as $satir) {
            if (! is_array($satir)) {
                continue;
            }
            $rowCatalogId = (int) ($satir['activity_catalog_id'] ?? 0);
            $rowCode = trim((string) ($satir['faaliyet_kodu'] ?? ''));
            $matchesById = $rowCatalogId > 0 && $rowCatalogId === $activityCatalogId;
            $matchesByCode = $selectedCode !== '' && $rowCode !== '' && strcasecmp($rowCode, $selectedCode) === 0;
            if ($matchesById || $matchesByCode) {
                return $satir;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function normalizeAyQueryVariants(string $ayNorm): array
    {
        if (strlen($ayNorm) !== 2) {
            return [$ayNorm];
        }

        $unpadded = (string) (int) $ayNorm;

        return array_values(array_unique([$ayNorm, $unpadded]));
    }

    private static function reportPeriodSortKey(AylikFaaliyet $r): int
    {
        $y = (int) ($r->yil ?? 0);
        $m = (int) (preg_replace('/\D/', '', (string) ($r->ay ?? '')) ?: 0);

        return $y * 100 + $m;
    }

    private static function latestAylikFaaliyetForDirectorateUser(int $directorateUserId): ?AylikFaaliyet
    {
        if ($directorateUserId <= 0) {
            return null;
        }

        $candidates = AylikFaaliyet::query()
            ->where('user_id', $directorateUserId)
            ->get()
            ->sortByDesc(fn (AylikFaaliyet $r): int => static::reportPeriodSortKey($r))
            ->values();

        foreach ($candidates as $r) {
            $rows = static::normalizeFaaliyetlerRows($r->faaliyetler);
            if ($rows === []) {
                continue;
            }
            foreach ($rows as $satir) {
                if (! is_array($satir)) {
                    continue;
                }
                if ((int) ($satir['activity_catalog_id'] ?? 0) > 0) {
                    return $r;
                }
                if (trim((string) ($satir['faaliyet_kodu'] ?? '')) !== '') {
                    return $r;
                }
            }
        }

        return $candidates->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function faaliyetlerRowsWithHydratedCatalogIds(AylikFaaliyet $rapor, int $directorateUserId): array
    {
        $rows = static::normalizeFaaliyetlerRows($rapor->faaliyetler);
        if ($rows === []) {
            return [];
        }

        $mudurlukAdi = null;
        if ($directorateUserId > 0) {
            $dir = User::query()->find($directorateUserId);
            if ($dir !== null && filled($dir->name)) {
                $mudurlukAdi = trim((string) $dir->name);
            }
        }

        $hydrated = ActivityCatalogFormatter::hydrateActivityCatalogIdsInFaaliyetler(
            ['faaliyetler' => $rows],
            $mudurlukAdi
        );

        $out = $hydrated['faaliyetler'] ?? [];

        return is_array($out) ? $out : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function normalizeFaaliyetlerRows(mixed $value): array
    {
        if ($value instanceof \Illuminate\Contracts\Support\Arrayable) {
            $value = $value->toArray();
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
