<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ControlTeamAuditNoteResource\Pages;
use App\Models\ActivityCatalog;
use App\Models\AylikFaaliyet;
use App\Models\ControlTeamAuditNote;
use App\Models\User;
use App\Support\ActivityCatalogFormatter;
use App\Support\QuerySafety;
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
                    ->afterStateUpdated(function (Set $set, $state): void {
                        if ((int) $state > 0) {
                            $latest = static::latestReportPeriodForDirectorate((int) $state);
                            $set('yil', $latest['yil']);
                            $set('ay', $latest['ay']);
                            $set('activity_catalog_id', null);
                        }
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
                            ->afterStateUpdated(fn (Set $set) => $set('activity_catalog_id', null)),
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
                            ->afterStateUpdated(fn (Set $set) => $set('activity_catalog_id', null)),
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
                    ->required(),
                Section::make('Seçili faaliyet özeti')
                    ->schema([
                        Forms\Components\Placeholder::make('activity_hedef')
                            ->label('Hedeflenen')
                            ->content(fn (Get $get): string => (string) static::activityProgressSummary($get)['hedef']),
                        Forms\Components\Placeholder::make('activity_gerceklesen')
                            ->label('Gerçekleşen')
                            ->content(fn (Get $get): string => (string) static::activityProgressSummary($get)['gerceklesen']),
                        Forms\Components\Placeholder::make('activity_kalan')
                            ->label('Kalan')
                            ->content(fn (Get $get): string => (string) static::activityProgressSummary($get)['kalan']),
                    ])
                    ->columns(3),
                Forms\Components\Textarea::make('note')
                    ->label('Analiz Notu ve Bulgular')
                    ->rows(8)
                    ->required(),
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
            ->actions([])
            ->bulkActions([]);
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

        return $query->whereRaw('0=1');
    }

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return $u instanceof User && ($u->isReportingSuperAdmin() || $u->isControlTeam());
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
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
    protected static function activityProgressSummary(Get $get): array
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
