<?php

namespace App\Filament\Resources;

use App\Filament\Forms\AnalizEkibiRaporForm;
use App\Filament\Resources\ControlTeamAuditNoteResource\Pages;
use App\Models\ActivityCatalog;
use App\Models\AylikFaaliyet;
use App\Models\ControlTeamAuditNote;
use App\Models\User;
use App\Support\ActivityCatalogFormatter;
use App\Support\AnalizEkibiHaftalikFaaliyetEkrani;
use App\Support\AnalizEkibiHaftalikFaaliyetPdf;
use App\Support\AnalizEkibiYoneticiRapor;
use App\Support\AylikFaaliyetPeriodMerge;
use App\Support\QuerySafety;
use App\Support\ReportPeriodWeeks;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
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
                    ->label('Müdürlük')
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
                            $set('hafta', $latest['hafta']);
                        }
                        static::applySelectedPeriod($set, $get);
                    }),
                Section::make('Rapor dönemi')
                    ->description('Müdürlük, yıl, ay ve haftayı (tarih aralığıyla) seçin. Alttaki ekran o haftanın faaliyet raporunu birebir gösterir.')
                    ->schema([
                        Forms\Components\Select::make('yil')
                            ->label('Yıl')
                            ->options(fn (): array => static::reportYearOptions())
                            ->default(now()->year)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                static::applySelectedPeriod($set, $get);
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
                                static::applySelectedPeriod($set, $get);
                            }),
                        Forms\Components\Select::make('hafta')
                            ->label('Hafta / tarih aralığı')
                            ->options(function (Get $get): array {
                                return static::haftaSelectOptions($get('yil'), $get('ay'));
                            })
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                static::applySelectedPeriod($set, $get, false);
                            }),
                    ])
                    ->columns(3),
                Forms\Components\Hidden::make('activity_catalog_id')
                    ->dehydrated()
                    ->default(null),
                Section::make('Seçilen haftanın faaliyet raporu')
                    ->description('Tüm müdürlükler aynı şablonu kullanır; her kalemin yanında ölçü birimi (m², adet, tutanak, iş vb.) görünür.')
                    ->headerActions([
                        FormAction::make('aylikKarsilastirma')
                            ->label('Aylık karşılaştırma')
                            ->icon('heroicon-m-chart-bar')
                            ->url(function (Get $get): string {
                                return static::aylikKarsilastirmaUrl(
                                    (int) ($get('directorate_user_id') ?? 0),
                                    $get('yil'),
                                    $get('ay')
                                ) ?? '#';
                            })
                            ->visible(function (Get $get): bool {
                                return static::aylikKarsilastirmaUrl(
                                    (int) ($get('directorate_user_id') ?? 0),
                                    $get('yil'),
                                    $get('ay')
                                ) !== null;
                            }),
                    ])
                    ->schema([
                        Forms\Components\Placeholder::make('haftalik_rapor_ekrani')
                            ->label('')
                            ->content(function (Get $get) {
                                return view('filament.forms.analiz-ekibi-haftalik-rapor-ekrani', [
                                    'screen' => AnalizEkibiHaftalikFaaliyetEkrani::fromForm($get),
                                ]);
                            }),
                    ])
                    ->columnSpanFull()
                    ->visible(function (Get $get): bool {
                        $user = auth()->user();
                        if (! $user instanceof User) {
                            return false;
                        }
                        $id = (int) ($get('directorate_user_id') ?? 0);

                        return $id > 0 && AnalizEkibiYoneticiRapor::userCanAccessMudurluk($user, $id);
                    }),

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

                        $ay = str_pad((string) $record->ay, 2, '0', STR_PAD_LEFT);
                        $hafta = $record->hafta;
                        $weekLabel = ReportPeriodWeeks::periodLabelForRecord((int) $record->yil, $ay, $hafta);

                        if ($weekLabel !== null) {
                            return (string) $record->yil.' / '.$ay.' · '.$weekLabel;
                        }

                        return (string) $record->yil.' / '.$ay;
                    }),
                Tables\Columns\TextColumn::make('audit_date')
                    ->label('Tarih')
                    ->date('d.m.Y'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Analiz Ekibi')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('directorate_user_id')
                    ->label('Müdürlük')
                    ->options(function (): array {
                        $u = auth()->user();
                        if (! $u instanceof User) {
                            return [];
                        }

                        return AnalizEkibiYoneticiRapor::mudurlukOptionsForUser($u);
                    })
                    ->searchable(),
                Tables\Filters\SelectFilter::make('yil')
                    ->label('Yıl')
                    ->options(fn (): array => static::reportYearOptions()),
                Tables\Filters\SelectFilter::make('ay')
                    ->label('Ay')
                    ->options([
                        '01' => 'Ocak', '02' => 'Şubat', '03' => 'Mart', '04' => 'Nisan',
                        '05' => 'Mayıs', '06' => 'Haziran', '07' => 'Temmuz', '08' => 'Ağustos',
                        '09' => 'Eylül', '10' => 'Ekim', '11' => 'Kasım', '12' => 'Aralık',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Analiz Notunu Görüntüle'),
                Tables\Actions\Action::make('pdfIndir')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->action(fn (ControlTeamAuditNote $record) => AnalizEkibiHaftalikFaaliyetPdf::downloadForNote($record)),
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

                            $ay = str_pad((string) $record->ay, 2, '0', STR_PAD_LEFT);
                            $weekLabel = ReportPeriodWeeks::periodLabelForRecord((int) $record->yil, $ay, $record->hafta);
                            if ($weekLabel !== null) {
                                return (string) $record->yil.' / '.$ay.' · '.$weekLabel;
                            }

                            return (string) $record->yil.' / '.$ay;
                        }),
                    TextEntry::make('aylik_karsilastirma')
                        ->label('Karşılaştırma')
                        ->getStateUsing(fn (): string => 'Aylık karşılaştırma')
                        ->url(function (ControlTeamAuditNote $record): ?string {
                            return static::aylikKarsilastirmaUrl(
                                (int) $record->directorate_user_id,
                                $record->yil,
                                $record->ay,
                                (int) $record->id
                            );
                        })
                        ->visible(function (ControlTeamAuditNote $record): bool {
                            return static::aylikKarsilastirmaUrl(
                                (int) $record->directorate_user_id,
                                $record->yil,
                                $record->ay
                            ) !== null;
                        }),
                    TextEntry::make('audit_date')
                        ->label('Analiz Tarihi')
                        ->date('d.m.Y'),
                ])
                ->columns(2),

            InfolistSection::make('Seçilen haftanın faaliyet raporu')
                ->schema([
                    TextEntry::make('haftalik_rapor_ekrani')
                        ->hiddenLabel()
                        ->getStateUsing(function (ControlTeamAuditNote $record) {
                            return view('filament.forms.analiz-ekibi-haftalik-rapor-ekrani', [
                                'screen' => AnalizEkibiHaftalikFaaliyetEkrani::fromNote($record),
                            ])->render();
                        })
                        ->html()
                        ->columnSpanFull(),
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

        if (! $u instanceof User) {
            return false;
        }
        if ($u->isReportingSuperAdmin()) {
            return true;
        }
        if (! $u->isControlTeam()) {
            return false;
        }

        return $u->assignedDirectorates()->exists();
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
            'aylik-karsilastirma' => Pages\AylikHaftaKarsilastirma::route('/aylik-karsilastirma'),
            'view' => Pages\ViewControlTeamAuditNote::route('/{record}'),
        ];
    }

    public static function aylikKarsilastirmaUrl(?int $mudurlukId, mixed $yil, mixed $ay, ?int $noteId = null): ?string
    {
        $id = (int) $mudurlukId;
        $yilInt = (int) $yil;
        $ayPadded = AylikFaaliyetPeriodMerge::normalizeAy((string) $ay);
        if ($id <= 0 || $yilInt <= 0 || $ayPadded === '') {
            return null;
        }

        $parameters = [
            'directorate' => $id,
            'yil' => $yilInt,
            'ay' => $ayPadded,
        ];
        if ($noteId !== null && $noteId > 0) {
            $parameters['note'] = $noteId;
        }

        return static::getUrl('aylik-karsilastirma').'?'.http_build_query($parameters);
    }

    /**
     * Aylık/haftalık rapor: seçilen yıl + ay + (varsa) hafta.
     */
    public static function resolveAylikFaaliyetForDirectoratePeriod(
        int $directorateUserId,
        int $yil,
        string $ayRaw,
        mixed $hafta = null
    ): ?AylikFaaliyet {
        if ($directorateUserId <= 0) {
            return null;
        }

        $ayNorm = str_pad(preg_replace('/\D/', '', $ayRaw) ?: '', 2, '0', STR_PAD_LEFT);

        if ($yil > 0 && strlen($ayNorm) === 2) {
            $haftaNorm = ReportPeriodWeeks::normalizeReportHafta($hafta);
            if ($haftaNorm !== null) {
                return AnalizEkibiYoneticiRapor::findWeekReport($directorateUserId, $yil, $ayNorm, $haftaNorm);
            }

            $variants = static::normalizeAyQueryVariants($ayNorm);
            $exact = AylikFaaliyet::query()
                ->where('user_id', $directorateUserId)
                ->where('yil', $yil)
                ->whereIn('ay', $variants)
                ->orderByDesc('id')
                ->first();
            if ($exact instanceof AylikFaaliyet) {
                return $exact;
            }
        }

        return static::latestAylikFaaliyetForDirectorateUser($directorateUserId);
    }

    /**
     * @return array<int|string, string>
     */
    public static function haftaSelectOptions(mixed $yil, mixed $ay): array
    {
        $yilInt = (int) ($yil ?: now()->year);
        $ayInt = (int) preg_replace('/\D/', '', (string) ($ay ?: now()->month));
        if ($ayInt < 1 || $ayInt > 12) {
            $ayInt = (int) now()->month;
        }

        return ReportPeriodWeeks::periodSelectOptions($yilInt, $ayInt, null);
    }

    public static function applySelectedPeriod(Set $set, Get $get, bool $normalizeWeek = true): void
    {
        if ($normalizeWeek) {
            static::normalizeHaftaSelection($set, $get);
        }
        $set('activity_catalog_id', null);
        AnalizEkibiRaporForm::applyPrefill($set, $get);

        $note = trim((string) ($get('note') ?? ''));
        if ($note !== '' && ! str_starts_with($note, 'Sistem tavsiyesi:')) {
            return;
        }

        $screen = AnalizEkibiHaftalikFaaliyetEkrani::fromForm($get);
        $ozet = trim((string) ($screen['tavsiye']['ozet'] ?? ''));
        if ($ozet !== '') {
            $set('note', $ozet);
        }
    }

    public static function normalizeHaftaSelection(Set $set, Get $get): void
    {
        $options = static::haftaSelectOptions($get('yil'), $get('ay'));
        if ($options === []) {
            return;
        }

        $hafta = $get('hafta');
        $norm = ReportPeriodWeeks::normalizeReportHafta($hafta);
        foreach (array_keys($options) as $key) {
            if ((string) $key === (string) $norm || (string) $key === (string) $hafta) {
                $set('hafta', $key);

                return;
            }
        }

        $set('hafta', array_key_first($options));
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
     * @return array{yil:int, ay:string, hafta:string}
     */
    protected static function latestReportPeriodForDirectorate(int $directorateUserId): array
    {
        $yil = (int) now()->year;
        $ay = now()->format('m');
        $fallback = [
            'yil' => $yil,
            'ay' => $ay,
            'hafta' => (string) ReportPeriodWeeks::resolveWeekForReportPeriod($yil, (int) $ay),
        ];
        if ($directorateUserId <= 0) {
            return $fallback;
        }

        $rapor = static::latestAylikFaaliyetForDirectorateUser($directorateUserId);

        if (! $rapor) {
            return $fallback;
        }

        $yilR = (int) ($rapor->yil ?: $fallback['yil']);
        $ayR = str_pad((string) ($rapor->ay ?: $fallback['ay']), 2, '0', STR_PAD_LEFT);
        $hafta = ReportPeriodWeeks::normalizeReportHafta($rapor->hafta)
            ?? (string) ReportPeriodWeeks::resolveWeekForReportPeriod($yilR, (int) $ayR);

        return [
            'yil' => $yilR,
            'ay' => $ayR,
            'hafta' => $hafta,
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
            (string) $ay,
            $get('hafta')
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

    /**
     * Müdürlük + dönem için tüm kapsam kalemlerinin analizi.
     *
     * @return list<array<string, mixed>>
     */
    public static function mudurlukPeriodKalemAnalizi(Get $get): array
    {
        $directorateUserId = (int) ($get('directorate_user_id') ?? 0);
        $yil = $get('yil');
        $ay = $get('ay');

        if ($directorateUserId <= 0 || blank($yil) || blank($ay)) {
            return [];
        }

        $rapor = static::resolveAylikFaaliyetForDirectoratePeriod(
            $directorateUserId,
            (int) $yil,
            (string) $ay,
            $get('hafta')
        );
        if (! $rapor) {
            return [];
        }

        $allKalemler = [];
        foreach (static::faaliyetlerRowsWithHydratedCatalogIds($rapor, $directorateUserId) as $satir) {
            if (! is_array($satir)) {
                continue;
            }
            $kod = trim((string) ($satir['faaliyet_kodu'] ?? ''));
            foreach (\App\Support\AnalizEkibiRaporVerileri::buildKalemAnalizi($satir) as $kalem) {
                if ($kod !== '' && ! str_starts_with((string) ($kalem['kalem'] ?? ''), $kod)) {
                    $kalem['kalem'] = $kod.' — '.$kalem['kalem'];
                }
                $allKalemler[] = $kalem;
            }
        }

        return $allKalemler;
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
        $week = ReportPeriodWeeks::normalizeReportHafta($r->hafta ?? null);
        $w = ($week === null || $week === ReportPeriodWeeks::MONTHLY_VALUE) ? 0 : (int) $week;

        return $y * 10000 + $m * 100 + $w;
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
