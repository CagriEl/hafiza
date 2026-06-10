<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AylikFaaliyetResource\Pages;
use App\Models\ActivityCatalog;
use App\Models\AylikFaaliyet;
use App\Models\ExtraordinarySituation;
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
use Filament\Tables\Grouping\Group as TableGroup;
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

    private static function isIncomingTabActive(): bool
    {
        return (string) session('activity_report_active_tab', '') === 'incoming';
    }

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
     * Ay sonu performansında sapma nedeni alanı: açıkta kalan / bekleyen iş varsa gösterilir.
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
        return $bek !== null && $bek !== '' && is_numeric($bek) && (float) $bek > 0;
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
        $catalogId = (int) ($get('activity_catalog_id')
            ?? $get('../activity_catalog_id')
            ?? $get('../../activity_catalog_id')
            ?? $get('../../../activity_catalog_id')
            ?? 0);

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

    private static function resolveOlcuBirimiForRow(Get $get): ?string
    {
        $value = $get('olcu_birimi')
            ?? $get('../olcu_birimi')
            ?? $get('../../olcu_birimi')
            ?? $get('../../../olcu_birimi');

        $unit = trim((string) ($value ?? ''));

        return $unit !== '' ? $unit : null;
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

    /**
     * Ay sonu gerçekleşen/bekleyen zorunluluğu yalnızca rapor döneminin son gününde devreye girer.
     */
    private static function shouldRequireAySonuCompletion(mixed $livewire): bool
    {
        if (! $livewire instanceof EditRecord) {
            return false;
        }

        $record = $livewire->getRecord();
        if (! $record instanceof AylikFaaliyet) {
            return false;
        }

        $now = now();
        $recordYil = (int) ($record->yil ?? 0);
        $recordAy = str_pad(trim((string) ($record->ay ?? '')), 2, '0', STR_PAD_LEFT);

        if ($recordYil !== (int) $now->year || $recordAy !== $now->format('m')) {
            return false;
        }

        return (int) $now->day === (int) $now->daysInMonth;
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
                Section::make('Uyarı')
                    ->schema([
                        Forms\Components\Placeholder::make('rapor_olusturma_uyarisi')
                            ->content('Rapor oluştururken ilgili dönemdeki tüm faaliyetleri "İş Listesi" içine ekleyiniz. Müdürlükler aynı yıl/ay döneminde birden fazla rapor oluşturabilir.')
                            ->extraAttributes(['class' => 'text-amber-700'])
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($livewire): bool => $livewire instanceof \Filament\Resources\Pages\CreateRecord)
                    ->compact(),
                Section::make('Ay Sonu Uyarısı')
                    ->schema([
                        Forms\Components\Placeholder::make('ay_sonu_kapanis_uyarisi')
                            ->content('Ayın son günündesiniz. Mevcut faaliyet satırlarında ay sonu alanları (gerçekleşen/açıkta bekleyen) doldurulmadan kayıt tamamlanamaz.')
                            ->extraAttributes(['class' => 'text-amber-700'])
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($livewire): bool => static::shouldRequireAySonuCompletion($livewire))
                    ->compact(),

                Section::make('Faaliyet ve Performans Takip Listesi')
                    ->description('İş satırlarında yapılan iş sayısı ve bekleyen işlem sayısı üzerinden takip yapılır. Kapsam kalemi olan satırlarda bekleyen alanı otomatik hesaplanır. Tamamlanan ay sonu bir kez kilitlenir. Yeni plan için «Gerekli Revize» ile satır ekleyebilirsiniz.')
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
                                        ->label('Faaliyet Ailesi')
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
                                            if (! filled($get('raporlama_sikligi'))) {
                                                $set('raporlama_sikligi', $catalog->raporlama_sikligi);
                                            }
                                            if (! filled($get('baskanlik_bilgilendirme_seviyesi'))) {
                                                $set('baskanlik_bilgilendirme_seviyesi', $catalog->baskanlik_bilgilendirme_seviyesi);
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
                                                $set('raporlama_sikligi', $catalog->raporlama_sikligi);
                                                $set('baskanlik_bilgilendirme_seviyesi', $catalog->baskanlik_bilgilendirme_seviyesi);
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
                                        ->label('Ölçü Birimi')
                                        ->readOnly()
                                        ->disabled(fn (Get $get, $livewire): bool => AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire))
                                        ->extraAttributes(['class' => 'bg-gray-50']),
                                    Forms\Components\TextInput::make('raporlama_sikligi')
                                        ->label('Raporlama Sıklığı')
                                        ->readOnly()
                                        ->dehydrated()
                                        ->disabled(fn (Get $get, $livewire): bool => AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire))
                                        ->extraAttributes(['class' => 'bg-gray-50']),
                                    Forms\Components\TextInput::make('baskanlik_bilgilendirme_seviyesi')
                                        ->label('Başkanlık Bilgilendirme Seviyesi')
                                        ->readOnly()
                                        ->dehydrated()
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
                                                ->label('Yapılan İş')
                                                ->suffix(fn (Get $get): ?string => static::resolveOlcuBirimiForRow($get))
                                                ->numeric()
                                                ->minValue(0)
                                                ->live()
                                                ->rules(['integer', 'min:0'])
                                                ->extraInputAttributes(['min' => 0, 'step' => 1, 'inputmode' => 'numeric', 'pattern' => '[0-9]*'])
                                                ->dehydrateStateUsing(fn ($state) => NonNegativeInput::normalizeIntegerScalar($state))
                                                ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                                                    $c = NonNegativeInput::coercePositiveIntegerLiveState($state);
                                                    if ($c !== $state) {
                                                        $set('ongorulen', $c);
                                                    }
                                                    static::syncKapsamRepeaterAciktaKalanField($set, $get);
                                                })
                                                ->required(fn (Get $get): bool => trim((string) (AylikFaaliyetRepeaterLock::resolveFaaliyetRowOrigIndex($get) ?? '')) === '')
                                                ->disabled(fn (Get $get): bool => trim((string) (AylikFaaliyetRepeaterLock::resolveFaaliyetRowOrigIndex($get) ?? '')) !== '')
                                                ->dehydrated(true),
                                            Forms\Components\TextInput::make('gerceklesen')
                                                ->label('Tamamlanan İş')
                                                ->suffix(fn (Get $get): ?string => static::resolveOlcuBirimiForRow($get))
                                                ->numeric()
                                                ->minValue(0)
                                                ->live()
                                                ->rules(['integer', 'min:0'])
                                                ->extraInputAttributes(['min' => 0, 'step' => 1, 'inputmode' => 'numeric', 'pattern' => '[0-9]*'])
                                                ->dehydrateStateUsing(fn ($state) => NonNegativeInput::normalizeIntegerScalar($state))
                                                ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                                                    $c = NonNegativeInput::coercePositiveIntegerLiveState($state);
                                                    if ($c !== $state) {
                                                        $set('gerceklesen', $c);
                                                    }
                                                    static::syncKapsamRepeaterAciktaKalanField($set, $get);
                                                })
                                                ->required(fn (Get $get, $livewire): bool => static::faaliyetRowShowsAySonuPerformansFields($get, $livewire)
                                                    && static::shouldRequireAySonuCompletion($livewire)
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
                                                ->label('Açıkta Bekleyen İş')
                                                ->numeric()
                                                ->minValue(0)
                                                ->extraInputAttributes(['min' => 0])
                                                ->dehydrateStateUsing(fn ($state) => NonNegativeInput::normalizeScalar($state))
                                                ->readOnly()
                                                ->helperText('Yapılacak iş − yapılan iş (otomatik, en az 0).')
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

                                Grid::make(2)->schema([
                                    Forms\Components\TextInput::make('gerceklesen')
                                        ->label('Tamamlanan İş')
                                        ->suffix(fn (Get $get): ?string => static::resolveOlcuBirimiForRow($get))
                                        ->numeric()
                                        ->minValue(0)
                                        ->rules(['integer', 'min:0'])
                                        ->extraInputAttributes(['min' => 0, 'step' => 1, 'inputmode' => 'numeric', 'pattern' => '[0-9]*'])
                                        ->dehydrateStateUsing(fn ($state) => NonNegativeInput::normalizeIntegerScalar($state))
                                        ->required(fn (Get $get, $livewire): bool => static::faaliyetRowShowsAySonuPerformansFields($get, $livewire)
                                            && static::shouldRequireAySonuCompletion($livewire)
                                            && ! static::faaliyetRowUsesKapsamAySonuForPerformans($get)
                                            && ! (bool) ($get('ay_sonu_performans_kilitli') ?? false))
                                        ->placeholder('Örn: 395')
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, $state): void {
                                            $c = NonNegativeInput::coercePositiveIntegerLiveState($state);
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
                                        ->label('Açıkta Bekleyen İş')
                                        ->suffix(fn (Get $get): ?string => static::resolveOlcuBirimiForRow($get))
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
                                            && static::shouldRequireAySonuCompletion($livewire)
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
                            ->deletable(false)
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

        $gerceklesen = (float) ($is['gerceklesen'] ?? 0);
        $bekleyen = (float) ($is['bekleyen_is'] ?? 0);

        return max(0.0, $gerceklesen + $bekleyen);
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
            ->modifyQueryUsing(fn (Builder $query): Builder => static::applyMudurlukTreeScope($query))
            ->columns([
                Tables\Columns\TextColumn::make('yil')->label('Yıl')->badge(),
                Tables\Columns\TextColumn::make('ay')->label('Ay'),
                Tables\Columns\TextColumn::make('user.name')->label('Müdürlük')->searchable(),
                Tables\Columns\TextColumn::make('talep_tarihi')
                    ->label('Talep Tarihi')
                    ->getStateUsing(fn (AylikFaaliyet $record): string => optional($record->created_at)?->format('d.m.Y') ?? '—')
                    ->visible(fn (): bool => static::isIncomingTabActive()),
                Tables\Columns\TextColumn::make('incoming_coordination_summary')
                    ->label('Gelen Koordinasyon Detayı')
                    ->getStateUsing(function (AylikFaaliyet $record): string {
                        return static::coordinationIncomingSummaryForViewer($record);
                    })
                    ->toggleable()
                    ->wrap()
                    ->visible(fn (): bool => static::isIncomingTabActive()),
                Tables\Columns\TextColumn::make('extraordinary_situation_summary')
                    ->label('Olağanüstü Durum')
                    ->getStateUsing(fn (AylikFaaliyet $record): string => static::latestExtraordinarySituationSummary($record))
                    ->badge()
                    ->color(fn (string $state): string => $state === '—' ? 'gray' : 'warning')
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('presidency_info_level_summary')
                    ->label('Başkanlık Bilgilendirme Seviyesi')
                    ->getStateUsing(fn (AylikFaaliyet $record): string => static::presidencyInfoLevelSummary($record))
                    ->badge()
                    ->color(fn (string $state): string => match (mb_strtolower(trim($state))) {
                        'kritik', 'acil müdahale gerektirir' => 'danger',
                        'takip edilecek' => 'warning',
                        'bilgi amaçlı' => 'info',
                        default => 'gray',
                    })
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('performans_ozeti')
                    ->label('İş Durum Özeti')
                    ->getStateUsing(function ($record) {
                        $isler = is_string($record->faaliyetler) ? json_decode($record->faaliyetler, true) : $record->faaliyetler;
                        if (! is_array($isler)) {
                            return '-';
                        }

                        $yapilan = 0;
                        $bekleyen = 0;
                        foreach ($isler as $is) {
                            if (! is_array($is)) {
                                continue;
                            }
                            $plan = static::performansPlanToplamForFaaliyetIs($is);
                            $ger = static::performansGerceklesenToplamForFaaliyetIs($is);
                            if ($plan <= 0 && $ger <= 0) {
                                continue;
                            }
                            $tamamlandi = ($plan > 0 && $ger >= $plan) || ($plan <= 0 && $ger > 0);
                            if ($tamamlandi) {
                                $yapilan++;
                            } else {
                                $bekleyen++;
                            }
                        }

                        return "Yapılan: {$yapilan} | Bekleyen: {$bekleyen}";
                    })
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        str_contains((string) $state, 'Bekleyen: 0') => 'success',
                        str_contains((string) $state, 'Yapılan: 0') => 'danger',
                        default => 'warning',
                    })
                    ->visible(fn (): bool => ! static::isIncomingTabActive()),

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
                    ->trueColor('danger')
                    ->visible(fn (): bool => ! static::isIncomingTabActive()),
            ])
            ->groups([
                TableGroup::make('user.name')
                    ->label('Dosya Ağacı / Müdürlük')
                    ->collapsible(),
            ])
            ->defaultGroup('user.name')
            ->filters([
                Tables\Filters\Filter::make('mudurluk_faaliyet_katalog')
                    ->label('Müdürlük / Faaliyet')
                    ->form([
                        Forms\Components\Select::make('mudurluk_user_id')
                            ->label('Müdürlük')
                            ->options(fn (): array => static::reportVisibleMudurlukFilterOptions())
                            ->searchable()
                            ->preload()
                            ->live(),
                        Forms\Components\Select::make('faaliyet_kodu')
                            ->label('Faaliyet Kodu')
                            ->options(fn (Get $get): array => static::faaliyetKoduOptionsForFilter(
                                $get('mudurluk_user_id'),
                                null
                            ))
                            ->searchable()
                            ->preload(),
                    ])
                    ->columns(1)
                    ->query(function (Builder $query, array $data): Builder {
                        $mudurlukUserId = isset($data['mudurluk_user_id']) ? (int) $data['mudurluk_user_id'] : 0;
                        $faaliyetKodu = trim((string) ($data['faaliyet_kodu'] ?? ''));

                        if ($mudurlukUserId > 0) {
                            $query->where($query->qualifyColumn('user_id'), $mudurlukUserId);
                        }

                        if ($faaliyetKodu !== '') {
                            return static::applyFaaliyetKodlariJsonFilter($query, [$faaliyetKodu]);
                        }

                        return $query;
                    }),
                Tables\Filters\SelectFilter::make('yil')->options([2025 => '2025', 2026 => '2026']),
                Tables\Filters\SelectFilter::make('ay')
                    ->label('Ay')
                    ->options([
                        '01' => 'Ocak',
                        '02' => 'Şubat',
                        '03' => 'Mart',
                        '04' => 'Nisan',
                        '05' => 'Mayıs',
                        '06' => 'Haziran',
                        '07' => 'Temmuz',
                        '08' => 'Ağustos',
                        '09' => 'Eylül',
                        '10' => 'Ekim',
                        '11' => 'Kasım',
                        '12' => 'Aralık',
                    ]),
                Tables\Filters\SelectFilter::make('is_durum_ozeti')
                    ->label('İş Durum Özeti')
                    ->options([
                        'tamamlanan_var' => 'Tamamlanan İş Var',
                        'bekleyen_var' => 'Açıkta Bekleyen İş Var',
                        'sadece_tamamlanan' => 'Sadece Tamamlanan',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! is_string($value) || $value === '') {
                            return $query;
                        }

                        return static::applyIsDurumOzetiFilter($query, $value);
                    }),
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

    /**
     * @return array<int, string>
     */
    private static function reportVisibleMudurlukFilterOptions(): array
    {
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
                ->orderBy('users.id')
                ->limit(5)
                ->pluck('users.name', 'users.id')
                ->all();
        }

        $audience = $u->reportAudienceUserIds();
        if (! is_array($audience) || $audience === []) {
            return [];
        }

        $ids = array_values(array_filter(array_map('intval', $audience), fn (int $id): bool => $id > 0));

        return User::query()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->all();
    }

    private static function applyMudurlukTreeScope(Builder $query): Builder
    {
        $u = auth()->user();
        if (! $u instanceof User || ! $u->isControlTeam()) {
            return $query;
        }

        $allowedMudurlukIds = array_values(array_map(
            'intval',
            array_keys(static::reportVisibleMudurlukFilterOptions())
        ));
        if ($allowedMudurlukIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn($query->qualifyColumn('user_id'), $allowedMudurlukIds);
    }

    /**
     * @return array<string, string>
     */
    private static function faaliyetKoduOptionsForFilter(mixed $mudurlukUserId, mixed $faaliyetAilesi): array
    {
        $mudurlukOptions = static::reportVisibleMudurlukFilterOptions();
        $userId = is_numeric((string) $mudurlukUserId) ? (int) $mudurlukUserId : 0;
        $family = trim((string) ($faaliyetAilesi ?? ''));

        $catalog = ActivityCatalog::query()
            ->select(['faaliyet_kodu', 'faaliyet_ailesi', 'mudurluk'])
            ->whereNotNull('faaliyet_kodu')
            ->where('faaliyet_kodu', '!=', '');

        if ($userId > 0) {
            $mudurlukName = trim((string) ($mudurlukOptions[$userId] ?? ''));
            if ($mudurlukName === '') {
                return [];
            }
            $catalog->where('mudurluk', $mudurlukName);
        } else {
            $mudurlukNames = array_values(array_filter(array_map(
                fn (mixed $name): string => trim((string) $name),
                array_values($mudurlukOptions)
            )));
            if ($mudurlukNames === []) {
                return [];
            }
            $catalog->whereIn('mudurluk', $mudurlukNames);
        }

        if ($family !== '') {
            $catalog->where('faaliyet_ailesi', $family);
        }

        return $catalog
            ->orderBy('faaliyet_kodu')
            ->get()
            ->mapWithKeys(function (ActivityCatalog $row): array {
                $code = trim((string) ($row->faaliyet_kodu ?? ''));
                $family = trim((string) ($row->faaliyet_ailesi ?? ''));
                if ($code === '') {
                    return [];
                }

                $label = $family !== '' ? $code.' - '.$family : $code;

                return [$code => $label];
            })
            ->all();
    }

    /**
     * @param  list<string>  $codes
     */
    private static function applyFaaliyetKodlariJsonFilter(Builder $query, array $codes): Builder
    {
        $codes = array_values(array_unique(array_filter(array_map(
            fn (mixed $code): string => trim((string) $code),
            $codes
        ))));
        if ($codes === []) {
            return $query->whereRaw('0 = 1');
        }

        $column = $query->qualifyColumn('faaliyetler');

        return $query->where(function (Builder $q) use ($column, $codes): void {
            foreach ($codes as $code) {
                $q->orWhereRaw(
                    "JSON_SEARCH({$column}, 'one', ?, NULL, '$[*].faaliyet_kodu') IS NOT NULL",
                    [$code]
                );
            }
        });
    }

    private static function applyIsDurumOzetiFilter(Builder $query, string $value): Builder
    {
        $faaliyetlerColumn = $query->qualifyColumn('faaliyetler');
        $doneExpr = "COALESCE(CAST(NULLIF(jt.gerceklesen, '') AS DECIMAL(18,2)), 0)";
        $planExpr = "COALESCE(CAST(NULLIF(jt.hedef, '') AS DECIMAL(18,2)), CAST(NULLIF(jt.ongorulen, '') AS DECIMAL(18,2)), CAST(NULLIF(jt.deger, '') AS DECIMAL(18,2)), 0)";
        $pendingExpr = "CASE
            WHEN jt.bekleyen_is IS NOT NULL AND jt.bekleyen_is != '' THEN COALESCE(CAST(NULLIF(jt.bekleyen_is, '') AS DECIMAL(18,2)), 0)
            ELSE GREATEST({$planExpr} - {$doneExpr}, 0)
        END";
        $jsonTable = "JSON_TABLE({$faaliyetlerColumn}, '$[*]' COLUMNS(
            gerceklesen VARCHAR(64) PATH '$.gerceklesen',
            bekleyen_is VARCHAR(64) PATH '$.bekleyen_is',
            hedef VARCHAR(64) PATH '$.hedef',
            ongorulen VARCHAR(64) PATH '$.ongorulen',
            deger VARCHAR(64) PATH '$.deger'
        )) jt";
        $existsCompleted = "EXISTS (SELECT 1 FROM {$jsonTable} WHERE {$doneExpr} > 0 AND {$pendingExpr} <= 0)";
        $existsPending = "EXISTS (SELECT 1 FROM {$jsonTable} WHERE {$pendingExpr} > 0)";

        return match ($value) {
            'tamamlanan_var' => $query->whereRaw($existsCompleted),
            'bekleyen_var' => $query->whereRaw($existsPending),
            'sadece_tamamlanan' => $query->whereRaw($existsCompleted)->whereRaw("NOT ({$existsPending})"),
            default => $query,
        };
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

    private static function shouldShowIncomingCoordinationOnly(?AylikFaaliyet $record): bool
    {
        $u = auth()->user();
        if (! $u instanceof User || ! $record instanceof AylikFaaliyet) {
            return false;
        }

        if (static::isIncomingTabActive()) {
            return true;
        }

        return $u->isMudurlukReportingAccount()
            && ! AylikFaaliyetRepeaterLock::actorOwnsAylikFaaliyetRecord($record, $u)
            && CoordinationAccess::isIncomingPartnerOnRecord($record, (int) $u->id);
    }

    private static function incomingCoordinationDetailText(?AylikFaaliyet $record): string
    {
        if (! $record instanceof AylikFaaliyet) {
            return '—';
        }

        $targetUserIds = static::incomingCoordinationTargetUserIdsForViewer();
        $lines = [];
        $rows = is_array($record->faaliyetler) ? $record->faaliyetler : [];
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['faaliyet_turu'] ?? null) !== 'Koordinasyon') {
                continue;
            }
            $talepler = $row['isbirligi_talepleri'] ?? [];
            if (! is_array($talepler)) {
                continue;
            }
            $kod = trim((string) ($row['faaliyet_kodu'] ?? 'Koordinasyon'));
            $kapsam = trim((string) ($row['kapsam_icerigi'] ?? ''));

            foreach ($talepler as $talep) {
                if (! is_array($talep)) {
                    continue;
                }
                $talepUserId = (int) ($talep['mudurluk_user_id'] ?? 0);
                if ($targetUserIds !== [] && ! in_array($talepUserId, $targetUserIds, true)) {
                    continue;
                }

                $parts = [];
                $ihtiyac = trim((string) ($talep['ihtiyac'] ?? ''));
                $hedefTarih = trim((string) ($talep['hedef_tarih'] ?? ''));
                $bitisSuresi = trim((string) ($talep['bitis_suresi'] ?? ''));
                if ($ihtiyac !== '') {
                    $parts[] = 'İhtiyaç: '.e($ihtiyac);
                }
                if ($hedefTarih !== '') {
                    $parts[] = 'Hedef tarih: '.e($hedefTarih);
                }
                if ($bitisSuresi !== '') {
                    $parts[] = 'Bitiş süresi: '.e($bitisSuresi);
                }
                if ($parts === []) {
                    continue;
                }
                $block = ['Faaliyet: '.e($kod)];
                if ($kapsam !== '') {
                    $block[] = 'Kapsam: '.e($kapsam);
                }
                foreach ($parts as $part) {
                    $block[] = $part;
                }

                $lines[] = implode('<br>', $block);
            }
        }

        return $lines === [] ? '—' : implode('<hr class="my-2">', $lines);
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
                InfolistSection::make('Koordinasyon Detayı')
                    ->visible(fn (?AylikFaaliyet $record): bool => static::shouldShowIncomingCoordinationOnly($record))
                    ->schema([
                        TextEntry::make('incoming_coordination_detail')
                            ->label('Size Atanan Koordinasyon Bilgileri')
                            ->getStateUsing(fn (?AylikFaaliyet $record): string => static::incomingCoordinationDetailText($record))
                            ->placeholder('—')
                            ->html(),
                    ]),
                InfolistSection::make('Olağanüstü Durum Bildirimleri')
                    ->schema([
                        TextEntry::make('extraordinary_situation_detail')
                            ->label('Dönem Bildirimleri')
                            ->getStateUsing(fn (?AylikFaaliyet $record): string => static::extraordinarySituationDetailText($record))
                            ->placeholder('—')
                            ->html(),
                    ]),
                InfolistSection::make('Görsel Performans Özeti')
                    ->visible(fn (?AylikFaaliyet $record): bool => ! static::shouldShowIncomingCoordinationOnly($record))
                    ->schema([
                        TextEntry::make('visual_performance_summary')
                            ->hiddenLabel()
                            ->getStateUsing(fn (?AylikFaaliyet $record): string => static::visualPerformanceSummaryHtml($record))
                            ->html()
                            ->columnSpanFull(),
                    ]),
                InfolistSection::make('Raporlanan Faaliyetler')
                    ->visible(fn (?AylikFaaliyet $record): bool => ! static::shouldShowIncomingCoordinationOnly($record))
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
                                TextEntry::make('gerceklesen')->label('Ay sonu — Tamamlanan İş (satır)')
                                    ->visible(fn (TextEntry $component): bool => ! static::infolistFaaliyetRowHasKapsamVerileri($component))
                                    ->formatStateUsing(fn ($state): string => static::normalizeInfolistTextState($state)),
                                TextEntry::make('bekleyen_is')->label('Ay sonu — Açıkta Bekleyen İş (satır)')
                                    ->visible(fn (TextEntry $component): bool => ! static::infolistFaaliyetRowHasKapsamVerileri($component))
                                    ->formatStateUsing(fn ($state): string => static::normalizeInfolistTextState($state)),
                                TextEntry::make('olcu_birimi')->label('Ölçü Birimi')->placeholder('—')
                                    ->formatStateUsing(fn ($state): string => static::normalizeInfolistTextState($state)),
                                TextEntry::make('baskanlik_bilgilendirme_seviyesi')->label('Başkanlık Bilgilendirme Seviyesi')->placeholder('—')
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
        return false;
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

    private static function visualPerformanceSummaryHtml(?AylikFaaliyet $record): string
    {
        $summary = static::summarizeReportForPresentation($record);
        $totalDone = number_format((float) $summary['total_done'], 0, ',', '.');
        $totalPending = number_format((float) $summary['total_pending'], 0, ',', '.');
        $totalPlan = number_format((float) $summary['total_plan'], 0, ',', '.');
        $completion = (int) $summary['completion'];
        $completedRows = (int) $summary['completed_rows'];
        $pendingRows = (int) $summary['pending_rows'];
        $totalsMissing = (bool) $summary['totals_missing'];
        $totalDoneColor = $totalsMissing ? '#b91c1c' : '#065f46';
        $totalPendingColor = $totalsMissing ? '#b91c1c' : '#1e3a8a';
        $totalPlanColor = $totalsMissing ? '#b91c1c' : '#9a3412';

        $chartMax = max((float) $summary['total_done'], (float) $summary['total_pending'], (float) $summary['total_plan'], 1.0);
        $doneRatio = (int) round(((float) $summary['total_done'] / $chartMax) * 100);
        $pendingRatio = (int) round(((float) $summary['total_pending'] / $chartMax) * 100);
        $planRatio = (int) round(((float) $summary['total_plan'] / $chartMax) * 100);
        $chartHtml = '<div style="border:1px solid #e5e7eb;border-radius:10px;padding:10px;background:#fff;margin-top:10px;">'
            .'<div style="font-size:12px;font-weight:700;color:#111827;margin-bottom:8px;">Aylık İş Dağılımı (Chart)</div>'
            .'<table style="width:100%;border-collapse:collapse;">'
            .'<tr><td style="width:120px;font-size:12px;color:#065f46;padding:6px 8px;">Yapılan</td><td style="padding:6px 8px;">'
            .'<div style="height:10px;background:#e5e7eb;border-radius:9999px;overflow:hidden;"><div style="height:100%;width:'.$doneRatio.'%;background:#22c55e;"></div></div>'
            .'</td><td style="width:80px;text-align:right;font-size:12px;font-weight:700;color:'.$totalDoneColor.';">'.e($totalDone).'</td></tr>'
            .'<tr><td style="width:120px;font-size:12px;color:#1e3a8a;padding:6px 8px;">Açıkta Bekleyen</td><td style="padding:6px 8px;">'
            .'<div style="height:10px;background:#e5e7eb;border-radius:9999px;overflow:hidden;"><div style="height:100%;width:'.$pendingRatio.'%;background:#3b82f6;"></div></div>'
            .'</td><td style="width:80px;text-align:right;font-size:12px;font-weight:700;color:'.$totalPendingColor.';">'.e($totalPending).'</td></tr>'
            .'<tr><td style="width:120px;font-size:12px;color:#6b21a8;padding:6px 8px;">Toplam</td><td style="padding:6px 8px;">'
            .'<div style="height:10px;background:#e5e7eb;border-radius:9999px;overflow:hidden;"><div style="height:100%;width:'.$planRatio.'%;background:#a855f7;"></div></div>'
            .'</td><td style="width:80px;text-align:right;font-size:12px;font-weight:700;color:'.$totalPlanColor.';">'.e($totalPlan).'</td></tr>'
            .'</table>'
            .'</div>';

        $cardsHtml = '';
        foreach ($summary['items'] as $item) {
            $width = (int) $item['completion'];
            $done = number_format((float) $item['done'], 0, ',', '.');
            $pending = number_format((float) $item['pending'], 0, ',', '.');
            $plan = number_format((float) $item['plan'], 0, ',', '.');
            $doneColor = (bool) ($item['missing_done'] ?? false) ? '#b91c1c' : '#111827';
            $pendingColor = (bool) ($item['missing_pending'] ?? false) ? '#b91c1c' : '#111827';
            $planColor = (bool) ($item['missing_plan'] ?? false) ? '#b91c1c' : '#111827';
            $unit = trim((string) ($item['unit'] ?? ''));
            $unitSuffix = $unit !== '' ? ' '.$unit : '';
            $infoLevel = trim((string) ($item['info_level'] ?? ''));
            $infoHtml = $infoLevel !== ''
                ? '<span style="font-size:11px;color:#b91c1c;background:#fee2e2;padding:2px 8px;border-radius:9999px;">Bilgilendirme: '.e($infoLevel).'</span>'
                : '';

            $cardsHtml .= '<div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;margin-bottom:8px;box-sizing:border-box;">'
                .'<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap;">'
                .'<div style="min-width:0;"><div style="font-weight:700;color:#111827;word-break:break-word;">'.e((string) $item['code']).'</div><div style="font-size:12px;color:#4b5563;word-break:break-word;">'.e((string) $item['title']).'</div></div>'
                .'<span style="font-size:12px;padding:3px 10px;border-radius:9999px;background:'.e((string) $item['badge_bg']).';color:'.e((string) $item['badge_text']).';">'.e((string) $item['status_label']).'</span>'
                .'</div>'
                .'<div style="display:block;margin-top:8px;font-size:12px;color:#374151;line-height:1.6;">'
                .'<div>Yapılan: <b style="color:'.$doneColor.';">'.e($done.$unitSuffix).'</b></div>'
                .'<div>Açıkta Bekleyen: <b style="color:'.$pendingColor.';">'.e($pending.$unitSuffix).'</b></div>'
                .'<div>Toplam İş: <b style="color:'.$planColor.';">'.e($plan.$unitSuffix).'</b></div>'
                .($infoHtml !== '' ? '<div style="margin-top:4px;">'.$infoHtml.'</div>' : '')
                .'</div>'
                .'<div style="margin-top:10px;background:#e5e7eb;height:9px;border-radius:9999px;overflow:hidden;">'
                .'<div style="height:100%;width:'.$width.'%;background:'.e((string) $item['bar_color']).';"></div>'
                .'</div>'
                .'<div style="margin-top:6px;font-size:11px;color:#6b7280;">Tamamlanma oranı: <b>%'.e((string) $width).'</b></div>'
                .'</div>';
        }

        $riskItems = collect($summary['items'])
            ->filter(fn (array $item): bool => ((float) ($item['pending'] ?? 0.0) > 0.0)
                || ((bool) ($item['missing_done'] ?? false))
                || ((bool) ($item['missing_pending'] ?? false))
                || ((bool) ($item['missing_plan'] ?? false)))
            ->take(5)
            ->map(function (array $item): string {
                $pending = number_format((float) ($item['pending'] ?? 0), 0, ',', '.');
                $status = trim((string) ($item['status_label'] ?? 'Kısmi'));

                return '<li style="margin:0 0 6px 16px;color:#7f1d1d;">'
                    .'<b>'.e((string) ($item['code'] ?? 'Faaliyet')).'</b> - '
                    .e((string) ($item['title'] ?? 'Kapsam girilmedi')).' | '
                    .'Açıkta Bekleyen: <b>'.e($pending).'</b> | Durum: <b>'.e($status).'</b>'
                    .'</li>';
            })
            ->implode('');
        $riskPanelHtml = $riskItems === ''
            ? '<div style="font-size:12px;color:#166534;">Kritik risk görünmüyor, açıkta bekleyen satır yok.</div>'
            : '<ul style="padding:0;margin:6px 0 0;">'.$riskItems.'</ul>';

        return '<div>'
            .'<table style="width:100%;border-collapse:separate;border-spacing:8px;table-layout:fixed;">'
            .'<tr>'
            .'<td style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;padding:10px;vertical-align:top;"><div style="font-size:12px;color:#065f46;">Yapılan İş</div><div style="font-size:22px;font-weight:700;color:'.$totalDoneColor.';">'.$totalDone.'</div></td>'
            .'<td style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:10px;vertical-align:top;"><div style="font-size:12px;color:#1e3a8a;">Açıkta Bekleyen İş</div><div style="font-size:22px;font-weight:700;color:'.$totalPendingColor.';">'.$totalPending.'</div></td>'
            .'<td style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:10px;vertical-align:top;"><div style="font-size:12px;color:#9a3412;">Toplam İş</div><div style="font-size:22px;font-weight:700;color:'.$totalPlanColor.';">'.$totalPlan.'</div></td>'
            .'<td style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:10px;padding:10px;vertical-align:top;"><div style="font-size:12px;color:#5b21b6;">Genel Tamamlanma</div><div style="font-size:22px;font-weight:700;color:#5b21b6;">%'.$completion.'</div></td>'
            .'</tr></table>'
            .'<div style="font-size:11px;color:#b91c1c;">Eksik alanlar otomatik olarak 0 gösterilir ve kırmızı ile işaretlenir.</div>'
            .$chartHtml
            .'<div style="font-size:12px;color:#4b5563;margin-top:8px;">Satır özeti: <b>'.e((string) $completedRows).'</b> tamamlandı, <b>'.e((string) $pendingRows).'</b> satır açıkta bekliyor.</div>'
            .'<div style="border:1px solid #fecaca;background:#fff1f2;border-radius:10px;padding:10px;margin-top:8px;">'
            .'<div style="font-size:12px;font-weight:700;color:#9f1239;">Risk ve İstisnalar</div>'
            .$riskPanelHtml
            .'</div>'
            .'<div style="margin-top:8px;">'.$cardsHtml.'</div>'
            .'</div>';
    }

    public static function reportPdfHtml(?AylikFaaliyet $record): string
    {
        $summary = static::summarizeReportForPresentation($record);
        $mudurluk = trim((string) ($record?->user?->name ?? 'Belirtilmemiş'));
        $period = trim((string) (($record?->yil ?? '—').' / '.str_pad((string) ($record?->ay ?? '—'), 2, '0', STR_PAD_LEFT)));
        $rowsHtml = '';

        foreach ($summary['items'] as $item) {
            $doneColor = (bool) ($item['missing_done'] ?? false) ? '#b91c1c' : '#111827';
            $pendingColor = (bool) ($item['missing_pending'] ?? false) ? '#b91c1c' : '#111827';
            $planColor = (bool) ($item['missing_plan'] ?? false) ? '#b91c1c' : '#111827';
            $rowsHtml .= '<tr>'
                .'<td>'.e((string) $item['code']).'</td>'
                .'<td>'.e((string) $item['title']).'</td>'
                .'<td style="color:'.$doneColor.';">'.e(number_format((float) $item['done'], 0, ',', '.')).'</td>'
                .'<td style="color:'.$pendingColor.';">'.e(number_format((float) $item['pending'], 0, ',', '.')).'</td>'
                .'<td style="color:'.$planColor.';">'.e(number_format((float) $item['plan'], 0, ',', '.')).'</td>'
                .'<td>%'.e((string) ((int) $item['completion'])).'</td>'
                .'<td>'.e((string) $item['status_label']).'</td>'
                .'<td>'.e((string) ($item['info_level'] ?: '—')).'</td>'
                .'</tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="8">Kayıtlı faaliyet bulunamadı.</td></tr>';
        }

        return '<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #111827; }
        .title { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
        .meta { font-size: 11px; color: #4b5563; margin-bottom: 12px; }
        .cards { width: 100%; margin-bottom: 12px; }
        .cards td { border: 1px solid #d1d5db; border-radius: 8px; padding: 8px; width: 25%; }
        .cards .k { font-size: 10px; color: #374151; }
        .cards .v { font-size: 18px; font-weight: 700; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 6px; vertical-align: top; text-align: left; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>
    <div class="title">Faaliyet Raporu - Görsel Özet</div>
    <div class="meta">Müdürlük: '.e($mudurluk).' | Dönem: '.e($period).' | Oluşturulma: '.e(now()->format('d.m.Y H:i')).'</div>

    <table class="cards">
        <tr>
            <td><div class="k">Yapılan İş</div><div class="v">'.e(number_format((float) $summary['total_done'], 0, ',', '.')).'</div></td>
            <td><div class="k">Açıkta Bekleyen İş</div><div class="v">'.e(number_format((float) $summary['total_pending'], 0, ',', '.')).'</div></td>
            <td><div class="k">Toplam İş</div><div class="v">'.e(number_format((float) $summary['total_plan'], 0, ',', '.')).'</div></td>
            <td><div class="k">Genel Tamamlanma</div><div class="v">%'.e((string) ((int) $summary['completion'])).'</div></td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Kod</th>
                <th>Faaliyet</th>
                <th>Yapılan</th>
                <th>Açıkta Bekleyen</th>
                <th>Toplam</th>
                <th>Tamamlanma</th>
                <th>Durum</th>
                <th>Başkanlık Bilgilendirme</th>
            </tr>
        </thead>
        <tbody>
            '.$rowsHtml.'
        </tbody>
    </table>
</body>
</html>';
    }

    /**
     * @return array{
     *   total_done: float,
     *   total_pending: float,
     *   total_plan: float,
     *   completion: int,
     *   total_rows: int,
     *   completed_rows: int,
     *   pending_rows: int,
     *   totals_missing: bool,
     *   items: list<array{
     *     code: string,
     *     title: string,
     *     unit: string,
     *     info_level: string,
     *     done: float,
     *     pending: float,
     *     plan: float,
     *     missing_done: bool,
     *     missing_pending: bool,
     *     missing_plan: bool,
     *     completion: int,
     *     status_label: string,
     *     badge_bg: string,
     *     badge_text: string,
     *     bar_color: string
     *   }>
     * }
     */
    private static function summarizeReportForPresentation(?AylikFaaliyet $record): array
    {
        $rows = is_array($record?->faaliyetler) ? $record->faaliyetler : [];
        $items = [];
        $totalDone = 0.0;
        $totalPending = 0.0;
        $totalPlan = 0.0;
        $completedRows = 0;
        $pendingRows = 0;
        $totalsMissing = false;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $progress = static::resolveProgressFromFaaliyetRow($row);
            $done = $progress['done'];
            $pending = $progress['pending'];
            $plan = $progress['plan'];
            $missingDone = (bool) ($progress['missing_done'] ?? true);
            $missingPending = (bool) ($progress['missing_pending'] ?? true);
            $missingPlan = (bool) ($progress['missing_plan'] ?? true);
            if ($missingDone || $missingPending || $missingPlan) {
                $totalsMissing = true;
            }

            $completion = $plan > 0.0 ? (int) min(100, max(0, round(($done / $plan) * 100))) : 0;
            $statusLabel = 'Kısmi';
            $badgeBg = '#ede9fe';
            $badgeText = '#5b21b6';
            $barColor = '#8b5cf6';

            if ($missingDone && $missingPending && $missingPlan) {
                $statusLabel = 'Veri Eksik';
                $badgeBg = '#fee2e2';
                $badgeText = '#b91c1c';
                $barColor = '#ef4444';
                $pendingRows++;
            } elseif ($pending <= 0.0 && $done > 0.0) {
                $statusLabel = 'Tamamlandı';
                $badgeBg = '#dcfce7';
                $badgeText = '#166534';
                $barColor = '#22c55e';
                $completedRows++;
            } elseif ($done <= 0.0 && $pending > 0.0) {
                $statusLabel = 'Başlanmadı';
                $badgeBg = '#fee2e2';
                $badgeText = '#b91c1c';
                $barColor = '#ef4444';
                $pendingRows++;
            } elseif ($pending > 0.0) {
                $pendingRows++;
            }

            $items[] = [
                'code' => trim((string) ($row['faaliyet_kodu'] ?? 'Faaliyet')),
                'title' => static::resolveReportRowTitle($row),
                'unit' => trim((string) ($row['olcu_birimi'] ?? '')),
                'info_level' => trim((string) ($row['baskanlik_bilgilendirme_seviyesi'] ?? '')),
                'done' => $done,
                'pending' => $pending,
                'plan' => $plan,
                'missing_done' => $missingDone,
                'missing_pending' => $missingPending,
                'missing_plan' => $missingPlan,
                'completion' => $completion,
                'status_label' => $statusLabel,
                'badge_bg' => $badgeBg,
                'badge_text' => $badgeText,
                'bar_color' => $barColor,
            ];

            $totalDone += $done;
            $totalPending += $pending;
            $totalPlan += $plan;
        }

        $completion = $totalPlan > 0.0 ? (int) min(100, max(0, round(($totalDone / $totalPlan) * 100))) : 0;
        $totalRows = count($items);

        return [
            'total_done' => $totalDone,
            'total_pending' => $totalPending,
            'total_plan' => $totalPlan,
            'completion' => $completion,
            'total_rows' => $totalRows,
            'completed_rows' => $completedRows,
            'pending_rows' => $pendingRows,
            'totals_missing' => $totalsMissing,
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{
     *   done: float,
     *   pending: float,
     *   plan: float,
     *   missing_done: bool,
     *   missing_pending: bool,
     *   missing_plan: bool
     * }
     */
    private static function resolveProgressFromFaaliyetRow(array $row): array
    {
        $kapsamRows = $row['kapsam_verileri'] ?? null;
        if (is_array($kapsamRows) && $kapsamRows !== []) {
            $plan = 0.0;
            $done = 0.0;
            $hasPlan = false;
            $hasDone = false;
            foreach ($kapsamRows as $kapsamRow) {
                if (! is_array($kapsamRow)) {
                    continue;
                }
                if (static::hasProvidedNumericValue($kapsamRow['ongorulen'] ?? null) || static::hasProvidedNumericValue($kapsamRow['deger'] ?? null)) {
                    $hasPlan = true;
                }
                if (static::hasProvidedNumericValue($kapsamRow['gerceklesen'] ?? null)) {
                    $hasDone = true;
                }
                $plan += static::toFloatNumber($kapsamRow['ongorulen'] ?? $kapsamRow['deger'] ?? 0);
                $done += static::toFloatNumber($kapsamRow['gerceklesen'] ?? 0);
            }

            return [
                'done' => max(0.0, $done),
                'pending' => max(0.0, $plan - $done),
                'plan' => max(0.0, $plan),
                'missing_done' => ! $hasDone,
                'missing_pending' => ! $hasPlan && ! $hasDone,
                'missing_plan' => ! $hasPlan,
            ];
        }

        $targetProvided = static::hasProvidedNumericValue(
            $row['hedef'] ?? $row['ongorulen'] ?? $row['deger'] ?? null
        );
        $doneProvided = static::hasProvidedNumericValue($row['gerceklesen'] ?? null);
        $pendingProvided = static::hasProvidedNumericValue($row['bekleyen_is'] ?? null);
        $target = static::toFloatNumber($row['hedef'] ?? $row['ongorulen'] ?? $row['deger'] ?? 0);
        $done = static::toFloatNumber($row['gerceklesen'] ?? 0);
        $pending = $pendingProvided
            ? static::toFloatNumber($row['bekleyen_is'] ?? 0)
            : ($targetProvided && $doneProvided ? max(0.0, $target - $done) : 0.0);

        $plan = $targetProvided ? max(0.0, $target) : max(0.0, $done + $pending);

        return [
            'done' => max(0.0, $done),
            'pending' => max(0.0, $pending),
            'plan' => $plan,
            'missing_done' => ! $doneProvided,
            'missing_pending' => ! $pendingProvided,
            'missing_plan' => ! $targetProvided && ! $doneProvided && ! $pendingProvided,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function resolveReportRowTitle(array $row): string
    {
        $scope = trim((string) ($row['kapsam_icerigi'] ?? ''));
        if ($scope !== '') {
            return $scope;
        }

        $week = trim((string) ($row['hafta'] ?? ''));
        if ($week !== '') {
            return 'Hafta: '.$week;
        }

        $type = trim((string) ($row['faaliyet_turu'] ?? ''));
        if ($type !== '') {
            return $type.' faaliyet kaydı';
        }

        return 'Kapsam girilmedi';
    }

    private static function hasProvidedNumericValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_int($value) || is_float($value)) {
            return true;
        }
        if (! is_string($value)) {
            return false;
        }

        return trim($value) !== '';
    }

    private static function toFloatNumber(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return 0.0;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return 0.0;
        }

        $normalized = str_replace(["\xc2\xa0", ' '], '', $normalized);
        $hasComma = str_contains($normalized, ',');
        $hasDot = str_contains($normalized, '.');

        if ($hasComma && $hasDot) {
            $lastComma = strrpos($normalized, ',');
            $lastDot = strrpos($normalized, '.');
            if ($lastComma !== false && $lastDot !== false) {
                if ($lastComma > $lastDot) {
                    $normalized = str_replace('.', '', $normalized);
                    $normalized = str_replace(',', '.', $normalized);
                } else {
                    $normalized = str_replace(',', '', $normalized);
                }
            }
        } elseif ($hasComma) {
            $normalized = str_replace(',', '.', $normalized);
        }

        $normalized = preg_replace('/[^0-9.\-]/', '', $normalized);
        if (! is_string($normalized) || $normalized === '' || $normalized === '-' || $normalized === '.') {
            return 0.0;
        }

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    /**
     * @return list<int>
     */
    private static function incomingCoordinationTargetUserIdsForViewer(): array
    {
        $u = auth()->user();
        if (! $u instanceof User) {
            return [];
        }

        if ($u->isReportingSuperAdmin()) {
            return User::queryMudurlukReportingAccounts()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();
        }

        if ($u->isControlTeam()) {
            return $u->assignedDirectorates()
                ->pluck('users.id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();
        }

        $audience = $u->reportAudienceUserIds();
        if (is_array($audience)) {
            return array_values(array_filter(array_map('intval', $audience), fn (int $id): bool => $id > 0));
        }

        return [(int) $u->id];
    }

    private static function coordinationIncomingSummaryForViewer(AylikFaaliyet $record): string
    {
        $targetUserIds = static::incomingCoordinationTargetUserIdsForViewer();

        $rows = is_array($record->faaliyetler) ? $record->faaliyetler : [];
        if ($rows === []) {
            return '—';
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['faaliyet_turu'] ?? null) !== 'Koordinasyon') {
                continue;
            }
            $talepler = $row['isbirligi_talepleri'] ?? [];
            if (! is_array($talepler)) {
                continue;
            }

            $kod = trim((string) ($row['faaliyet_kodu'] ?? 'Koordinasyon'));
            foreach ($talepler as $talep) {
                if (! is_array($talep)) {
                    continue;
                }

                $talepUserId = (int) ($talep['mudurluk_user_id'] ?? 0);
                if ($targetUserIds !== [] && ! in_array($talepUserId, $targetUserIds, true)) {
                    continue;
                }

                $parts = [];
                $ihtiyac = trim((string) ($talep['ihtiyac'] ?? ''));
                $hedefTarih = trim((string) ($talep['hedef_tarih'] ?? ''));
                $bitisSuresi = trim((string) ($talep['bitis_suresi'] ?? ''));

                if ($ihtiyac !== '') {
                    $parts[] = 'İhtiyaç: '.$ihtiyac;
                }
                if ($hedefTarih !== '') {
                    $parts[] = 'Hedef: '.$hedefTarih;
                }
                if ($bitisSuresi !== '') {
                    $parts[] = 'Süre: '.$bitisSuresi;
                }
                if ($parts === []) {
                    continue;
                }

                $out[] = $kod.' -> '.implode(', ', $parts);
            }
        }

        return $out === [] ? '—' : implode(' | ', array_slice($out, 0, 5));
    }

    private static function latestExtraordinarySituationSummary(AylikFaaliyet $record): string
    {
        $last = ExtraordinarySituation::query()
            ->where('target_user_id', (int) $record->user_id)
            ->where('yil', (int) $record->yil)
            ->where('ay', str_pad((string) $record->ay, 2, '0', STR_PAD_LEFT))
            ->latest('id')
            ->first();

        if (! $last instanceof ExtraordinarySituation) {
            return '—';
        }

        $reporter = User::find((int) ($last->reporter_user_id ?? 0));
        $reporterName = $reporter?->name ? trim((string) $reporter->name) : 'Sistem';
        $message = trim((string) $last->message);
        if ($message === '') {
            return $reporterName;
        }

        return $reporterName.': '.$message;
    }

    private static function extraordinarySituationDetailText(?AylikFaaliyet $record): string
    {
        if (! $record instanceof AylikFaaliyet) {
            return '—';
        }

        $rows = ExtraordinarySituation::query()
            ->where('target_user_id', (int) $record->user_id)
            ->where('yil', (int) $record->yil)
            ->where('ay', str_pad((string) $record->ay, 2, '0', STR_PAD_LEFT))
            ->latest('id')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            return '—';
        }

        $blocks = $rows->map(function (ExtraordinarySituation $row): string {
            $reporter = User::find((int) ($row->reporter_user_id ?? 0));
            $reporterName = $reporter?->name ? e($reporter->name) : 'Sistem';
            $message = trim((string) $row->message) !== '' ? e(trim((string) $row->message)) : '—';
            $at = optional($row->created_at)?->format('d.m.Y H:i') ?? '—';

            return '<b>'.$reporterName.'</b> ('.$at.')<br>'.$message;
        })->all();

        return implode('<hr class="my-2">', $blocks);
    }

    private static function presidencyInfoLevelSummary(AylikFaaliyet $record): string
    {
        $rows = is_array($record->faaliyetler) ? $record->faaliyetler : [];
        if ($rows === []) {
            return '—';
        }

        $levels = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $level = trim((string) ($row['baskanlik_bilgilendirme_seviyesi'] ?? ''));
            if ($level === '') {
                continue;
            }
            $levels[$level] = true;
        }

        if ($levels === []) {
            return '—';
        }

        return implode(' | ', array_keys($levels));
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
