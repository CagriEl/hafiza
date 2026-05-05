<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AylikFaaliyetResource\Pages;
use App\Models\ActivityCatalog;
use App\Models\AylikFaaliyet;
use App\Models\User;
use App\Support\ActivityCatalogFormatter;
use App\Support\AylikFaaliyetEscalation;
use App\Support\AylikFaaliyetRepeaterLock;
use App\Support\CoordinationAccess;
use App\Support\NonNegativeInput;
use App\Support\QuerySafety;
use App\Support\ReportingModelReader;
use App\Support\TurkishString;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Component as InfolistComponent;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class AylikFaaliyetResource extends Resource
{
    protected static ?string $model = AylikFaaliyet::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'Aylık Rapor';

    protected static ?string $navigationGroup = 'Raporlama';

    protected static ?int $navigationSort = 2;

    /**
     * Faaliyet satırında katalogdan gelen kapsam kalemleri varsa ay sonu performansı yalnızca
     * bu kalemler üzerinden girilir; satır genelinde ayrı gerçekleşen / açıkta bekleyen alanı yoktur.
     */
    public static function faaliyetRowUsesKapsamAySonuForPerformans(Get $get): bool
    {
        $kv = $get('kapsam_verileri');
        if (is_array($kv) && $kv !== []) {
            return true;
        }
        $kv = $get('../../kapsam_verileri');

        return is_array($kv) && $kv !== [];
    }

    public static function sumKapsamNumericField(Get $get, string $field): float
    {
        $kv = $get('kapsam_verileri');
        if (! is_array($kv) || $kv === []) {
            $kv = $get('../../kapsam_verileri');
        }
        if (! is_array($kv)) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($kv as $line) {
            if (! is_array($line)) {
                continue;
            }
            $v = $line[$field] ?? null;
            if (filled($v) && is_numeric($v)) {
                $sum += (float) $v;
            }
        }

        return $sum;
    }

    public static function sumKapsamAciktaKalan(Get $get): float
    {
        $kv = $get('kapsam_verileri');
        if (! is_array($kv) || $kv === []) {
            $kv = $get('../../kapsam_verileri');
        }
        if (! is_array($kv)) {
            return 0.0;
        }

        return AylikFaaliyetRepeaterLock::faaliyetKapsamToplamAciktaKalan(['kapsam_verileri' => $kv]);
    }

    /**
     * Sapma / risk / karar ihtiyacı: ay sonu alanları açıkken yazılabilir; yalnızca ay sonu performans kilidi (veya süper admin dışı) kapatır.
     */
    public static function faaliyetRowAySonuSerbestMetinAlaniDisabled(Get $get, mixed $livewire): bool
    {
        if (! static::faaliyetRowShowsAySonuPerformansFields($get, $livewire)) {
            return true;
        }
        $u = auth()->user();
        if ($u instanceof User && $u->isReportingSuperAdmin()) {
            return false;
        }

        return AylikFaaliyetRepeaterLock::resolveFaaliyetRowAySonuPerformansKilitli($get);
    }

    /**
     * Ay sonu performansında sapma nedeni alanı: açıkta kalan / bekleyen iş veya hedef altı gerçekleşme varsa gösterilir.
     */
    public static function faaliyetRowShowsSapmaNedeni(Get $get, mixed $livewire): bool
    {
        if (! static::faaliyetRowShowsAySonuPerformansFields($get, $livewire)) {
            return false;
        }
        if (static::faaliyetRowUsesKapsamAySonuForPerformans($get)) {
            return static::sumKapsamAciktaKalan($get) > 0;
        }
        $bek = $get('bekleyen_is');
        if ($bek !== null && $bek !== '' && is_numeric($bek) && (float) $bek > 0) {
            return true;
        }
        $hedef = $get('hedef');
        $ger = $get('gerceklesen');
        if ($hedef === null || $hedef === '' || $ger === null || $ger === '') {
            return false;
        }
        if (! is_numeric($hedef) || ! is_numeric($ger)) {
            return false;
        }

        return (float) $ger < (float) $hedef;
    }

    private static function syncKapsamRepeaterAciktaKalanField(Set $set, Get $get): void
    {
        $set('acikta_kalan', AylikFaaliyetRepeaterLock::kapsamSatirAciktaKalan([
            'ongorulen' => $get('ongorulen'),
            'deger' => $get('deger'),
            'gerceklesen' => $get('gerceklesen'),
        ]));
    }

    private static function isGerekliRevizeEnabled(Get $get): bool
    {
        $v = $get('gerekli_revize');
        if ($v === true || $v === 1) {
            return true;
        }
        if ($v === '1' || $v === 'true' || $v === 'on') {
            return true;
        }

        return false;
    }

    private static function coordinationPrerequisitesReady(Get $get): bool
    {
        $catalogId = (int) ($get('activity_catalog_id') ?? 0);

        return $catalogId > 0;
    }

    private static function coordinationFieldsDisabled(mixed $livewire, ?Get $get = null): bool
    {
        if ($get instanceof Get && ! static::coordinationPrerequisitesReady($get)) {
            return true;
        }
        if ($get instanceof Get && AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire)) {
            return true;
        }
        if (! is_object($livewire) || ! method_exists($livewire, 'getRecord')) {
            return false;
        }
        $r = $livewire->getRecord();

        return $r instanceof AylikFaaliyet
            && auth()->user() instanceof User
            && CoordinationAccess::isIncomingPartnerOnRecord($r, (int) auth()->id());
    }

    /**
     * @return list<int>
     */
    private static function normalizeCoordinationTargetIds(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $state), fn (int $v) => $v > 0)));
    }

    /**
     * @param  list<int>  $targetIds
     * @return list<array{mudurluk_user_id:int, ihtiyac:string, hedef_tarih:mixed, bitis_suresi:string}>
     */
    private static function syncCoordinationRequests(array $targetIds, mixed $existing, mixed $legacyNeed, mixed $legacyDate, mixed $legacyDuration): array
    {
        $map = [];
        if (is_array($existing)) {
            foreach ($existing as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $uid = (int) ($row['mudurluk_user_id'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $map[$uid] = [
                    'mudurluk_user_id' => $uid,
                    'ihtiyac' => (string) ($row['ihtiyac'] ?? ''),
                    'hedef_tarih' => $row['hedef_tarih'] ?? null,
                    'bitis_suresi' => (string) ($row['bitis_suresi'] ?? ''),
                ];
            }
        }

        $legacyNeed = trim((string) ($legacyNeed ?? ''));
        $legacyDuration = trim((string) ($legacyDuration ?? ''));
        $legacyDate = $legacyDate ?? null;

        $out = [];
        foreach ($targetIds as $uid) {
            $out[] = $map[$uid] ?? [
                'mudurluk_user_id' => $uid,
                'ihtiyac' => $legacyNeed,
                'hedef_tarih' => $legacyDate,
                'bitis_suresi' => $legacyDuration,
            ];
        }

        return $out;
    }

    /**
     * Bu satırda ay sonu performansı girilmiş mi (özet başarı oranına dahil olabilecek durum).
     */
    private static function faaliyetRowAySonuPerformansiVarMiFromGet(Get $get): bool
    {
        $kv = $get('kapsam_verileri');
        if (! is_array($kv) || $kv === []) {
            $kv = $get('../../kapsam_verileri');
        }
        if (is_array($kv) && $kv !== []) {
            foreach ($kv as $line) {
                if (! is_array($line)) {
                    continue;
                }
                if (AylikFaaliyetRepeaterLock::kapsamSatirindaAySonuGerceklesenGirilmis($line)) {
                    return true;
                }
            }

            return false;
        }

        return AylikFaaliyetRepeaterLock::kapsamSatirindaAySonuGerceklesenGirilmis(['gerceklesen' => $get('gerceklesen')]);
    }

    /**
     * Revize: yalnızca ay sonu verisi tamamlanmış (tamamlanmış) kayıtlı satırlarda veya yeni satırda.
     * Ay sonu beklenen satırda kapalıdır. Performans kilidi olsa da revize işareti kayda alınır.
     * Koordinasyonda gelen müdürlük değiştiremez.
     */
    public static function faaliyetRowRevizeAlaniDisabled(Get $get, mixed $livewire): bool
    {
        $u = auth()->user();
        if (! $u instanceof User) {
            return true;
        }
        if ($u->isReportingSuperAdmin()) {
            return false;
        }
        if (! $livewire instanceof EditRecord) {
            return true;
        }
        $r = $livewire->getRecord();
        if (! $r instanceof AylikFaaliyet) {
            return true;
        }
        if (CoordinationAccess::isIncomingPartnerOnRecord($r, (int) $u->id)) {
            return true;
        }

        $orig = trim((string) (AylikFaaliyetRepeaterLock::resolveFaaliyetRowOrigIndex($get) ?? ''));
        $persisted = $orig !== '';
        if ($persisted && ! static::faaliyetRowAySonuPerformansiVarMiFromGet($get)) {
            return true;
        }

        if ($u->isMudurlukReportingAccount() && AylikFaaliyetRepeaterLock::actorOwnsAylikFaaliyetRecord($r, $u)) {
            return false;
        }
        if ($u->isViceMayorAccount()) {
            return false;
        }

        return true;
    }

    /**
     * Ay sonu (gerçekleşen / bekleyen) alanları: yalnızca kayıtlı rapor düzenlemesinde ve
     * kilitli faaliyet satırlarında (veya süper adminin kilitli satırlarında) gösterilir.
     */
    public static function faaliyetRowShowsAySonuPerformansFields(Get $get, mixed $livewire): bool
    {
        if (! $livewire instanceof EditRecord) {
            return false;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $v = AylikFaaliyetRepeaterLock::resolveFaaliyetRowOrigIndex($get);
        $persistedRow = ! ($v === null || $v === '');

        if ($user->isReportingSuperAdmin()) {
            return $persistedRow;
        }

        return AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Rapor Dönemi')
                    ->schema([
                        Grid::make(2)->schema([
                            Forms\Components\Select::make('yil')
                                ->options([2025 => '2025', 2026 => '2026'])
                                ->default(now()->year)->required(),
                            Forms\Components\Select::make('ay')
                                ->options(['01' => 'Ocak', '02' => 'Şubat', '03' => 'Mart', '04' => 'Nisan', '05' => 'Mayıs', '06' => 'Haziran', '07' => 'Temmuz', '08' => 'Ağustos', '09' => 'Eylül', '10' => 'Ekim', '11' => 'Kasım', '12' => 'Aralık'])
                                ->default(now()->format('m'))->required(),
                        ]),
                    ])->compact(),

                Section::make('Faaliyet ve Performans Takip Listesi')
                    ->description('İlk kayıtta planı oluştururken kapsam kalemlerinde yalnızca öngörülen değerleri girilir. Ay sonunda her kalem satırında gerçekleşen girilir; açıkta kalan öngörülen − gerçekleşen olarak otomatik hesaplanır. Liste performans özeti buna göre hesaplanır. Kapsam listesi olmayan satırlarda aylık hedef ve satır geneli ay sonu alanları kullanılır. Tamamlanan ay sonu bir kez kilitlenir. Yeni plan için «Gerekli Revize» ile satır ekleyebilirsiniz.')
                    ->schema([
                        Repeater::make('faaliyetler')
                            ->label('İş Listesi')
                            ->schema([
                                Forms\Components\Hidden::make('_orig_index'),
                                Forms\Components\Hidden::make('ay_sonu_performans_kilitli')
                                    ->default(false)
                                    ->dehydrated(true),
                                Grid::make(4)->schema([
                                    Forms\Components\Select::make('activity_catalog_id')
                                        ->label('Faaliyet Tanımı (Katalog)')
                                        ->options(function (Forms\Components\Select $field, Get $get): array {
                                            $mudurlukAdi = auth()->user()?->name ?? '';
                                            $record = $field->getRecord();
                                            if (! $record instanceof AylikFaaliyet) {
                                                $livewire = $field->getLivewire();
                                                if (is_object($livewire) && method_exists($livewire, 'getRecord')) {
                                                    $root = $livewire->getRecord();
                                                    if ($root instanceof AylikFaaliyet) {
                                                        $record = $root;
                                                    }
                                                }
                                            }
                                            if ($record instanceof AylikFaaliyet) {
                                                $record->loadMissing('user');
                                                if ($record->user && filled($record->user->name)) {
                                                    $mudurlukAdi = $record->user->name;
                                                }
                                            }

                                            $opts = ActivityCatalogFormatter::selectOptionsForMudurluk($mudurlukAdi);
                                            $cid = (int) ($get('activity_catalog_id') ?? 0);
                                            if ($cid > 0) {
                                                $lbl = ActivityCatalogFormatter::labelForCatalogId($cid);
                                                if ($lbl !== null) {
                                                    $opts[$cid] = $lbl;
                                                }
                                            }

                                            return $opts;
                                        })
                                        ->preload()
                                        ->getOptionLabelUsing(fn ($value) => ActivityCatalogFormatter::labelForCatalogId((int) $value) ?? '—')
                                        ->helperText(function (Get $get): ?string {
                                            $items = $get('../../faaliyetler');
                                            if (! is_array($items)) {
                                                return null;
                                            }

                                            $onceki = collect($items)
                                                ->filter(fn ($item) => is_array($item) && filled($item['faaliyet_kodu'] ?? null))
                                                ->pluck('faaliyet_kodu')
                                                ->map(fn ($k) => trim((string) $k))
                                                ->filter()
                                                ->unique()
                                                ->values()
                                                ->all();

                                            if ($onceki === []) {
                                                return null;
                                            }

                                            return 'Daha once girilen faaliyet kodlari: '.implode(', ', $onceki);
                                        })
                                        ->reactive()
                                        ->afterStateHydrated(function (Set $set, Get $get, $state): void {
                                            $catalog = ActivityCatalog::find($state);
                                            if (! $catalog) {
                                                return;
                                            }

                                            if (! filled($get('olcu_birimi'))) {
                                                $set('olcu_birimi', $catalog->olcu_birimi);
                                            }
                                            if (! filled($get('faaliyet_kodu'))) {
                                                $set('faaliyet_kodu', $catalog->faaliyet_kodu);
                                            }
                                            if (! filled($get('kapsam_icerigi'))) {
                                                $set('kapsam_icerigi', $catalog->kapsam);
                                            }

                                            $set(
                                                'kapsam_verileri',
                                                static::syncKapsamVerileri(
                                                    static::parseKapsamKalemleri((string) ($catalog->kapsam ?? '')),
                                                    $get('kapsam_verileri')
                                                )
                                            );
                                        })
                                        ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                            $catalog = ActivityCatalog::find($state);
                                            if ($catalog) {
                                                $set('olcu_birimi', $catalog->olcu_birimi);
                                                $set('faaliyet_kodu', $catalog->faaliyet_kodu);
                                                $set('kapsam_icerigi', $catalog->kapsam);
                                                $set(
                                                    'kapsam_verileri',
                                                    static::syncKapsamVerileri(
                                                        static::parseKapsamKalemleri((string) ($catalog->kapsam ?? '')),
                                                        $get('kapsam_verileri')
                                                    )
                                                );
                                            }
                                        })
                                        ->disabled(fn (Get $get, $livewire): bool => AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire))
                                        ->required()
                                        ->columnSpan(2),

                                    Forms\Components\TextInput::make('faaliyet_kodu')
                                        ->label('Kod')
                                        ->readOnly()
                                        ->disabled(fn (Get $get, $livewire): bool => AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire))
                                        ->extraAttributes(['class' => 'bg-gray-50']),

                                    Forms\Components\TextInput::make('olcu_birimi')
                                        ->label('Birim')
                                        ->readOnly()
                                        ->disabled(fn (Get $get, $livewire): bool => AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire))
                                        ->extraAttributes(['class' => 'bg-gray-50']),
                                ]),

                                Forms\Components\Textarea::make('kapsam_icerigi')
                                    ->label('Kapsam İçeriği')
                                    ->rows(2)
                                    ->readOnly()
                                    ->dehydrated()
                                    ->disabled(fn (Get $get, $livewire): bool => AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire))
                                    ->extraAttributes(['class' => 'bg-gray-50']),

                                Repeater::make('kapsam_verileri')
                                    ->label('Kapsam kalemleri (aynı satırda: öngörülen → ay sonunda gerçekleşen; açıkta kalan otomatik)')
                                    ->helperText('Plan aşamasında yalnızca öngörülen girilir. Ay sonunda gerçekleşen girilir; açıkta kalan öngörülen − gerçekleşen olarak hesaplanır (elle girilemez).')
                                    ->dehydrated()
                                    ->schema([
                                        Grid::make(4)->schema([
                                            Forms\Components\TextInput::make('kalem')
                                                ->label('Kalem')
                                                ->readOnly()
                                                ->dehydrated()
                                                ->extraAttributes(['class' => 'bg-gray-50']),
                                            Forms\Components\TextInput::make('ongorulen')
                                                ->label('Öngörülen')
                                                ->numeric()
                                                ->minValue(0)
                                                ->live()
                                                ->extraInputAttributes(['min' => 0])
                                                ->dehydrateStateUsing(fn ($state) => NonNegativeInput::normalizeScalar($state))
                                                ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                                                    $c = NonNegativeInput::coerceLiveState($state);
                                                    if ($c !== $state) {
                                                        $set('ongorulen', $c);
                                                    }
                                                    static::syncKapsamRepeaterAciktaKalanField($set, $get);
                                                })
                                                ->required(fn (Get $get): bool => trim((string) (AylikFaaliyetRepeaterLock::resolveFaaliyetRowOrigIndex($get) ?? '')) === '')
                                                ->disabled(fn (Get $get): bool => trim((string) (AylikFaaliyetRepeaterLock::resolveFaaliyetRowOrigIndex($get) ?? '')) !== '')
                                                ->dehydrated(true),
                                            Forms\Components\TextInput::make('gerceklesen')
                                                ->label('Gerçekleşen')
                                                ->numeric()
                                                ->minValue(0)
                                                ->live()
                                                ->extraInputAttributes(['min' => 0])
                                                ->dehydrateStateUsing(fn ($state) => NonNegativeInput::normalizeScalar($state))
                                                ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                                                    $c = NonNegativeInput::coerceLiveState($state);
                                                    if ($c !== $state) {
                                                        $set('gerceklesen', $c);
                                                    }
                                                    static::syncKapsamRepeaterAciktaKalanField($set, $get);
                                                })
                                                ->required(fn (Get $get, $livewire): bool => static::faaliyetRowShowsAySonuPerformansFields($get, $livewire)
                                                    && ! AylikFaaliyetRepeaterLock::resolveFaaliyetRowAySonuPerformansKilitli($get))
                                                ->visible(fn (Get $get, $livewire): bool => static::faaliyetRowShowsAySonuPerformansFields($get, $livewire))
                                                ->dehydrated(fn (Get $get, $livewire): bool => static::faaliyetRowShowsAySonuPerformansFields($get, $livewire))
                                                ->disabled(function (Get $get, $livewire): bool {
                                                    if (! static::faaliyetRowShowsAySonuPerformansFields($get, $livewire)) {
                                                        return true;
                                                    }
                                                    $u = auth()->user();
                                                    if ($u instanceof User && $u->isReportingSuperAdmin()) {
                                                        return false;
                                                    }

                                                    return AylikFaaliyetRepeaterLock::resolveFaaliyetRowAySonuPerformansKilitli($get);
                                                }),
                                            Forms\Components\TextInput::make('acikta_kalan')
                                                ->label('Açıkta kalan')
                                                ->numeric()
                                                ->minValue(0)
                                                ->extraInputAttributes(['min' => 0])
                                                ->dehydrateStateUsing(fn ($state) => NonNegativeInput::normalizeScalar($state))
                                                ->readOnly()
                                                ->helperText('Öngörülen − gerçekleşen (otomatik, en az 0).')
                                                ->visible(fn (Get $get, $livewire): bool => static::faaliyetRowShowsAySonuPerformansFields($get, $livewire))
                                                ->dehydrated(fn (Get $get, $livewire): bool => static::faaliyetRowShowsAySonuPerformansFields($get, $livewire))
                                                ->disabled(fn (Get $get, $livewire): bool => static::faaliyetRowShowsAySonuPerformansFields($get, $livewire)),
                                        ]),
                                    ])
                                    ->addable(false)
                                    ->deletable(false)
                                    ->reorderable(false)
                                    ->defaultItems(0)
                                    ->visible(fn (Get $get): bool => is_array($get('kapsam_verileri')) && count($get('kapsam_verileri')) > 0),

                                Forms\Components\Select::make('faaliyet_turu')
                                    ->label('Faaliyet Türü')
                                    ->options([
                                        'Operasyonel' => 'Operasyonel',
                                        'Koordinasyon' => 'Koordinasyon',
                                    ])
                                    ->default('Operasyonel')
                                    ->live()
                                    ->required()
                                    ->disabled(function (Get $get, $livewire): bool {
                                        $u = auth()->user();
                                        if (! $u instanceof User || ! $u->isMudurlukReportingAccount()) {
                                            return true;
                                        }
                                        if (! static::coordinationPrerequisitesReady($get)) {
                                            return true;
                                        }

                                        return AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire);
                                    })
                                    ->afterStateUpdated(function (Set $set, $state): void {
                                        if ($state !== 'Koordinasyon') {
                                            $set('isbirligi_hangi_ihtiyac', null);
                                            $set('isbirligi_hedef_tarih', null);
                                            $set('isbirligi_bitis_suresi', null);
                                            $set('isbirligi_hedef_mudurluk_user_ids', []);
                                            $set('isbirligi_talepleri', []);
                                        }
                                    }),

                                Section::make('Müdürlüklerle İşbirliği')
                                    ->description('Koordinasyon faaliyetlerinde diğer müdürlüklerle planlanan ihtiyaç ve süre bilgileri. Yalnızca müdürlük hesapları düzenleyebilir.')
                                    ->schema([
                                        Forms\Components\Select::make('isbirligi_hedef_mudurluk_user_ids')
                                            ->label('İşbirliği Yapılacak Müdürlükler')
                                            ->multiple()
                                            ->live()
                                            ->searchable()
                                            ->preload()
                                            ->disabled(fn (Get $get, $livewire): bool => static::coordinationFieldsDisabled($livewire, $get))
                                            ->options(function () {
                                                $uid = (int) (auth()->id() ?? 0);

                                                return User::queryMudurlukReportingAccounts()
                                                    ->when($uid > 0, fn (Builder $q) => $q->where($q->qualifyColumn('id'), '!=', $uid))
                                                    ->pluck('name', 'id')
                                                    ->all();
                                            })
                                            ->required(fn (Get $get) => auth()->user()?->isMudurlukReportingAccount() && $get('faaliyet_turu') === 'Koordinasyon')
                                            ->rules([
                                                fn (Get $get) => Rule::when(
                                                    auth()->user()?->isMudurlukReportingAccount() && $get('faaliyet_turu') === 'Koordinasyon',
                                                    ['array', 'min:1']
                                                ),
                                            ])
                                            ->afterStateHydrated(function (Set $set, Get $get, $state): void {
                                                $ids = static::normalizeCoordinationTargetIds($state);
                                                $set('isbirligi_hedef_mudurluk_user_ids', $ids);
                                                $set('isbirligi_talepleri', static::syncCoordinationRequests(
                                                    $ids,
                                                    $get('isbirligi_talepleri'),
                                                    $get('isbirligi_hangi_ihtiyac'),
                                                    $get('isbirligi_hedef_tarih'),
                                                    $get('isbirligi_bitis_suresi')
                                                ));
                                            })
                                            ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                                                $ids = static::normalizeCoordinationTargetIds($state);
                                                $set('isbirligi_hedef_mudurluk_user_ids', $ids);
                                                $set('isbirligi_talepleri', static::syncCoordinationRequests(
                                                    $ids,
                                                    $get('isbirligi_talepleri'),
                                                    $get('isbirligi_hangi_ihtiyac'),
                                                    $get('isbirligi_hedef_tarih'),
                                                    $get('isbirligi_bitis_suresi')
                                                ));
                                            }),

                                        Repeater::make('isbirligi_talepleri')
                                            ->label('Müdürlük Bazlı Talepler')
                                            ->helperText('Seçilen her müdürlük için ayrı talep satırı açılır.')
                                            ->dehydrated(true)
                                            ->addable(false)
                                            ->deletable(false)
                                            ->reorderable(false)
                                            ->defaultItems(0)
                                            ->itemLabel(function (array $state): ?string {
                                                $uid = (int) ($state['mudurluk_user_id'] ?? 0);
                                                if ($uid <= 0) {
                                                    return 'Müdürlük Talebi';
                                                }

                                                return User::query()->whereKey($uid)->value('name') ?? 'Müdürlük Talebi';
                                            })
                                            ->visible(fn (Get $get): bool => is_array($get('isbirligi_talepleri')) && count($get('isbirligi_talepleri')) > 0)
                                            ->schema([
                                                Forms\Components\Hidden::make('mudurluk_user_id')->dehydrated(true),
                                                Grid::make(3)->schema([
                                                    Forms\Components\Placeholder::make('mudurluk_adi')
                                                        ->label('Müdürlük')
                                                        ->content(function (Get $get): string {
                                                            $uid = (int) ($get('mudurluk_user_id') ?? 0);
                                                            if ($uid <= 0) {
                                                                return '-';
                                                            }

                                                            return User::query()->whereKey($uid)->value('name') ?? '-';
                                                        }),
                                                    Forms\Components\Textarea::make('ihtiyac')
                                                        ->label('Hangi İhtiyaç')
                                                        ->rows(3)
                                                        ->required(fn (Get $get) => auth()->user()?->isMudurlukReportingAccount() && $get('../../faaliyet_turu') === 'Koordinasyon')
                                                        ->rules([
                                                            fn (Get $get) => Rule::when(
                                                                auth()->user()?->isMudurlukReportingAccount() && $get('../../faaliyet_turu') === 'Koordinasyon',
                                                                ['string', 'max:5000']
                                                            ),
                                                        ])
                                                        ->disabled(fn (Get $get, $livewire): bool => static::coordinationFieldsDisabled($livewire, $get)),
                                                    Forms\Components\DatePicker::make('hedef_tarih')
                                                        ->label('Hedef Tarih')
                                                        ->native(false)
                                                        ->displayFormat('d.m.Y')
                                                        ->minDate(Carbon::today()->startOfDay())
                                                        ->required(fn (Get $get) => auth()->user()?->isMudurlukReportingAccount() && $get('../../faaliyet_turu') === 'Koordinasyon')
                                                        ->rules([
                                                            fn (Get $get) => Rule::when(
                                                                auth()->user()?->isMudurlukReportingAccount() && $get('../../faaliyet_turu') === 'Koordinasyon',
                                                                ['date', 'after_or_equal:today']
                                                            ),
                                                        ])
                                                        ->disabled(fn (Get $get, $livewire): bool => static::coordinationFieldsDisabled($livewire, $get)),
                                                    Forms\Components\TextInput::make('bitis_suresi')
                                                        ->label('Bitiş Süresi')
                                                        ->placeholder('Örn: 10 iş günü, 2 hafta')
                                                        ->maxLength(255)
                                                        ->required(fn (Get $get) => auth()->user()?->isMudurlukReportingAccount() && $get('../../faaliyet_turu') === 'Koordinasyon')
                                                        ->rules([
                                                            fn (Get $get) => Rule::when(
                                                                auth()->user()?->isMudurlukReportingAccount() && $get('../../faaliyet_turu') === 'Koordinasyon',
                                                                ['string', 'max:255']
                                                            ),
                                                        ])
                                                        ->disabled(fn (Get $get, $livewire): bool => static::coordinationFieldsDisabled($livewire, $get)),
                                                ]),
                                            ]),
                                    ])
                                    ->visible(fn (Get $get) => static::coordinationPrerequisitesReady($get)
                                        && $get('faaliyet_turu') === 'Koordinasyon'),

                                Grid::make(3)->schema([
                                    Forms\Components\TextInput::make('hedef')
                                        ->label('Öngörülen (aylık hedef)')
                                        ->numeric()
                                        ->minValue(0)
                                        ->extraInputAttributes(['min' => 0])
                                        ->dehydrateStateUsing(fn ($state) => NonNegativeInput::normalizeScalar($state))
                                        ->required()
                                        ->placeholder('Örn: 450')
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, $state): void {
                                            $c = NonNegativeInput::coerceLiveState($state);
                                            if ($c !== $state) {
                                                $set('hedef', $c);
                                            }
                                        })
                                        ->helperText(function (Get $get) {
                                            $catalog = ActivityCatalog::find($get('activity_catalog_id'));
                                            $kat = $catalog?->kategori ?? '';
                                            if ($kat === '' || ! TurkishString::same($kat, 'Destek / İç İşleyiş')) {
                                                return null;
                                            }
                                            $hint = ReportingModelReader::reportingStyleForKategori('Destek / İç İşleyiş');

                                            return $hint ? 'Raporlama modeli (Destek / İç İşleyiş): '.$hint : null;
                                        })
                                        ->disabled(fn (Get $get, $livewire): bool => AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire))
                                        ->dehydrated(true),

                                    Forms\Components\TextInput::make('gerceklesen')
                                        ->label('Gerçekleşen')
                                        ->numeric()
                                        ->minValue(0)
                                        ->extraInputAttributes(['min' => 0])
                                        ->dehydrateStateUsing(fn ($state) => NonNegativeInput::normalizeScalar($state))
                                        ->required(fn (Get $get, $livewire): bool => static::faaliyetRowShowsAySonuPerformansFields($get, $livewire)
                                            && ! static::faaliyetRowUsesKapsamAySonuForPerformans($get)
                                            && ! (bool) ($get('ay_sonu_performans_kilitli') ?? false))
                                        ->placeholder('Örn: 395')
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, $state): void {
                                            $c = NonNegativeInput::coerceLiveState($state);
                                            if ($c !== $state) {
                                                $set('gerceklesen', $c);
                                            }
                                        })
                                        ->visible(fn (Get $get, $livewire): bool => static::faaliyetRowShowsAySonuPerformansFields($get, $livewire)
                                            && ! static::faaliyetRowUsesKapsamAySonuForPerformans($get))
                                        ->dehydrated(fn (Get $get, $livewire): bool => static::faaliyetRowShowsAySonuPerformansFields($get, $livewire)
                                            && ! static::faaliyetRowUsesKapsamAySonuForPerformans($get))
                                        ->disabled(function (Get $get, $livewire): bool {
                                            if (! static::faaliyetRowShowsAySonuPerformansFields($get, $livewire)) {
                                                return true;
                                            }
                                            if (static::faaliyetRowUsesKapsamAySonuForPerformans($get)) {
                                                return true;
                                            }
                                            $u = auth()->user();
                                            if ($u instanceof User && $u->isReportingSuperAdmin()) {
                                                return false;
                                            }

                                            return (bool) ($get('ay_sonu_performans_kilitli') ?? false);
                                        }),

                                    Forms\Components\TextInput::make('bekleyen_is')
                                        ->label('Açıkta bekleyen')
                                        ->numeric()
                                        ->minValue(0)
                                        ->extraInputAttributes(['min' => 0])
                                        ->dehydrateStateUsing(fn ($state) => NonNegativeInput::normalizeScalar($state))
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, $state): void {
                                            $c = NonNegativeInput::coerceLiveState($state);
                                            if ($c !== $state) {
                                                $set('bekleyen_is', $c);
                                            }
                                        })
                                        ->required(fn (Get $get, $livewire): bool => static::faaliyetRowShowsAySonuPerformansFields($get, $livewire)
                                            && ! static::faaliyetRowUsesKapsamAySonuForPerformans($get)
                                            && ! (bool) ($get('ay_sonu_performans_kilitli') ?? false))
                                        ->placeholder('Örn: 18')
                                        ->visible(fn (Get $get, $livewire): bool => static::faaliyetRowShowsAySonuPerformansFields($get, $livewire)
                                            && ! static::faaliyetRowUsesKapsamAySonuForPerformans($get))
                                        ->dehydrated(fn (Get $get, $livewire): bool => static::faaliyetRowShowsAySonuPerformansFields($get, $livewire)
                                            && ! static::faaliyetRowUsesKapsamAySonuForPerformans($get))
                                        ->disabled(function (Get $get, $livewire): bool {
                                            if (! static::faaliyetRowShowsAySonuPerformansFields($get, $livewire)) {
                                                return true;
                                            }
                                            if (static::faaliyetRowUsesKapsamAySonuForPerformans($get)) {
                                                return true;
                                            }
                                            $u = auth()->user();
                                            if ($u instanceof User && $u->isReportingSuperAdmin()) {
                                                return false;
                                            }

                                            return (bool) ($get('ay_sonu_performans_kilitli') ?? false);
                                        }),
                                ]),

                                Grid::make(2)->schema([
                                    Forms\Components\Textarea::make('sapma_nedeni')
                                        ->label('Sapma Nedeni')
                                        ->placeholder('Hedefe ulaşılamadıysa veya açıkta iş kaldıysa nedenini yazınız...')
                                        ->rows(2)
                                        ->visible(fn (Get $get, $livewire): bool => static::faaliyetRowShowsSapmaNedeni($get, $livewire))
                                        ->disabled(fn (Get $get, $livewire): bool => static::faaliyetRowAySonuSerbestMetinAlaniDisabled($get, $livewire)),

                                    Forms\Components\Textarea::make('risk_engel')
                                        ->label('Risk / Engel')
                                        ->placeholder('İşin önündeki engelleri belirtiniz...')
                                        ->rows(2)
                                        ->visible(fn (Get $get, $livewire): bool => static::faaliyetRowShowsAySonuPerformansFields($get, $livewire))
                                        ->disabled(fn (Get $get, $livewire): bool => static::faaliyetRowAySonuSerbestMetinAlaniDisabled($get, $livewire)),
                                ]),

                                Group::make()
                                    ->live()
                                    ->extraAttributes(fn (Get $get): array => static::isGerekliRevizeEnabled($get)
                                        ? ['class' => 'bg-amber-50 p-2 rounded-md']
                                        : [])
                                    ->schema([
                                        Grid::make(2)->schema([
                                            Forms\Components\Toggle::make('gerekli_revize')
                                                ->label('Gerekli Revize')
                                                ->inline(false)
                                                ->default(false)
                                                ->live()
                                                ->dehydrated(true)
                                                ->disabled(fn (Get $get, $livewire): bool => static::faaliyetRowRevizeAlaniDisabled($get, $livewire))
                                                ->helperText('Ay sonu gerçekleşen girildikten sonra (özet başarı oranı oluşunca) revize işaretleyebilirsiniz. Yeni eklenen satırda plan revizesi için de kullanılır.'),
                                            Forms\Components\Textarea::make('revize_sebebi')
                                                ->label('Revize Sebebi')
                                                ->rows(2)
                                                ->placeholder('Revize neden gerekli? Kisa aciklama yaziniz...')
                                                ->disabled(fn (Get $get, $livewire): bool => static::faaliyetRowRevizeAlaniDisabled($get, $livewire))
                                                ->required(fn (Get $get): bool => static::isGerekliRevizeEnabled($get))
                                                ->visible(fn (Get $get): bool => static::isGerekliRevizeEnabled($get))
                                                ->extraAttributes(fn (Get $get): array => static::isGerekliRevizeEnabled($get)
                                                    ? ['class' => 'bg-amber-50 border-l-4 border-amber-500']
                                                    : []),
                                        ]),
                                    ]),

                                Grid::make(1)->schema([
                                    Forms\Components\TextInput::make('karar_ihtiyaci')
                                        ->label('📌 Üst Yönetim Karar İhtiyacı')
                                        ->placeholder('Başkanlık makamından beklenen karar veya destek nedir?')
                                        ->visible(fn (Get $get, $livewire): bool => static::faaliyetRowShowsAySonuPerformansFields($get, $livewire))
                                        ->disabled(fn (Get $get, $livewire): bool => static::faaliyetRowAySonuSerbestMetinAlaniDisabled($get, $livewire)),

                                    Forms\Components\Textarea::make('vice_mayor_notu')
                                        ->label('Başkan Yardımcısı Değerlendirmesi')
                                        ->placeholder('Başkan yardımcısı görüşü...')
                                        ->rows(2)
                                        ->disabled(function (Get $get, $livewire): bool {
                                            $u = auth()->user();
                                            if (! $u instanceof User) {
                                                return true;
                                            }
                                            if ($u->isReportingSuperAdmin() || $u->isViceMayorAccount()) {
                                                return AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire);
                                            }

                                            return true;
                                        })
                                        ->extraAttributes(['class' => 'bg-green-50 border-l-4 border-green-500']),
                                ]),
                            ])
                            ->itemLabel(function (array $state): ?string {
                                $label = $state['faaliyet_kodu'] ?? 'Yeni Faaliyet Girisi';
                                if ((bool) ($state['gerekli_revize'] ?? false)) {
                                    return '[REVIZE] '.$label;
                                }

                                return $label;
                            })
                            ->collapsible()
                            ->reorderable(false)
                            ->deleteAction(function (FormAction $action) {
                                return $action->visible(function (array $arguments, Repeater $component): bool {
                                    if (! $component->isDeletable()) {
                                        return false;
                                    }

                                    $user = auth()->user();
                                    if (! $user instanceof User || ! $user->isMudurlukReportingAccount()) {
                                        return true;
                                    }

                                    $livewire = $component->getLivewire();
                                    if (! $livewire instanceof \Filament\Resources\Pages\EditRecord) {
                                        return true;
                                    }

                                    $record = $livewire->getRecord();
                                    if (! $record instanceof AylikFaaliyet || ! AylikFaaliyetRepeaterLock::actorOwnsAylikFaaliyetRecord($record, $user)) {
                                        return true;
                                    }

                                    $items = $component->getState();
                                    $key = $arguments['item'] ?? null;
                                    if ($key === null || ! isset($items[$key]) || ! is_array($items[$key])) {
                                        return true;
                                    }

                                    $row = $items[$key];
                                    $v = $row['_orig_index'] ?? null;

                                    return $v === null || $v === '';
                                });
                            })
                            ->defaultItems(1),
                    ]),
            ]);
    }

    /**
     * Başarı yüzdesi: gerçekleşen / öngörülen; en fazla %100 (hedefi aşan gerçekleşme %100 gösterilir).
     */
    private static function performansBasariOraniYuzde(float $gerceklesenToplam, float $ongorulenToplam): int
    {
        if ($ongorulenToplam <= 0.0) {
            return 0;
        }
        $ham = ($gerceklesenToplam / $ongorulenToplam) * 100.0;

        return (int) min(100, max(0, round($ham)));
    }

    /**
     * Performans oranı: kapsam kalemleri varsa plan toplamı öngörülenlerin, gerçekleşen toplamı kalemlerdeki gerçekleşenlerin toplamıdır.
     *
     * @param  array<string, mixed>  $is
     */
    private static function performansPlanToplamForFaaliyetIs(array $is): float
    {
        $kv = $is['kapsam_verileri'] ?? null;
        if (is_array($kv) && $kv !== []) {
            return (float) collect($kv)->sum(function ($line) {
                if (! is_array($line)) {
                    return 0.0;
                }

                return (float) ($line['ongorulen'] ?? $line['deger'] ?? 0);
            });
        }

        return (float) ($is['hedef'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $is
     */
    private static function performansGerceklesenToplamForFaaliyetIs(array $is): float
    {
        $kv = $is['kapsam_verileri'] ?? null;
        if (is_array($kv) && $kv !== []) {
            return (float) collect($kv)->sum(function ($line) {
                if (! is_array($line)) {
                    return 0.0;
                }

                return (float) ($line['gerceklesen'] ?? 0);
            });
        }

        return (float) ($is['gerceklesen'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $is
     */
    private static function faaliyetIsindeAySonuPerformansiVarMi(array $is): bool
    {
        $kv = $is['kapsam_verileri'] ?? null;
        if (is_array($kv) && $kv !== []) {
            foreach ($kv as $line) {
                if (! is_array($line)) {
                    continue;
                }
                if (AylikFaaliyetRepeaterLock::kapsamSatirindaAySonuGerceklesenGirilmis($line)) {
                    return true;
                }
            }

            return false;
        }

        return AylikFaaliyetRepeaterLock::kapsamSatirindaAySonuGerceklesenGirilmis(['gerceklesen' => $is['gerceklesen'] ?? null]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('yil')->label('Yıl')->badge(),
                Tables\Columns\TextColumn::make('ay')->label('Ay'),
                Tables\Columns\TextColumn::make('user.name')->label('Müdürlük')->searchable(),

                Tables\Columns\TextColumn::make('performans_ozeti')
                    ->label('İş başarı performansı')
                    ->getStateUsing(function ($record) {
                        $isler = is_string($record->faaliyetler) ? json_decode($record->faaliyetler, true) : $record->faaliyetler;
                        if (! is_array($isler)) {
                            return '-';
                        }

                        $toplamHedef = collect($isler)->sum(fn ($is) => is_array($is) ? static::performansPlanToplamForFaaliyetIs($is) : 0);
                        $toplamGerceklesen = collect($isler)->sum(fn ($is) => is_array($is) ? static::performansGerceklesenToplamForFaaliyetIs($is) : 0);
                        $hasAySonu = collect($isler)->contains(fn ($is) => is_array($is) && static::faaliyetIsindeAySonuPerformansiVarMi($is));

                        if ($toplamHedef == 0) {
                            return 'Sayısal Veri Yok';
                        }
                        if (! $hasAySonu) {
                            return 'Ay sonu verisi bekleniyor';
                        }
                        $oran = static::performansBasariOraniYuzde($toplamGerceklesen, $toplamHedef);

                        return "% {$oran} Başarı";
                    })
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        str_contains((string) $state, '100') => 'success',
                        str_contains((string) $state, '80') => 'warning',
                        default => 'danger',
                    }),

                Tables\Columns\IconColumn::make('karar_bekleyen')
                    ->label('Üst yönetim bildirimi')
                    ->boolean()
                    ->getStateUsing(function ($record) {
                        $isler = is_string($record->faaliyetler) ? json_decode($record->faaliyetler, true) : $record->faaliyetler;
                        if (! is_array($isler)) {
                            return false;
                        }
                        foreach ($isler as $is) {
                            if (! is_array($is)) {
                                continue;
                            }
                            if (filled(trim((string) ($is['karar_ihtiyaci'] ?? '')))) {
                                return true;
                            }
                            if (AylikFaaliyetEscalation::itemNeedsUpperManagementAttention($is)) {
                                return true;
                            }
                        }

                        return false;
                    })
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('danger'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('yil')->options([2025 => '2025', 2026 => '2026']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Görüntüle')
                    ->visible(fn (AylikFaaliyet $record) => static::canView($record) && ! static::canEdit($record)),
                Tables\Actions\EditAction::make()
                    ->label('Raporu düzenle')
                    ->visible(fn (AylikFaaliyet $record) => static::canEdit($record)),
            ]);
    }

    private static function infolistFaaliyetRowHasKapsamVerileri(TextEntry $component): bool
    {
        $row = $component->getContainer()->getState();
        if (! is_array($row)) {
            return false;
        }
        $kv = $row['kapsam_verileri'] ?? null;

        return is_array($kv) && $kv !== [];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfolistSection::make('Rapor')
                    ->schema([
                        TextEntry::make('user.name')->label('Müdürlük')
                            ->formatStateUsing(fn ($state): string => static::normalizeInfolistTextState($state)),
                        TextEntry::make('yil')->label('Yıl')
                            ->formatStateUsing(fn ($state): string => static::normalizeInfolistTextState($state)),
                        TextEntry::make('ay')->label('Ay')
                            ->formatStateUsing(fn ($state): string => static::normalizeInfolistTextState($state)),
                    ])
                    ->columns(3),
                InfolistSection::make('Raporlanan Faaliyetler')
                    ->schema([
                        RepeatableEntry::make('faaliyetler')
                            ->schema([
                                TextEntry::make('faaliyet_kodu')->label('Kod')
                                    ->formatStateUsing(fn ($state): string => static::normalizeInfolistTextState($state)),
                                TextEntry::make('faaliyet_turu')->label('Tür')
                                    ->formatStateUsing(fn ($state): string => static::normalizeInfolistTextState($state)),
                                TextEntry::make('isbirligi_hedef_mudurluk_user_ids')
                                    ->label('İşbirliği müdürlükleri')
                                    ->default('-')
                                    ->formatStateUsing(function ($state) {
                                        if (! is_array($state) || $state === []) {
                                            return '-';
                                        }
                                        $ids = array_map('intval', $state);

                                        return User::query()->whereIn('id', $ids)->pluck('name')->implode(', ') ?: '-';
                                    }),
                                TextEntry::make('hedef')->label('Plan — Aylık öngörülen hedef')
                                    ->formatStateUsing(fn ($state): string => static::normalizeInfolistTextState($state)),
                                TextEntry::make('gerceklesen')->label('Ay sonu — Gerçekleşen (satır)')
                                    ->visible(fn (TextEntry $component): bool => ! static::infolistFaaliyetRowHasKapsamVerileri($component))
                                    ->formatStateUsing(fn ($state): string => static::normalizeInfolistTextState($state)),
                                TextEntry::make('bekleyen_is')->label('Ay sonu — Açıkta bekleyen (satır)')
                                    ->visible(fn (TextEntry $component): bool => ! static::infolistFaaliyetRowHasKapsamVerileri($component))
                                    ->formatStateUsing(fn ($state): string => static::normalizeInfolistTextState($state)),
                                TextEntry::make('olcu_birimi')->label('Ölçü Birimi')->placeholder('—')
                                    ->formatStateUsing(fn ($state): string => static::normalizeInfolistTextState($state)),
                                TextEntry::make('kapsam_icerigi')->label('Kapsam İçeriği')->placeholder('—')
                                    ->formatStateUsing(fn ($state): string => static::normalizeInfolistTextState($state)),
                                TextEntry::make('kapsam_verileri')
                                    ->label('Kapsam kalemleri (öngörülen / gerçekleşen / açıkta kalan)')
                                    ->placeholder('—')
                                    ->getStateUsing(function (InfolistComponent $component): string {
                                        $row = $component->getContainer()->getState();

                                        return static::normalizeKapsamVerileriText(
                                            is_array($row) ? ($row['kapsam_verileri'] ?? null) : null
                                        );
                                    }),
                                TextEntry::make('sapma_nedeni')->label('Sapma')->placeholder('—')
                                    ->formatStateUsing(fn ($state): string => static::normalizeInfolistTextState($state)),
                                TextEntry::make('gerekli_revize')
                                    ->label('Revize')
                                    ->formatStateUsing(fn ($state): string => (bool) $state ? 'Evet' : 'Hayir'),
                                TextEntry::make('revize_sebebi')->label('Revize sebebi')->placeholder('—')
                                    ->formatStateUsing(fn ($state): string => static::normalizeInfolistTextState($state)),
                                TextEntry::make('karar_ihtiyaci')->label('Karar ihtiyacı')->placeholder('—')
                                    ->formatStateUsing(fn ($state): string => static::normalizeInfolistTextState($state)),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $base = parent::getEloquentQuery();
        if (! QuerySafety::shouldApplyFilters($base)) {
            return $base;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            return $base->whereRaw('0 = 1');
        }

        if ($user->isReportingSuperAdmin()) {
            return $base;
        }

        $audience = $user->reportAudienceUserIds();
        if ($audience === null) {
            return $base;
        }

        if ($audience === []) {
            return $base->whereRaw('0 = 1');
        }

        if ($user->isViceMayorAccount()) {
            return $base->whereIn('user_id', $audience);
        }

        $incoming = CoordinationAccess::incomingAylikFaaliyetIdsForUser((int) $user->id);

        return $base->where(function (Builder $q) use ($audience, $incoming) {
            $q->whereIn('user_id', $audience);
            if ($incoming !== []) {
                $q->orWhereIn('id', $incoming);
            }
        });
    }

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canView(Model $record): bool
    {
        $u = auth()->user();
        if (! $u instanceof User) {
            return false;
        }

        if ($u->isReportingSuperAdmin()) {
            return true;
        }

        if ($u->isViceMayorAccount()) {
            return $u->canViewReportDataForOwnerId((int) $record->user_id);
        }

        if ($u->canViewReportDataForOwnerId((int) $record->user_id)) {
            return true;
        }

        return in_array((int) $record->id, CoordinationAccess::incomingAylikFaaliyetIdsForUser((int) $u->id), true);
    }

    public static function canEdit(Model $record): bool
    {
        if (! $record instanceof AylikFaaliyet) {
            return false;
        }

        if (! static::canView($record)) {
            return false;
        }

        $u = auth()->user();
        if (! $u instanceof User) {
            return false;
        }

        if ($u->isReportingSuperAdmin()) {
            return true;
        }

        return $u->isMudurlukReportingAccount()
            && AylikFaaliyetRepeaterLock::actorOwnsAylikFaaliyetRecord($record, $u);
    }

    public static function canDelete(Model $record): bool
    {
        $u = auth()->user();
        if (! $u instanceof User) {
            return false;
        }

        if ($u->isReportingSuperAdmin()) {
            return true;
        }

        return (int) $record->user_id === (int) $u->id;
    }

    public static function canCreate(): bool
    {
        $u = auth()->user();
        if (! $u instanceof User) {
            return false;
        }

        // Yalnızca müdürlük raporlayan hesap yeni rapor oluşturabilir.
        return $u->isMudurlukReportingAccount();
    }

    /**
     * @return list<string>
     */
    private static function parseKapsamKalemleri(string $kapsam): array
    {
        $kapsam = trim($kapsam);
        if ($kapsam === '') {
            return [];
        }

        return collect(explode(',', $kapsam))
            ->map(fn (string $parca): string => trim($parca))
            ->filter(fn (string $parca): bool => $parca !== '')
            ->values()
            ->all();
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

    private static function normalizeKapsamVerileriText(mixed $state): string
    {
        if (! is_array($state) || $state === []) {
            return '—';
        }

        // Tek satir yapida gelebilir.
        if (array_key_exists('kalem', $state) || array_key_exists('ongorulen', $state) || array_key_exists('deger', $state) || array_key_exists('gerceklesen', $state) || array_key_exists('acikta_kalan', $state)) {
            $state = [$state];
        }

        $parts = [];

        foreach ($state as $row) {
            if (! is_array($row)) {
                continue;
            }

            $kalem = trim((string) ($row['kalem'] ?? ''));
            if ($kalem === '') {
                continue;
            }

            $ong = $row['ongorulen'] ?? $row['deger'] ?? null;
            $ger = $row['gerceklesen'] ?? null;
            $acik = $row['acikta_kalan'] ?? null;
            $parts[] = $kalem.': öngörülen '.(filled($ong) ? (string) $ong : '-').' / gerçekleşen '.(filled($ger) ? (string) $ger : '-').' / açıkta kalan '.(filled($acik) ? (string) $acik : '-');
        }

        return $parts === [] ? '—' : implode(' | ', $parts);
    }

    /**
     * @param  list<string>  $kalemler
     * @return list<array{kalem: string, ongorulen: mixed, gerceklesen: mixed, acikta_kalan: mixed}>
     */
    private static function syncKapsamVerileri(array $kalemler, mixed $mevcut): array
    {
        $harita = [];
        if (is_array($mevcut)) {
            foreach ($mevcut as $satir) {
                if (! is_array($satir)) {
                    continue;
                }
                $kalem = trim((string) ($satir['kalem'] ?? ''));
                if ($kalem === '') {
                    continue;
                }
                $harita[$kalem] = [
                    'ongorulen' => $satir['ongorulen'] ?? $satir['deger'] ?? null,
                    'gerceklesen' => $satir['gerceklesen'] ?? null,
                ];
            }
        }

        $out = [];
        foreach ($kalemler as $kalem) {
            $prev = $harita[$kalem] ?? ['ongorulen' => null, 'gerceklesen' => null];
            $row = [
                'kalem' => $kalem,
                'ongorulen' => $prev['ongorulen'],
                'gerceklesen' => $prev['gerceklesen'],
            ];
            $row['acikta_kalan'] = AylikFaaliyetRepeaterLock::kapsamSatirAciktaKalan($row);
            $out[] = $row;
        }

        return $out;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAylikFaaliyets::route('/'),
            'create' => Pages\CreateAylikFaaliyet::route('/create'),
            'view' => Pages\ViewAylikFaaliyet::route('/{record}'),
            'edit' => Pages\EditAylikFaaliyet::route('/{record}/edit'),
        ];
    }
}
