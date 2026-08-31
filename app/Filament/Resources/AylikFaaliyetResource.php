<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AylikFaaliyetResource\Pages;
use App\Models\ActivityCatalog;
use App\Models\AylikFaaliyet;
use App\Models\ExtraordinarySituation;
use App\Models\User;
use App\Support\ActivityCatalogFormatter;
use App\Support\ActivityCatalogMetadataByCode;
use App\Support\KapsamIslemTuru;
use App\Support\AylikFaaliyetPeriodMerge;
use App\Support\AylikFaaliyetRepeaterLock;
use App\Support\AylikFaaliyetWeeklyCarryover;
use App\Support\CoordinationAccess;
use App\Support\NonNegativeInput;
use App\Support\OlcuBirimiForKapsam;
use App\Support\QuerySafety;
use App\Support\ReportPeriodWeeks;
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
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class AylikFaaliyetResource extends Resource
{
    protected static ?string $model = AylikFaaliyet::class;

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<int, int> */
    private static array $mudurlukGroupReportCountCache = [];

    /** @var array<string, int> */
    private static array $mudurlukAyGroupReportCountCache = [];

    /** @var array<string, string> */
    private static array $extraordinarySituationSummaryCache = [];

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
        $plan = static::toFloatNumber($get('ongorulen') ?? $get('deger') ?? 0);
        $done = static::toFloatNumber($get('gerceklesen') ?? 0);
        $pending = max(0.0, $plan - $done);
        $set('acikta_kalan', floor($pending) === $pending ? (int) $pending : $pending);
    }

    private static function aciktaKalanDuzenleLabel(float $pending): string
    {
        $count = floor($pending) === $pending ? (int) $pending : (int) ceil($pending);

        return $count.' açık işi düzenle';
    }

    private static function uiFieldHasValue(mixed $state): bool
    {
        return $state !== null && $state !== '';
    }

    private static function hydrateUiFromHiddenField(Set $set, Get $get, mixed $uiState, string $hiddenKey, string $uiKey): void
    {
        if (static::uiFieldHasValue($uiState)) {
            return;
        }

        $stored = $get($hiddenKey);
        if (! filled($stored)) {
            return;
        }

        $set($uiKey, $stored);
    }

    private static function syncHiddenFieldFromUi(Set $set, mixed $state, string $hiddenKey): void
    {
        $set($hiddenKey, $state === '' || $state === null ? null : $state);
    }

    public static function kapsamHasEnteredQuantity(Get $get): bool
    {
        if (static::hasProvidedNumericValue($get('ongorulen') ?? $get('deger'))) {
            return true;
        }

        if (static::hasProvidedNumericValue($get('gerceklesen'))) {
            return true;
        }

        if (static::hasProvidedNumericValue($get('bu_hafta_tamamlanan'))) {
            return true;
        }

        return false;
    }

    public static function kapsamRequiresIslemTuru(Get $get): bool
    {
        return static::kapsamHasEnteredQuantity($get);
    }

    public static function kapsamRequiresDateRange(Get $get): bool
    {
        if (! KapsamIslemTuru::requiresDateRange(KapsamIslemTuru::normalize($get('islem_turu')))) {
            return false;
        }

        return static::kapsamHasEnteredQuantity($get);
    }

    public static function kapsamShowsDateFields(Get $get): bool
    {
        return KapsamIslemTuru::requiresDateRange(KapsamIslemTuru::normalize($get('islem_turu')));
    }

    public static function kapsamDateFieldsEditable(Get $get, mixed $livewire): bool
    {
        if (! static::kapsamKalemVisibleInCurrentWeek($get, $livewire)) {
            return false;
        }

        if (! filled($get('baslangic_tarihi')) || ! filled($get('bitis_tarihi'))) {
            return true;
        }

        if (static::kapsamShowsWeeklyEntryFields($get, $livewire)) {
            return true;
        }

        $user = auth()->user();
        if ($user instanceof User && $user->isReportingSuperAdmin()) {
            return true;
        }

        return false;
    }

    private static function normalizeKapsamDate(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfDay()->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Miktar girilen kalemlerde işlem türü zorunludur; süreç/günlük işlerde tarih aralığı zorunludur.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function enforceKapsamDateRanges(array $data): array
    {
        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        foreach ($data['faaliyetler'] as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $kv = $row['kapsam_verileri'] ?? null;
            if (! is_array($kv)) {
                continue;
            }

            foreach (array_keys($kv) as $j) {
                if (! is_array($data['faaliyetler'][$i]['kapsam_verileri'][$j] ?? null)) {
                    continue;
                }

                $line = &$data['faaliyetler'][$i]['kapsam_verileri'][$j];
                $line['islem_turu'] = KapsamIslemTuru::normalize($line['islem_turu'] ?? null);
                $line['baslangic_tarihi'] = static::normalizeKapsamDate($line['baslangic_tarihi'] ?? null);
                $line['bitis_tarihi'] = static::normalizeKapsamDate($line['bitis_tarihi'] ?? null);

                $hasQuantity = static::hasProvidedNumericValue($line['ongorulen'] ?? $line['deger'] ?? null)
                    || static::hasProvidedNumericValue($line['gerceklesen'] ?? null)
                    || static::hasProvidedNumericValue($line['bu_hafta_tamamlanan'] ?? null);

                if ($hasQuantity && $line['islem_turu'] === null
                    && filled($line['baslangic_tarihi']) && filled($line['bitis_tarihi'])) {
                    $line['islem_turu'] = KapsamIslemTuru::SUREC;
                }

                if ($hasQuantity && $line['islem_turu'] === null) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'data.faaliyetler' => 'Miktar girilen her kalem için işlem türü seçilmelidir.',
                    ]);
                }

                if ($line['islem_turu'] === KapsamIslemTuru::ANLIK) {
                    $line['baslangic_tarihi'] = null;
                    $line['bitis_tarihi'] = null;
                }

                if ($hasQuantity && KapsamIslemTuru::requiresDateRange($line['islem_turu'])
                    && (! filled($line['baslangic_tarihi']) || ! filled($line['bitis_tarihi']))) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'data.faaliyetler' => 'Süreç gerektir ve günlük kalemlerde başlangıç ve bitiş tarihi zorunludur.',
                    ]);
                }

                if (filled($line['baslangic_tarihi']) && filled($line['bitis_tarihi'])
                    && $line['bitis_tarihi'] < $line['baslangic_tarihi']) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'data.faaliyetler' => 'Kalem bitiş tarihi başlangıçtan önce olamaz.',
                    ]);
                }
            }
            unset($line);
        }

        return $data;
    }

    public static function formatKapsamDateRange(?string $start, ?string $end): ?string
    {
        $startLabel = AylikFaaliyetWeeklyCarryover::formatDisplayDate($start);
        $endLabel = AylikFaaliyetWeeklyCarryover::formatDisplayDate($end);
        if ($startLabel && $endLabel) {
            return $startLabel.' – '.$endLabel;
        }

        return $startLabel ?? $endLabel;
    }

    public static function currentReportWeekForForm(Get $get, mixed $livewire = null): int
    {
        $yil = (int) ($get('yil') ?? $get('../../yil') ?? $get('../../../yil') ?? 0);
        $ay = (int) preg_replace('/\D/', '', (string) ($get('ay') ?? $get('../../ay') ?? $get('../../../ay') ?? ''));

        if (($yil <= 0 || $ay < 1 || $ay > 12) && is_object($livewire)) {
            if (method_exists($livewire, 'getRecord')) {
                $record = $livewire->getRecord();
                if ($record instanceof AylikFaaliyet) {
                    $yil = (int) ($record->yil ?? $yil);
                    $ay = (int) preg_replace('/\D/', '', (string) ($record->ay ?? $ay));
                }
            }
            if (($yil <= 0 || $ay < 1) && property_exists($livewire, 'data') && is_array($livewire->data)) {
                $yil = (int) ($livewire->data['yil'] ?? $yil);
                $ay = (int) preg_replace('/\D/', '', (string) ($livewire->data['ay'] ?? $ay));
            }
        }

        if ($yil <= 0 || $ay < 1 || $ay > 12) {
            return 1;
        }

        $selected = $get('hafta') ?? $get('../../hafta') ?? $get('../../../hafta') ?? null;
        if (ReportPeriodWeeks::isMonthlyPeriod($selected)) {
            return 0;
        }
        $selectedWeek = (int) $selected;
        if ($selectedWeek >= 1 && $selectedWeek <= ReportPeriodWeeks::WEEK_COUNT) {
            return $selectedWeek;
        }

        if (is_object($livewire) && property_exists($livewire, 'data') && is_array($livewire->data)) {
            $reportHafta = ReportPeriodWeeks::normalizeReportHafta($livewire->data['hafta'] ?? null);
            if ($reportHafta !== null && ! ReportPeriodWeeks::isMonthlyPeriod($reportHafta)) {
                return (int) $reportHafta;
            }
        }

        return ReportPeriodWeeks::resolveWeekForReportPeriod($yil, $ay);
    }

    public static function isLastReportWeekForForm(Get $get, mixed $livewire = null): bool
    {
        $hafta = $get('hafta') ?? $get('../../hafta') ?? $get('../../../hafta') ?? null;
        if (ReportPeriodWeeks::isMonthlyPeriod($hafta)) {
            return true;
        }

        $week = static::currentReportWeekForForm($get, $livewire);
        if ($week < 1) {
            return false;
        }

        $yil = (int) ($get('yil') ?? $get('../../yil') ?? $get('../../../yil') ?? 0);
        $ay = (int) preg_replace('/\D/', '', (string) ($get('ay') ?? $get('../../ay') ?? $get('../../../ay') ?? ''));

        if (($yil <= 0 || $ay < 1 || $ay > 12) && is_object($livewire)) {
            if (method_exists($livewire, 'getRecord')) {
                $record = $livewire->getRecord();
                if ($record instanceof AylikFaaliyet) {
                    $yil = (int) ($record->yil ?? $yil);
                    $ay = (int) preg_replace('/\D/', '', (string) ($record->ay ?? $ay));
                }
            }
            if (($yil <= 0 || $ay < 1) && property_exists($livewire, 'data') && is_array($livewire->data)) {
                $yil = (int) ($livewire->data['yil'] ?? $yil);
                $ay = (int) preg_replace('/\D/', '', (string) ($livewire->data['ay'] ?? $ay));
            }
        }

        if ($yil <= 0 || $ay < 1 || $ay > 12) {
            return $week >= ReportPeriodWeeks::WEEK_COUNT;
        }

        return ReportPeriodWeeks::isLastWeekOfMonth($week, $yil, $ay);
    }

    public static function selectedRaporHaftasiLabelForFormContext(Get $get, mixed $livewire = null): ?string
    {
        $yil = (int) ($get('yil') ?? $get('../../yil') ?? $get('../../../yil') ?? 0);
        $ay = (int) preg_replace('/\D/', '', (string) ($get('ay') ?? $get('../../ay') ?? $get('../../../ay') ?? ''));

        if (($yil <= 0 || $ay < 1 || $ay > 12) && is_object($livewire)) {
            if (method_exists($livewire, 'getRecord')) {
                $record = $livewire->getRecord();
                if ($record instanceof AylikFaaliyet) {
                    $yil = (int) ($record->yil ?? $yil);
                    $ay = (int) preg_replace('/\D/', '', (string) ($record->ay ?? $ay));
                }
            }
            if (($yil <= 0 || $ay < 1) && property_exists($livewire, 'data') && is_array($livewire->data)) {
                $yil = (int) ($livewire->data['yil'] ?? $yil);
                $ay = (int) preg_replace('/\D/', '', (string) ($livewire->data['ay'] ?? $ay));
            }
        }

        if ($yil <= 0 || $ay < 1 || $ay > 12) {
            return null;
        }

        $week = static::currentReportWeekForForm($get, $livewire);

        return ReportPeriodWeeks::weekLabelForRecord($yil, $ay, $week);
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function resolveReportPeriodFromLivewire(mixed $livewire): array
    {
        $yil = 0;
        $ay = 0;

        if (is_object($livewire)) {
            if (method_exists($livewire, 'getRecord')) {
                $record = $livewire->getRecord();
                if ($record instanceof AylikFaaliyet) {
                    $yil = (int) ($record->yil ?? 0);
                    $ay = (int) preg_replace('/\D/', '', (string) ($record->ay ?? ''));
                }
            }
            if (($yil <= 0 || $ay < 1) && property_exists($livewire, 'data') && is_array($livewire->data)) {
                $yil = (int) ($livewire->data['yil'] ?? $yil);
                $ay = (int) preg_replace('/\D/', '', (string) ($livewire->data['ay'] ?? $ay));
            }
        }

        return [$yil, $ay];
    }

    public static function isMonthlyPeriodForRow(Get $get): bool
    {
        return ReportPeriodWeeks::isMonthlyPeriod(
            $get('hafta') ?? $get('../../hafta') ?? $get('../../../hafta') ?? null
        );
    }

    public static function faaliyetHaftaSelectField(): Forms\Components\Hidden
    {
        return Forms\Components\Hidden::make('hafta')
            ->default(ReportPeriodWeeks::MONTHLY_VALUE)
            ->dehydrated(true);
    }

    private static function applyDefaultHaftaForRow(Set $set, Get $get, mixed $livewire = null): void
    {
        $set('hafta', ReportPeriodWeeks::MONTHLY_VALUE);
    }

    /**
     * Her iş listesi satırında hafta alanını rapor haftasına kilitler.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function hydrateFaaliyetHaftaFields(array $data): array
    {
        unset($data['rapor_haftasi']);
        $data['hafta'] = ReportPeriodWeeks::MONTHLY_VALUE;

        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        $data = AylikFaaliyetWeeklyCarryover::restrictFaaliyetlerToReportHafta($data);

        foreach ($data['faaliyetler'] as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $data['faaliyetler'][$i]['hafta'] = ReportPeriodWeeks::MONTHLY_VALUE;
            unset($data['faaliyetler'][$i]['raporlama_sikligi'], $data['faaliyetler'][$i]['hafta_baslangic'], $data['faaliyetler'][$i]['hafta_bitis']);
        }

        return $data;
    }

    /**
     * @deprecated Use hydrateFaaliyetHaftaFields()
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function hydrateRaporHaftasiField(array $data): array
    {
        return static::hydrateFaaliyetHaftaFields($data);
    }

    public static function reportRecordSavedAtLabel(?AylikFaaliyet $record): ?string
    {
        if (! $record instanceof AylikFaaliyet) {
            return null;
        }

        $at = $record->updated_at ?? $record->created_at;
        if ($at === null) {
            return null;
        }

        return $at->format('d.m.Y H:i');
    }

    /**
     * Rapordaki hafta özeti.
     * $includePeriodSiblings true ise aynı aydaki tüm haftalık raporlar sırayla listelenir (PDF).
     */
    public static function reportAssignedWeeksSummary(?AylikFaaliyet $record, bool $includePeriodSiblings = false): ?string
    {
        if (! $record instanceof AylikFaaliyet) {
            return null;
        }

        $yil = (int) ($record->yil ?? 0);
        $ay = (int) preg_replace('/\D/', '', (string) ($record->ay ?? ''));
        if ($yil <= 0 || $ay < 1 || $ay > 12) {
            return null;
        }

        if ($includePeriodSiblings) {
            $labels = [];
            foreach (static::periodSiblingReports($record) as $sibling) {
                if (! filled($sibling->hafta ?? null)) {
                    continue;
                }
                $label = ReportPeriodWeeks::periodLabelForRecord($yil, $ay, $sibling->hafta);
                if ($label !== null && $label !== '') {
                    $labels[$label] = true;
                }
            }
            if ($labels !== []) {
                return implode(' · ', array_keys($labels));
            }
        }

        if (filled($record->hafta ?? null)) {
            return ReportPeriodWeeks::periodLabelForRecord($yil, $ay, $record->hafta);
        }

        $labels = [];
        $rows = is_array($record->faaliyetler) ? $record->faaliyetler : [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = ReportPeriodWeeks::weekLabelForRecord($yil, $ay, $row['hafta'] ?? null);
            if ($label !== null && $label !== '') {
                $labels[$label] = true;
            }
        }

        if ($labels === []) {
            return null;
        }

        return implode(' · ', array_keys($labels));
    }

    /**
     * @return array<string, mixed>
     */
    private static function kapsamRowStateFromGet(Get $get): array
    {
        return [
            'ongorulen' => $get('ongorulen'),
            'deger' => $get('deger'),
            'gerceklesen' => $get('gerceklesen'),
            'acikta_kalan' => $get('acikta_kalan'),
            'acikta_kapatildi' => $get('acikta_kapatildi'),
            'not_ile_kapatilan' => $get('not_ile_kapatilan'),
            'haftalik_kayitlar' => $get('haftalik_kayitlar'),
        ];
    }

    /**
     * Kayıtlı raporda bu kapsam satırında yapılan iş veritabanında var mı?
     */
    public static function kapsamYapilanIsDbKayitli(Get $get, mixed $livewire): bool
    {
        return static::kapsamDbNumericFieldKayitli($get, $livewire, ['ongorulen', 'deger']);
    }

    /**
     * Kayıtlı raporda bu kapsam satırında tamamlanan iş veritabanında var mı?
     */
    public static function kapsamTamamlananDbKayitli(Get $get, mixed $livewire): bool
    {
        return static::kapsamDbNumericFieldKayitli($get, $livewire, ['gerceklesen']);
    }

    public static function kapsamShowsTamamlananIsField(Get $get, mixed $livewire): bool
    {
        if (static::faaliyetRowShowsAySonuPerformansFields($get, $livewire)) {
            return true;
        }

        if (static::kapsamShowsWeeklyEntryFields($get, $livewire)
            && static::kapsamYapilanIsDbKayitli($get, $livewire)) {
            return true;
        }

        // Son hafta: tamamlanan hiç girilmediyse hâlâ girilebilir.
        return static::isLastReportWeekForForm($get, $livewire)
            && static::kapsamYapilanIsDbKayitli($get, $livewire)
            && ! static::kapsamTamamlananDbKayitli($get, $livewire)
            && static::kapsamHasPendingWork($get);
    }

    public static function kapsamAciktaKapatildiDbKayitli(Get $get, mixed $livewire): bool
    {
        return static::kapsamDbFieldKayitli($get, $livewire, 'acikta_kapatildi', static fn (mixed $v): bool => (bool) $v);
    }

    public static function kapsamShowsAciktaKapatmaAlani(Get $get, mixed $livewire): bool
    {
        if (! static::kapsamHasPendingWork($get)) {
            return false;
        }
        if (! $livewire instanceof EditRecord) {
            return false;
        }
        if (trim((string) (AylikFaaliyetRepeaterLock::resolveFaaliyetRowOrigIndex($get) ?? '')) === '') {
            return false;
        }
        if (! static::kapsamYapilanIsDbKayitli($get, $livewire)) {
            return false;
        }
        if (static::kapsamAciktaKapatildiDbKayitli($get, $livewire)) {
            return false;
        }

        return true;
    }

    public static function kapsamShowsAciktaKapatmaOzet(Get $get, mixed $livewire): bool
    {
        return static::kapsamAciktaKapatildiDbKayitli($get, $livewire);
    }

    /**
     * @param  callable(mixed): bool|null  $isFilled
     */
    private static function kapsamDbFieldKayitli(Get $get, mixed $livewire, string $field, ?callable $isFilled = null): bool
    {
        if (! $livewire instanceof EditRecord) {
            return false;
        }

        $origIdx = trim((string) (AylikFaaliyetRepeaterLock::resolveFaaliyetRowOrigIndex($get) ?? ''));
        if ($origIdx === '' || ! is_numeric($origIdx)) {
            return false;
        }

        $record = $livewire->getRecord();
        if (! $record instanceof AylikFaaliyet) {
            return false;
        }

        $faaliyetler = is_array($record->faaliyetler) ? array_values($record->faaliyetler) : [];
        $row = $faaliyetler[(int) $origIdx] ?? null;
        if (! is_array($row)) {
            return false;
        }

        $kalem = trim((string) ($get('kalem') ?? ''));
        $kv = $row['kapsam_verileri'] ?? null;
        if (is_array($kv) && $kv !== [] && $kalem !== '') {
            foreach ($kv as $line) {
                if (! is_array($line)) {
                    continue;
                }
                if (trim((string) ($line['kalem'] ?? '')) !== $kalem) {
                    continue;
                }

                $val = $line[$field] ?? null;

                return $isFilled !== null ? $isFilled($val) : filled($val);
            }

            return false;
        }

        $val = $row[$field] ?? null;

        return $isFilled !== null ? $isFilled($val) : filled($val);
    }

    /**
     * @param  list<string>  $fields
     */
    private static function kapsamDbNumericFieldKayitli(Get $get, mixed $livewire, array $fields): bool
    {
        if (! $livewire instanceof EditRecord) {
            return false;
        }

        $origIdx = trim((string) (AylikFaaliyetRepeaterLock::resolveFaaliyetRowOrigIndex($get) ?? ''));
        if ($origIdx === '' || ! is_numeric($origIdx)) {
            return false;
        }

        $record = $livewire->getRecord();
        if (! $record instanceof AylikFaaliyet) {
            return false;
        }

        $faaliyetler = is_array($record->faaliyetler) ? array_values($record->faaliyetler) : [];
        $row = $faaliyetler[(int) $origIdx] ?? null;
        if (! is_array($row)) {
            return false;
        }

        $kalem = trim((string) ($get('kalem') ?? ''));
        $kv = $row['kapsam_verileri'] ?? null;
        if (is_array($kv) && $kv !== [] && $kalem !== '') {
            foreach ($kv as $line) {
                if (! is_array($line)) {
                    continue;
                }
                if (trim((string) ($line['kalem'] ?? '')) !== $kalem) {
                    continue;
                }
                foreach ($fields as $field) {
                    if (static::hasProvidedNumericValue($line[$field] ?? null)) {
                        return true;
                    }
                }

                return false;
            }

            return false;
        }

        foreach ($fields as $field) {
            if (static::hasProvidedNumericValue($row[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    public static function kapsamShowsWeeklyEntryFields(Get $get, mixed $livewire): bool
    {
        if (! static::kapsamKalemVisibleInCurrentWeek($get, $livewire)) {
            return false;
        }

        return ! static::kapsamShowsWeeklyFollowUpFields($get, $livewire);
    }

    public static function kapsamOngorulenEditable(Get $get, mixed $livewire): bool
    {
        if (trim((string) (AylikFaaliyetRepeaterLock::resolveFaaliyetRowOrigIndex($get) ?? '')) === '') {
            return true;
        }

        if (! static::kapsamYapilanIsDbKayitli($get, $livewire)) {
            return true;
        }

        $u = auth()->user();
        if ($u instanceof User && $u->isReportingSuperAdmin()) {
            return true;
        }

        return false;
    }

    public static function kapsamGerceklesenEditable(Get $get, mixed $livewire): bool
    {
        if (static::faaliyetRowShowsAySonuPerformansFields($get, $livewire)) {
            $u = auth()->user();
            if ($u instanceof User && $u->isReportingSuperAdmin()) {
                return true;
            }

            return ! AylikFaaliyetRepeaterLock::resolveFaaliyetRowAySonuPerformansKilitli($get);
        }

        if (! static::kapsamYapilanIsDbKayitli($get, $livewire)) {
            return false;
        }

        // Açıkta kalan varken tamamlanan tekrar düzenlenebilir (kilitli satırda da kapanış mümkün olsun).
        if (static::kapsamHasPendingWork($get)
            && ! (bool) ($get('acikta_kapatildi') ?? false)) {
            return true;
        }

        if (static::kapsamTamamlananDbKayitli($get, $livewire)) {
            $u = auth()->user();
            if ($u instanceof User && $u->isReportingSuperAdmin()) {
                return true;
            }

            return false;
        }

        return static::kapsamShowsWeeklyEntryFields($get, $livewire);
    }

    /**
     * Açıkta iş revize notu/tarihi kayıtlı satırda bir kez girilir.
     */
    public static function kapsamRevizeAlaniDisabled(Get $get, mixed $livewire): bool
    {
        if (trim((string) (AylikFaaliyetRepeaterLock::resolveFaaliyetRowOrigIndex($get) ?? '')) === '') {
            return false;
        }
        if (static::isLastReportWeekForForm($get, $livewire) && static::kapsamHasPendingWork($get)) {
            return false;
        }
        if (! filled($get('acikta_revize_notu')) && ! filled($get('acikta_revize_tarihi'))) {
            return false;
        }
        $u = auth()->user();
        if ($u instanceof User && $u->isReportingSuperAdmin()) {
            return false;
        }

        return true;
    }

    public static function kapsamHasPendingWork(Get $get): bool
    {
        return AylikFaaliyetWeeklyCarryover::kapsamPendingAmount(static::kapsamRowStateFromGet($get)) > 0.0;
    }

    public static function kapsamShowsWeeklyFollowUpFields(Get $get, mixed $livewire): bool
    {
        // Her hafta ayrı rapor: takip-sadece (önceki haftadan açık iş) modu yok.
        return false;
    }

    public static function kapsamKalemVisibleInCurrentWeek(Get $get, mixed $livewire): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareFaaliyetlerForSave(array $data, ?AylikFaaliyet $record, ?User $user): array
    {
        $mudurluk = $user?->name ?? $record?->user?->name ?? auth()->user()?->name ?? null;
        $data = static::syncFaaliyetlerWithCurrentCatalog($data, is_string($mudurluk) ? $mudurluk : null);

        if ($user instanceof User && $record instanceof AylikFaaliyet) {
            $data = AylikFaaliyetRepeaterLock::stripAySonuFieldsFromUnpersistedMudurlukRows($record, $user, $data);
        }

        $data = AylikFaaliyetRepeaterLock::relaxPerformansKilitWhilePending($data);
        $data = AylikFaaliyetRepeaterLock::clampNonNegativeNumericFaaliyetler($data);
        $data = AylikFaaliyetWeeklyCarryover::applyAciktaKapatma($data);
        $data = static::enforceKapsamDateRanges($data);
        $data = AylikFaaliyetWeeklyCarryover::applyWeeklyEntries($data);
        $data = AylikFaaliyetWeeklyCarryover::consolidateFaaliyetRowsByCatalog($data);

        if ($user instanceof User && $record instanceof AylikFaaliyet) {
            $data = AylikFaaliyetRepeaterLock::enforceMudurlukLocks($record, $user, $data);
            $data = AylikFaaliyetRepeaterLock::applyAySonuPerformansKilitAfterMudurlukSave($record, $user, $data);
        }

        $data = AylikFaaliyetRepeaterLock::syncRowAySonuTotalsFromKapsamVerileri($data);

        return AylikFaaliyetWeeklyCarryover::restrictFaaliyetlerToReportHafta(
            static::applyAutoHaftaToFaaliyetler(
                AylikFaaliyetRepeaterLock::stripInternalKeysFromFaaliyetler($data)
            )
        );
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

    private static function resolveOlcuBirimiForKapsamRow(Get $get): ?string
    {
        $parentUnit = static::resolveOlcuBirimiForRow($get);
        if ($parentUnit === null) {
            return null;
        }

        $kalem = trim((string) ($get('kalem') ?? ''));

        return OlcuBirimiForKapsam::resolve(
            $kalem,
            $parentUnit,
            static::resolveKapsamKalemIndex($get)
        );
    }

    private static function resolveKapsamKalemIndex(Get $get): int
    {
        $kalem = trim((string) ($get('kalem') ?? ''));
        if ($kalem === '') {
            return 0;
        }

        $kv = $get('../../kapsam_verileri');
        if (! is_array($kv)) {
            $kv = $get('../kapsam_verileri');
        }
        if (! is_array($kv)) {
            return 0;
        }

        foreach (array_values($kv) as $index => $line) {
            if (! is_array($line)) {
                continue;
            }
            if (trim((string) ($line['kalem'] ?? '')) === $kalem) {
                return (int) $index;
            }
        }

        return 0;
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
                                ->default(now()->year)
                                ->required()
                                ->live(),
                            Forms\Components\Select::make('ay')
                                ->options(['01' => 'Ocak', '02' => 'Şubat', '03' => 'Mart', '04' => 'Nisan', '05' => 'Mayıs', '06' => 'Haziran', '07' => 'Temmuz', '08' => 'Ağustos', '09' => 'Eylül', '10' => 'Ekim', '11' => 'Kasım', '12' => 'Aralık'])
                                ->default(now()->format('m'))
                                ->required()
                                ->live(),
                        ]),
                        Forms\Components\Hidden::make('hafta')
                            ->default(ReportPeriodWeeks::MONTHLY_VALUE)
                            ->dehydrated(true),
                        Forms\Components\Placeholder::make('donem_tarih_araligi')
                            ->label('Seçili Dönem')
                            ->content(function (Get $get): string {
                                $yil = (int) ($get('yil') ?? now()->year);
                                $ay = (int) preg_replace('/\D/', '', (string) ($get('ay') ?? now()->format('m')));

                                if ($yil <= 0 || $ay < 1 || $ay > 12) {
                                    return '—';
                                }

                                return ReportPeriodWeeks::monthPeriodLabel($yil, $ay);
                            }),
                        Forms\Components\Placeholder::make('rapor_kayit_bilgisi')
                            ->label('Sisteme Kayıt')
                            ->content(function ($livewire): string {
                                if (! is_object($livewire) || ! method_exists($livewire, 'getRecord')) {
                                    return 'Kayıt sonrası atanır.';
                                }
                                $record = $livewire->getRecord();
                                if (! $record instanceof AylikFaaliyet || ! $record->exists) {
                                    return 'Kayıt sonrası atanır.';
                                }
                                $savedAt = static::reportRecordSavedAtLabel($record) ?? '—';

                                return 'Kayıt tarihi: '.$savedAt;
                            })
                            ->helperText('Tarih raporun sisteme son kaydedildiği anı gösterir.')
                            ->visible(fn ($livewire): bool => is_object($livewire)
                                && method_exists($livewire, 'getRecord')
                                && ($livewire->getRecord() instanceof AylikFaaliyet)
                                && $livewire->getRecord()->exists)
                            ->columnSpanFull(),
                    ])->compact(),
                Section::make('Uyarı')
                    ->schema([
                        Forms\Components\Placeholder::make('rapor_olusturma_uyarisi')
                            ->content('Her müdürlük için aynı yıl/ay döneminde yalnızca bir rapor açılabilir. Mevcut rapor varsa düzenleme ekranına yönlendirilirsiniz.')
                            ->extraAttributes(['class' => 'text-amber-700'])
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($livewire): bool => $livewire instanceof \Filament\Resources\Pages\CreateRecord)
                    ->compact(),
                Section::make('Ay Sonu Uyarısı')
                    ->schema([
                        Forms\Components\Placeholder::make('ay_sonu_kapanis_uyarisi')
                            ->content('Ayın son günündesiniz. Ay sonu gerçekleşen / açıkta bekleyen alanlarını doldurabilirsiniz; boş bırakılan alanlar 0 yazılmadan kaydedilir.')
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

                                            $set('olcu_birimi', $catalog->olcu_birimi);
                                            $metadata = ActivityCatalogMetadataByCode::mergeWithCatalog(
                                                (string) $catalog->faaliyet_kodu,
                                                '',
                                                (string) ($catalog->baskanlik_bilgilendirme_seviyesi ?? '')
                                            );
                                            $set('baskanlik_bilgilendirme_seviyesi', $metadata['baskanlik_bilgilendirme_seviyesi']);
                                            $set('faaliyet_kodu', $catalog->faaliyet_kodu);
                                            $set('kapsam_icerigi', $catalog->faaliyet_ailesi);

                                            $set(
                                                'kapsam_verileri',
                                                static::syncKapsamVerileri(
                                                    static::parseKapsamKalemleri((string) ($catalog->kapsam ?? '')),
                                                    $get('kapsam_verileri')
                                                )
                                            );
                                            static::applyDefaultHaftaForRow($set, $get);
                                        })
                                        ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                            $catalog = ActivityCatalog::find($state);
                                            if ($catalog) {
                                                $set('olcu_birimi', $catalog->olcu_birimi);
                                                $metadata = ActivityCatalogMetadataByCode::mergeWithCatalog(
                                                    (string) $catalog->faaliyet_kodu,
                                                    '',
                                                    (string) ($catalog->baskanlik_bilgilendirme_seviyesi ?? '')
                                                );
                                                $set('baskanlik_bilgilendirme_seviyesi', $metadata['baskanlik_bilgilendirme_seviyesi']);
                                                $set('faaliyet_kodu', $catalog->faaliyet_kodu);
                                                $set('kapsam_icerigi', $catalog->faaliyet_ailesi);
                                                $set(
                                                    'kapsam_verileri',
                                                    static::syncKapsamVerileri(
                                                        static::parseKapsamKalemleri((string) ($catalog->kapsam ?? '')),
                                                        $get('kapsam_verileri')
                                                    )
                                                );
                                                static::applyDefaultHaftaForRow($set, $get);
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

                                static::faaliyetHaftaSelectField(),

                                Repeater::make('kapsam_verileri')
                                    ->label('Kapsam kalemleri')
                                    ->helperText('İş varsa miktarı girin; yoksa alanı boş bırakın (0 yazmayın). Miktar girilen kalemlerde işlem türü zorunludur; süreç ve günlük işlerde tarih aralığı girilir.')
                                    ->dehydrated()
                                    ->schema([
                                        Forms\Components\Hidden::make('kalem')->dehydrated(true),
                                        Forms\Components\Hidden::make('ongorulen')
                                            ->dehydrateStateUsing(fn ($state) => NonNegativeInput::normalizeIntegerScalar($state))
                                            ->dehydrated(true),
                                        Forms\Components\Hidden::make('gerceklesen')
                                            ->dehydrateStateUsing(fn ($state) => NonNegativeInput::normalizeIntegerScalar($state))
                                            ->dehydrated(true),
                                        Forms\Components\Hidden::make('son_yapilma_tarihi')->dehydrated(true),
                                        Forms\Components\Hidden::make('haftalik_kayitlar')->dehydrated(true),
                                        Forms\Components\Hidden::make('deger')->dehydrated(true),
                                        Forms\Components\Hidden::make('acikta_kalan')
                                            ->dehydrateStateUsing(fn ($state) => NonNegativeInput::normalizeScalar($state))
                                            ->dehydrated(true),
                                        Forms\Components\Hidden::make('acikta_kapatildi')->dehydrated(true),
                                        Forms\Components\Hidden::make('acikta_kapatma_notu')->dehydrated(true),
                                        Forms\Components\Hidden::make('acikta_revize_tarihi')->dehydrated(true),
                                        Forms\Components\Hidden::make('acikta_revize_notu')->dehydrated(true),
                                        Forms\Components\Hidden::make('bu_hafta_tamamlanan')
                                            ->dehydrateStateUsing(fn ($state) => NonNegativeInput::normalizeIntegerScalar($state))
                                            ->dehydrated(true),
                                        Forms\Components\Hidden::make('bu_hafta_aciklama')->dehydrated(true),
                                        Forms\Components\Hidden::make('bu_hafta_yapilma_tarihi')->dehydrated(true),
                                        Forms\Components\Hidden::make('baslangic_tarihi')->dehydrated(true),
                                        Forms\Components\Hidden::make('bitis_tarihi')->dehydrated(true),
                                        Forms\Components\Hidden::make('islem_turu')->dehydrated(true),
                                        Forms\Components\Placeholder::make('kalem_bu_hafta_kapali')
                                            ->label(fn (Get $get): string => trim((string) ($get('kalem') ?? 'Kalem')))
                                            ->content('Bu kalem bu hafta rapor döneminde açıkta iş bulunmadığı için gizlidir. Veriler korunur.')
                                            ->visible(fn (Get $get, $livewire): bool => ! static::kapsamKalemVisibleInCurrentWeek($get, $livewire))
                                            ->columnSpanFull(),
                                        Grid::make(4)->schema([
                                            Forms\Components\Placeholder::make('kalem_goster')
                                                ->label('Kalem')
                                                ->content(fn (Get $get): string => trim((string) ($get('kalem') ?? '—')))
                                                ->extraAttributes(['class' => 'bg-gray-50 whitespace-normal break-words'])
                                                ->visible(fn (Get $get, $livewire): bool => static::kapsamKalemVisibleInCurrentWeek($get, $livewire)),
                                            Forms\Components\TextInput::make('ongorulen_ui')
                                                ->label('Yapılan İş')
                                                ->suffix(fn (Get $get): ?string => static::resolveOlcuBirimiForKapsamRow($get))
                                                ->numeric()
                                                ->minValue(0)
                                                ->rules(['nullable', 'integer', 'min:0'])
                                                ->extraInputAttributes([
                                                    'min' => 0,
                                                    'step' => 1,
                                                    'inputmode' => 'numeric',
                                                    'pattern' => '[0-9]*',
                                                    'style' => 'min-height: 3rem; padding-top: 0.75rem; padding-bottom: 0.75rem; font-size: 1.0625rem;',
                                                ])
                                                ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                                                    static::syncHiddenFieldFromUi($set, $state, 'ongorulen');
                                                    $plan = static::toFloatNumber($state ?? $get('deger') ?? 0);
                                                    $done = static::toFloatNumber($get('gerceklesen') ?? 0);
                                                    $pending = max(0.0, $plan - $done);
                                                    $set('acikta_kalan', floor($pending) === $pending ? (int) $pending : $pending);
                                                })
                                                ->afterStateHydrated(fn (Set $set, Get $get, $state): mixed => static::hydrateUiFromHiddenField($set, $get, $state, 'ongorulen', 'ongorulen_ui'))
                                                ->helperText('Boş bırakılabilir; iş yoksa 0 yazmayın.')
                                                ->readOnly(fn (Get $get, $livewire): bool => ! static::kapsamOngorulenEditable($get, $livewire))
                                                ->disabled(fn (Get $get, $livewire): bool => ! static::kapsamOngorulenEditable($get, $livewire))
                                                ->visible(fn (Get $get, $livewire): bool => static::kapsamKalemVisibleInCurrentWeek($get, $livewire)
                                                    && (static::kapsamShowsWeeklyEntryFields($get, $livewire)
                                                        || static::faaliyetRowShowsAySonuPerformansFields($get, $livewire)
                                                        || static::kapsamYapilanIsDbKayitli($get, $livewire)))
                                                ->dehydrated(false),
                                            Forms\Components\Placeholder::make('ongorulen_ozet')
                                                ->label('Yapılan İş')
                                                ->content(function (Get $get): string {
                                                    $val = $get('ongorulen') ?? $get('deger') ?? '—';

                                                    return (string) $val;
                                                })
                                                ->visible(fn (Get $get, $livewire): bool => static::kapsamKalemVisibleInCurrentWeek($get, $livewire)
                                                    && static::kapsamShowsWeeklyFollowUpFields($get, $livewire)),
                                            Forms\Components\TextInput::make('gerceklesen_ui')
                                                ->label('Tamamlanan İş')
                                                ->suffix(fn (Get $get): ?string => static::resolveOlcuBirimiForKapsamRow($get))
                                                ->numeric()
                                                ->minValue(0)
                                                ->rules(['nullable', 'integer', 'min:0'])
                                                ->extraInputAttributes(['min' => 0, 'step' => 1, 'inputmode' => 'numeric', 'pattern' => '[0-9]*'])
                                                ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                                                    static::syncHiddenFieldFromUi($set, $state, 'gerceklesen');
                                                    $plan = static::toFloatNumber($get('ongorulen') ?? $get('deger') ?? 0);
                                                    $done = static::toFloatNumber($state ?? 0);
                                                    $pending = max(0.0, $plan - $done);
                                                    $set('acikta_kalan', floor($pending) === $pending ? (int) $pending : $pending);
                                                })
                                                ->afterStateHydrated(fn (Set $set, Get $get, $state): mixed => static::hydrateUiFromHiddenField($set, $get, $state, 'gerceklesen', 'gerceklesen_ui'))
                                                ->helperText('Boş bırakılabilir; tamamlanan yoksa 0 yazmayın.')
                                                ->visible(fn (Get $get, $livewire): bool => static::kapsamKalemVisibleInCurrentWeek($get, $livewire)
                                                    && static::kapsamShowsTamamlananIsField($get, $livewire))
                                                ->dehydrated(false)
                                                ->disabled(fn (Get $get, $livewire): bool => ! static::kapsamGerceklesenEditable($get, $livewire)),
                                            Forms\Components\Placeholder::make('gerceklesen_ozet')
                                                ->label('Tamamlanan İş (toplam)')
                                                ->content(function (Get $get): string {
                                                    $val = $get('gerceklesen') ?? '0';

                                                    return (string) $val;
                                                })
                                                ->visible(fn (Get $get, $livewire): bool => static::kapsamKalemVisibleInCurrentWeek($get, $livewire)
                                                    && static::kapsamShowsWeeklyFollowUpFields($get, $livewire)),
                                            Forms\Components\Placeholder::make('acikta_kalan_goster')
                                                ->label('Açıkta Bekleyen İş')
                                                ->content(function (Get $get): HtmlString {
                                                    $pending = AylikFaaliyetWeeklyCarryover::kapsamPendingAmount(static::kapsamRowStateFromGet($get));

                                                    if ($pending <= 0.0) {
                                                        return new HtmlString(e('0'));
                                                    }

                                                    $label = static::aciktaKalanDuzenleLabel($pending);

                                                    return new HtmlString(
                                                        '<button type="button" class="text-primary-600 font-semibold underline decoration-dotted underline-offset-2 cursor-pointer"'
                                                        .' x-data x-on:click="'
                                                        .'$el.closest(\'.fi-fo-repeater-item\')?.querySelector(\'[data-acikta-kapat-panel]\')?.scrollIntoView({behavior:\'smooth\',block:\'nearest\'});'
                                                        .'$el.closest(\'.fi-fo-repeater-item\')?.querySelector(\'[data-acikta-kapat-panel] .fi-section-header\')?.click();'
                                                        .'">'
                                                        .e($label)
                                                        .'</button>'
                                                        .'<div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Tıklayarak not ile kapat revizesi yapın</div>'
                                                    );
                                                })
                                                ->helperText('Yapılan iş − tamamlanan iş (eşit değilse açıkta kalan).')
                                                ->visible(fn (Get $get, $livewire): bool => static::kapsamKalemVisibleInCurrentWeek($get, $livewire)
                                                    && static::kapsamYapilanIsDbKayitli($get, $livewire)
                                                    && (static::kapsamShowsTamamlananIsField($get, $livewire)
                                                        || static::kapsamShowsWeeklyFollowUpFields($get, $livewire)
                                                        || static::kapsamHasPendingWork($get))),
                                        ]),
                                        Forms\Components\Select::make('islem_turu_ui')
                                            ->label('İşlem Türü')
                                            ->options(KapsamIslemTuru::options())
                                            ->placeholder('Seçiniz')
                                            ->native(false)
                                            ->required(fn (Get $get): bool => static::kapsamRequiresIslemTuru($get))
                                            ->live()
                                            ->visible(fn (Get $get, $livewire): bool => static::kapsamKalemVisibleInCurrentWeek($get, $livewire))
                                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                                $normalized = KapsamIslemTuru::normalize($state);
                                                $set('islem_turu', $normalized);
                                                if (! KapsamIslemTuru::requiresDateRange($normalized)) {
                                                    $set('baslangic_tarihi', null);
                                                    $set('bitis_tarihi', null);
                                                    $set('baslangic_tarihi_ui', null);
                                                    $set('bitis_tarihi_ui', null);
                                                }
                                            })
                                            ->afterStateHydrated(fn (Set $set, Get $get, mixed $state): mixed => static::hydrateUiFromHiddenField($set, $get, $state, 'islem_turu', 'islem_turu_ui'))
                                            ->dehydrated(false)
                                            ->columnSpanFull(),
                                        Grid::make(2)->schema([
                                            Forms\Components\DatePicker::make('baslangic_tarihi_ui')
                                                ->label('Başlangıç Tarihi')
                                                ->native(false)
                                                ->displayFormat('d.m.Y')
                                                ->required(fn (Get $get): bool => static::kapsamRequiresDateRange($get))
                                                ->visible(fn (Get $get, $livewire): bool => static::kapsamKalemVisibleInCurrentWeek($get, $livewire)
                                                    && static::kapsamShowsDateFields($get))
                                                ->disabled(fn (Get $get, $livewire): bool => ! static::kapsamDateFieldsEditable($get, $livewire))
                                                ->afterStateUpdated(function (Set $set, mixed $state): void {
                                                    $set('baslangic_tarihi', static::normalizeKapsamDate($state));
                                                })
                                                ->afterStateHydrated(fn (Set $set, Get $get, mixed $state): mixed => static::hydrateUiFromHiddenField($set, $get, $state, 'baslangic_tarihi', 'baslangic_tarihi_ui'))
                                                ->dehydrated(false),
                                            Forms\Components\DatePicker::make('bitis_tarihi_ui')
                                                ->label('Bitiş Tarihi')
                                                ->native(false)
                                                ->displayFormat('d.m.Y')
                                                ->required(fn (Get $get): bool => static::kapsamRequiresDateRange($get))
                                                ->afterOrEqual('baslangic_tarihi_ui')
                                                ->visible(fn (Get $get, $livewire): bool => static::kapsamKalemVisibleInCurrentWeek($get, $livewire)
                                                    && static::kapsamShowsDateFields($get))
                                                ->disabled(fn (Get $get, $livewire): bool => ! static::kapsamDateFieldsEditable($get, $livewire))
                                                ->afterStateUpdated(function (Set $set, mixed $state): void {
                                                    $set('bitis_tarihi', static::normalizeKapsamDate($state));
                                                })
                                                ->afterStateHydrated(fn (Set $set, Get $get, mixed $state): mixed => static::hydrateUiFromHiddenField($set, $get, $state, 'bitis_tarihi', 'bitis_tarihi_ui'))
                                                ->dehydrated(false),
                                        ])->columnSpanFull()
                                            ->visible(fn (Get $get, $livewire): bool => static::kapsamKalemVisibleInCurrentWeek($get, $livewire)
                                                && static::kapsamShowsDateFields($get)),
                                        Section::make('Açıkta İş Kapat Revizesi')
                                            ->description('Her kalemde açık işi not ile kapatabilirsiniz. Örn: 10 işten 7 tamamlandı, 3 kaldı → bu hafta 2’sini not ile kapatıp 1’ini sonraki haftaya bırakın.')
                                            ->schema([
                                                Forms\Components\Hidden::make('acikta_kapanis_miktar')->dehydrated(true),
                                                Forms\Components\Hidden::make('acikta_not_kapat_miktar')->dehydrated(true),
                                                Forms\Components\Hidden::make('not_ile_kapatilan')->dehydrated(true),
                                                Forms\Components\Hidden::make('kalan_acik_tamamla')->default(false)->dehydrated(true),
                                                Forms\Components\TextInput::make('acikta_kapanis_miktar_ui')
                                                    ->label('Kapanışta tamamlanan miktar')
                                                    ->helperText('Girilen sayı tamamlanan işe eklenir. Kalan sıfırlanırsa bekleyen iş raporda kapanır.')
                                                    ->numeric()
                                                    ->minValue(0)
                                                    ->rules(['nullable', 'integer', 'min:0'])
                                                    ->extraInputAttributes(['min' => 0, 'step' => 1, 'inputmode' => 'numeric', 'pattern' => '[0-9]*'])
                                                    ->live(onBlur: true)
                                                    ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                                        static::syncHiddenFieldFromUi($set, $state, 'acikta_kapanis_miktar');
                                                        $amount = static::toFloatNumber($state ?? 0);
                                                        if ($amount <= 0.0) {
                                                            return;
                                                        }
                                                        $pending = AylikFaaliyetWeeklyCarryover::kapsamPendingAmount(static::kapsamRowStateFromGet($get));
                                                        if ($amount >= $pending && $pending > 0.0) {
                                                            $set('acikta_is_kapatiliyor', false);
                                                        }
                                                    })
                                                    ->afterStateHydrated(fn (Set $set, Get $get, $state): mixed => static::hydrateUiFromHiddenField($set, $get, $state, 'acikta_kapanis_miktar', 'acikta_kapanis_miktar_ui'))
                                                    ->dehydrated(false),
                                                Forms\Components\Toggle::make('acikta_is_kapatiliyor')
                                                    ->label('Açıkta kalan işi not ile kapat (revize)')
                                                    ->helperText('Zorunlu not ile açık işi kapatır; tamamlanan artmaz. İsterseniz yalnızca bir kısmını kapatıp kalanı sonraki haftaya bırakın.')
                                                    ->live()
                                                    ->dehydrated(true)
                                                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                                                        if ($state) {
                                                            $set('kalan_acik_tamamla', false);
                                                        }
                                                    }),
                                                Forms\Components\TextInput::make('acikta_not_kapat_miktar_ui')
                                                    ->label(function (Get $get): string {
                                                        $pending = AylikFaaliyetWeeklyCarryover::kapsamPendingAmount(static::kapsamRowStateFromGet($get));
                                                        $amount = floor($pending) === $pending ? (string) (int) $pending : (string) $pending;

                                                        return 'Not ile kapatılacak miktar (açık: '.$amount.')';
                                                    })
                                                    ->helperText('Boş bırakırsanız kalanın tamamı kapanır. Örn: açık 3 ise 2 yazıp 1’ini sonraki haftaya bırakabilirsiniz.')
                                                    ->numeric()
                                                    ->minValue(0)
                                                    ->rules(['nullable', 'integer', 'min:0'])
                                                    ->extraInputAttributes(['min' => 0, 'step' => 1, 'inputmode' => 'numeric', 'pattern' => '[0-9]*'])
                                                    ->visible(fn (Get $get): bool => (bool) ($get('acikta_is_kapatiliyor') ?? false))
                                                    ->live(onBlur: true)
                                                    ->afterStateUpdated(fn (Set $set, $state): mixed => static::syncHiddenFieldFromUi($set, $state, 'acikta_not_kapat_miktar'))
                                                    ->afterStateHydrated(fn (Set $set, Get $get, $state): mixed => static::hydrateUiFromHiddenField($set, $get, $state, 'acikta_not_kapat_miktar', 'acikta_not_kapat_miktar_ui'))
                                                    ->dehydrated(false),
                                                Forms\Components\Textarea::make('acikta_kapatma_notu_ui')
                                                    ->label('Kapat Revize Notu')
                                                    ->placeholder('Açıkta kalan işin neden kapatıldığını / revize gerekçesini yazınız...')
                                                    ->rows(3)
                                                    ->columnSpanFull()
                                                    ->required(fn (Get $get): bool => (bool) ($get('acikta_is_kapatiliyor') ?? false))
                                                    ->validationMessages([
                                                        'required' => 'Açıkta işi kapatmak için kapat revize notu zorunludur.',
                                                    ])
                                                    ->visible(fn (Get $get): bool => (bool) ($get('acikta_is_kapatiliyor') ?? false))
                                                    ->live(onBlur: true)
                                                    ->afterStateUpdated(fn (Set $set, $state): mixed => static::syncHiddenFieldFromUi($set, $state, 'acikta_kapatma_notu'))
                                                    ->afterStateHydrated(fn (Set $set, Get $get, $state): mixed => static::hydrateUiFromHiddenField($set, $get, $state, 'acikta_kapatma_notu', 'acikta_kapatma_notu_ui'))
                                                    ->dehydrated(false),
                                            ])
                                            ->visible(fn (Get $get, $livewire): bool => static::kapsamKalemVisibleInCurrentWeek($get, $livewire)
                                                && static::kapsamShowsAciktaKapatmaAlani($get, $livewire))
                                            ->collapsible()
                                            ->collapsed(false)
                                            ->extraAttributes(['data-acikta-kapat-panel' => 'true'])
                                            ->columnSpanFull(),
                                        Forms\Components\Placeholder::make('acikta_kapatma_ozet')
                                            ->label('Açık İş Kapat Revizesi')
                                            ->content(function (Get $get): HtmlString {
                                                $note = trim((string) ($get('acikta_kapatma_notu') ?? $get('acikta_revize_notu') ?? ''));

                                                return new HtmlString(
                                                    '<span class="text-success-600 dark:text-success-400 font-medium">Açıkta kalan iş not ile kapatıldı (revize).</span>'
                                                    .($note !== '' ? '<div class="text-sm mt-1">'.e($note).'</div>' : '')
                                                );
                                            })
                                            ->visible(fn (Get $get, $livewire): bool => static::kapsamKalemVisibleInCurrentWeek($get, $livewire)
                                                && static::kapsamShowsAciktaKapatmaOzet($get, $livewire))
                                            ->columnSpanFull(),
                                        Grid::make(3)->schema([
                                            Forms\Components\TextInput::make('bu_hafta_tamamlanan_ui')
                                                ->label('Bu Hafta Tamamlanan')
                                                ->suffix(fn (Get $get): ?string => static::resolveOlcuBirimiForKapsamRow($get))
                                                ->numeric()
                                                ->minValue(0)
                                                ->rules(['integer', 'min:0'])
                                                ->extraInputAttributes(['min' => 0, 'step' => 1, 'inputmode' => 'numeric', 'pattern' => '[0-9]*'])
                                                ->required(fn (Get $get, $livewire): bool => static::kapsamShowsWeeklyFollowUpFields($get, $livewire)
                                                    && filled($get('bu_hafta_aciklama')))
                                                ->visible(fn (Get $get, $livewire): bool => static::kapsamKalemVisibleInCurrentWeek($get, $livewire)
                                                    && static::kapsamShowsWeeklyFollowUpFields($get, $livewire))
                                                ->afterStateUpdated(fn (Set $set, $state): mixed => static::syncHiddenFieldFromUi($set, $state, 'bu_hafta_tamamlanan'))
                                                ->afterStateHydrated(fn (Set $set, Get $get, $state): mixed => static::hydrateUiFromHiddenField($set, $get, $state, 'bu_hafta_tamamlanan', 'bu_hafta_tamamlanan_ui'))
                                                ->dehydrated(false),
                                            Forms\Components\DatePicker::make('bu_hafta_yapilma_tarihi_ui')
                                                ->label('Tamamlanma Tarihi')
                                                ->native(false)
                                                ->displayFormat('d.m.Y')
                                                ->default(fn (): string => ReportPeriodWeeks::systemRecordDate()->toDateString())
                                                ->visible(fn (Get $get, $livewire): bool => static::kapsamKalemVisibleInCurrentWeek($get, $livewire)
                                                    && static::kapsamShowsWeeklyFollowUpFields($get, $livewire))
                                                ->afterStateUpdated(function (Set $set, mixed $state): void {
                                                    if (! filled($state)) {
                                                        $set('bu_hafta_yapilma_tarihi', ReportPeriodWeeks::systemRecordDate()->toDateString());

                                                        return;
                                                    }
                                                    try {
                                                        $set('bu_hafta_yapilma_tarihi', Carbon::parse($state)->startOfDay()->toDateString());
                                                    } catch (\Throwable) {
                                                        $set('bu_hafta_yapilma_tarihi', ReportPeriodWeeks::systemRecordDate()->toDateString());
                                                    }
                                                })
                                                ->afterStateHydrated(function (Set $set, Get $get, mixed $state): void {
                                                    static::hydrateUiFromHiddenField($set, $get, $state, 'bu_hafta_yapilma_tarihi', 'bu_hafta_yapilma_tarihi_ui');
                                                    if (! filled($get('bu_hafta_yapilma_tarihi'))) {
                                                        $today = ReportPeriodWeeks::systemRecordDate()->toDateString();
                                                        $set('bu_hafta_yapilma_tarihi', $today);
                                                        if (! filled($state)) {
                                                            $set('bu_hafta_yapilma_tarihi_ui', $today);
                                                        }
                                                    }
                                                })
                                                ->dehydrated(false)
                                                ->helperText('Açıkta kalan işin tamamlandığı tarih.'),
                                            Forms\Components\Textarea::make('bu_hafta_aciklama_ui')
                                                ->label('Açıklama')
                                                ->rows(2)
                                                ->required(fn (Get $get, $livewire): bool => static::kapsamShowsWeeklyFollowUpFields($get, $livewire)
                                                    && filled($get('bu_hafta_tamamlanan'))
                                                    && (float) ($get('bu_hafta_tamamlanan') ?? 0) > 0)
                                                ->visible(fn (Get $get, $livewire): bool => static::kapsamKalemVisibleInCurrentWeek($get, $livewire)
                                                    && static::kapsamShowsWeeklyFollowUpFields($get, $livewire))
                                                ->afterStateUpdated(fn (Set $set, $state): mixed => static::syncHiddenFieldFromUi($set, $state, 'bu_hafta_aciklama'))
                                                ->afterStateHydrated(fn (Set $set, Get $get, $state): mixed => static::hydrateUiFromHiddenField($set, $get, $state, 'bu_hafta_aciklama', 'bu_hafta_aciklama_ui'))
                                                ->dehydrated(false),
                                        ])->columnSpanFull(),
                                        Forms\Components\Placeholder::make('son_yapilma_tarihi_goster')
                                            ->label('Son Kayıt Tarihi')
                                            ->content(fn (Get $get): string => AylikFaaliyetWeeklyCarryover::formatDisplayDate($get('son_yapilma_tarihi')) ?? '—')
                                            ->visible(fn (Get $get, $livewire): bool => static::kapsamKalemVisibleInCurrentWeek($get, $livewire)
                                                && filled($get('son_yapilma_tarihi')))
                                            ->columnSpanFull(),
                                        Forms\Components\Placeholder::make('haftalik_kayit_ozeti')
                                            ->label('Haftalık İlerleme Kayıtları')
                                            ->content(function (Get $get, $livewire): HtmlString {
                                                $kayitlar = $get('haftalik_kayitlar');
                                                if (! is_array($kayitlar) || $kayitlar === []) {
                                                    return new HtmlString('—');
                                                }
                                                [$yil, $ay] = static::resolveReportPeriodFromLivewire($livewire);
                                                $lines = [];
                                                foreach ($kayitlar as $kayit) {
                                                    if (! is_array($kayit)) {
                                                        continue;
                                                    }
                                                    $hafta = (int) ($kayit['hafta'] ?? 0);
                                                    $weekLabel = ($yil > 0 && $ay >= 1 && $ay <= 12 && $hafta >= 1)
                                                        ? (ReportPeriodWeeks::weekLabelForRecord($yil, $ay, $hafta) ?? ('Hafta '.$hafta))
                                                        : ('Hafta '.$hafta);
                                                    $miktar = $kayit['miktar'] ?? '—';
                                                    $tarih = AylikFaaliyetWeeklyCarryover::formatDisplayDate($kayit['yapilma_tarihi'] ?? null) ?? '—';
                                                    $aciklama = e(trim((string) ($kayit['aciklama'] ?? '')));
                                                    $lines[] = '<div style="margin-bottom:4px;"><b>'.e($weekLabel).'</b> · Kayıt: '.$tarih.' · '.$miktar.'<br><span style="color:#4b5563;">'.$aciklama.'</span></div>';
                                                }

                                                return new HtmlString(implode('', $lines));
                                            })
                                            ->visible(fn (Get $get, $livewire): bool => static::kapsamKalemVisibleInCurrentWeek($get, $livewire)
                                                && is_array($get('haftalik_kayitlar')) && $get('haftalik_kayitlar') !== [])
                                            ->columnSpanFull(),
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
                                        ->rules(['nullable', 'integer', 'min:0'])
                                        ->extraInputAttributes(['min' => 0, 'step' => 1, 'inputmode' => 'numeric', 'pattern' => '[0-9]*'])
                                        ->dehydrateStateUsing(fn ($state) => NonNegativeInput::normalizeIntegerScalar($state))
                                        ->placeholder('Örn: 395')
                                        ->helperText('Boş bırakılabilir; tamamlanan yoksa 0 yazmayın.')
                                        ->live(onBlur: true)
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
                                        ->rules(['nullable', 'numeric', 'min:0'])
                                        ->extraInputAttributes(['min' => 0])
                                        ->dehydrateStateUsing(fn ($state) => NonNegativeInput::normalizeScalar($state))
                                        ->live(onBlur: true)
                                        ->placeholder('Örn: 18')
                                        ->helperText('Boş bırakılabilir.')
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
                                    ->schema([
                                        Grid::make(2)->schema([
                                            Forms\Components\Toggle::make('gerekli_revize')
                                                ->label('Gerekli Revize')
                                                ->inline(false)
                                                ->default(false)
                                                ->dehydrated(true)
                                                ->disabled(fn (Get $get, $livewire): bool => static::faaliyetRowRevizeAlaniDisabled($get, $livewire))
                                                ->helperText('Revize işaretini kayıt sırasında belirleyin. Sebep alanını aşağıya yazınız.'),
                                            Forms\Components\Textarea::make('revize_sebebi')
                                                ->label('Revize Sebebi')
                                                ->rows(2)
                                                ->placeholder('Revize neden gerekli? Kisa aciklama yaziniz...')
                                                ->disabled(fn (Get $get, $livewire): bool => static::faaliyetRowRevizeAlaniDisabled($get, $livewire))
                                                ->required(fn (Get $get): bool => static::isGerekliRevizeEnabled($get))
                                                ->visible(fn (Get $get, $livewire): bool => ! static::faaliyetRowRevizeAlaniDisabled($get, $livewire)),
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
                            ->itemLabel(function (array $state, $livewire): ?string {
                                $label = $state['faaliyet_kodu'] ?? 'Yeni Faaliyet Girisi';
                                if ((bool) ($state['gerekli_revize'] ?? false)) {
                                    $label = '[REVIZE] '.$label;
                                }

                                $yil = 0;
                                $ay = '';
                                if (is_object($livewire)) {
                                    if (method_exists($livewire, 'getRecord')) {
                                        $record = $livewire->getRecord();
                                        if ($record instanceof AylikFaaliyet) {
                                            $yil = (int) ($record->yil ?? 0);
                                            $ay = (string) ($record->ay ?? '');
                                        }
                                    }
                                    if ($yil <= 0 && property_exists($livewire, 'data') && is_array($livewire->data)) {
                                        $yil = (int) ($livewire->data['yil'] ?? 0);
                                        $ay = (string) ($livewire->data['ay'] ?? '');
                                    }
                                }

                                $weekLabel = null;
                                if ($yil > 0 && $ay !== '') {
                                    $month = (int) preg_replace('/\D/', '', $ay);
                                    if ($month >= 1 && $month <= 12 && ($state['hafta'] ?? null) !== null && $state['hafta'] !== '') {
                                        $weekLabel = ReportPeriodWeeks::weekLabelForRecord($yil, $ay, $state['hafta']);
                                    }
                                }
                                if ($weekLabel) {
                                    $label .= ' | '.$weekLabel;
                                }

                                return $label;
                            })
                            ->collapsible()
                            ->persistCollapsed()
                            ->reorderable(false)
                            ->deletable(true)
                            ->deleteAction(function (FormAction $action) {
                                return $action
                                    ->label('Satırı sil')
                                    ->visible(function (array $arguments, Repeater $component): bool {
                                        $user = auth()->user();
                                        if ($user instanceof User && $user->isReportingSuperAdmin()) {
                                            return true;
                                        }

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

                                        return ! (bool) ($row['ay_sonu_performans_kilitli'] ?? false);
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
            ->columns([
                Tables\Columns\TextColumn::make('donem_tarih_araligi')
                    ->label('Dönem')
                    ->getStateUsing(function (AylikFaaliyet $record): string {
                        return ReportPeriodWeeks::recordPeriodLabel(
                            (int) ($record->yil ?? 0),
                            $record->ay ?? null
                        ) ?? trim((string) (($record->yil ?? '—').' / '.($record->ay ?? '—')));
                    })
                    ->wrap()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query
                            ->where('yil', 'like', "%{$search}%")
                            ->orWhere('ay', 'like', "%{$search}%");
                    }),
                Tables\Columns\TextColumn::make('yil')
                    ->label('Yıl')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('ay')
                    ->label('Ay')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('user.name')->label('Müdürlük')->searchable(),
                Tables\Columns\TextColumn::make('son_kayit_tarihi')
                    ->label('Kayıt Tarihi')
                    ->getStateUsing(fn (AylikFaaliyet $record): string => static::reportRecordSavedAtLabel($record) ?? '—')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('updated_at', $direction);
                    }),
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
                    ->label('Müdürlük')
                    ->titlePrefixedWithLabel(false)
                    ->getTitleFromRecordUsing(fn (AylikFaaliyet $record): string => static::mudurlukGroupTitle($record))
                    ->collapsible(),
                TableGroup::make('ay')
                    ->label('Müdürlük / Dönem')
                    ->titlePrefixedWithLabel(false)
                    ->getKeyFromRecordUsing(fn (AylikFaaliyet $record): string => static::mudurlukAyGroupKey($record))
                    ->getTitleFromRecordUsing(fn (AylikFaaliyet $record): string => static::mudurlukAyGroupTitle($record))
                    ->collapsible(),
            ])
            ->defaultGroup('user.name')
            ->groupingSettingsHidden(false)
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
            ])
            // Raporlar tek ekranda; sayfa başına kısıtı yok.
            ->paginated(false);
    }

    private static function mudurlukGroupTitle(AylikFaaliyet $record): string
    {
        $userId = (int) ($record->user_id ?? 0);
        $name = trim((string) ($record->user?->name ?? 'Müdürlük'));
        if ($userId <= 0) {
            return $name;
        }

        if (! array_key_exists($userId, static::$mudurlukGroupReportCountCache)) {
            $total = (int) AylikFaaliyet::query()
                ->where('user_id', $userId)
                ->count();
            static::$mudurlukGroupReportCountCache[$userId] = max(0, $total);
        }

        return $name.' ('.static::$mudurlukGroupReportCountCache[$userId].')';
    }

    private static function mudurlukAyGroupKey(AylikFaaliyet $record): string
    {
        $userId = (int) ($record->user_id ?? 0);
        $yil = (int) ($record->yil ?? 0);
        $ay = str_pad(trim((string) ($record->ay ?? '')), 2, '0', STR_PAD_LEFT);

        return $userId.'|'.$yil.'|'.$ay;
    }

    private static function mudurlukAyGroupTitle(AylikFaaliyet $record): string
    {
        $userId = (int) ($record->user_id ?? 0);
        $name = trim((string) ($record->user?->name ?? 'Müdürlük'));
        $yil = (int) ($record->yil ?? 0);
        $ay = str_pad(trim((string) ($record->ay ?? '')), 2, '0', STR_PAD_LEFT);

        if ($userId <= 0) {
            return $name.' / '.$yil.'-'.$ay;
        }

        $key = static::mudurlukAyGroupKey($record);
        if (! array_key_exists($key, static::$mudurlukAyGroupReportCountCache)) {
            $total = (int) AylikFaaliyet::query()
                ->where('user_id', $userId)
                ->where('yil', $yil)
                ->where('ay', $ay)
                ->count();
            static::$mudurlukAyGroupReportCountCache[$key] = max(0, $total);
        }

        return $name.' / '.(ReportPeriodWeeks::recordPeriodLabel($yil, $ay) ?? ($yil.'-'.$ay)).' ('.static::$mudurlukAyGroupReportCountCache[$key].')';
    }

    private static function scopeMudurlukAyGroupQueryByKey(Builder $query, string $key): Builder
    {
        $parts = explode('|', $key);
        $userId = isset($parts[0]) ? (int) $parts[0] : 0;
        $yil = isset($parts[1]) ? (int) $parts[1] : 0;
        $ay = isset($parts[2]) ? str_pad(trim((string) $parts[2]), 2, '0', STR_PAD_LEFT) : '';

        if ($userId <= 0 || $yil <= 0 || $ay === '') {
            return $query->whereRaw('0 = 1');
        }

        return $query
            ->where($query->qualifyColumn('user_id'), $userId)
            ->where($query->qualifyColumn('yil'), $yil)
            ->where($query->qualifyColumn('ay'), $ay);
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
                ->orderBy('users.name')
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
        $kapsamPending = "EXISTS (
            SELECT 1 FROM JSON_TABLE(
                {$faaliyetlerColumn},
                '\$[*].kapsam_verileri[*]' COLUMNS (
                    ong VARCHAR(64) PATH '\$.ongorulen',
                    deger VARCHAR(64) PATH '\$.deger',
                    ger VARCHAR(64) PATH '\$.gerceklesen',
                    kap VARCHAR(16) PATH '\$.acikta_kapatildi'
                )
            ) k
            WHERE COALESCE(k.kap, 'false') NOT IN ('true', '1', 'TRUE')
              AND GREATEST(
                    COALESCE(CAST(NULLIF(COALESCE(k.ong, k.deger), '') AS DECIMAL(18,2)), 0)
                    - COALESCE(CAST(NULLIF(k.ger, '') AS DECIMAL(18,2)), 0)
                  , 0) > 0
        )";
        $existsCompleted = "EXISTS (SELECT 1 FROM {$jsonTable} WHERE {$doneExpr} > 0 AND {$pendingExpr} <= 0)";
        $existsPending = "(EXISTS (SELECT 1 FROM {$jsonTable} WHERE {$pendingExpr} > 0) OR ({$kapsamPending}))";

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
                        TextEntry::make('donem_tarih_araligi')
                            ->label('Dönem Tarih Aralığı')
                            ->getStateUsing(function (?AylikFaaliyet $record): string {
                                if (! $record instanceof AylikFaaliyet) {
                                    return '—';
                                }

                                $yil = (int) ($record->yil ?? 0);
                                $ay = (int) preg_replace('/\D/', '', (string) ($record->ay ?? ''));

                                if ($yil <= 0 || $ay < 1 || $ay > 12) {
                                    return '—';
                                }

                                return ReportPeriodWeeks::monthPeriodLabel($yil, $ay);
                            }),
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
            ]);
    }

    /**
     * Aynı müdürlük + yıl + ay için haftalık rapor kayıtları (1.–5. / aylık, sıralı).
     *
     * @return Collection<int, AylikFaaliyet>
     */
    public static function monthPeriodReports(AylikFaaliyet $record): Collection
    {
        return static::periodSiblingReports($record);
    }

    /**
     * Aynı müdürlük + yıl + ay için tüm haftalık rapor satırlarını
     * (1.–5. hafta / aylık) sıralı birleştirir.
     *
     * @return list<array<string, mixed>>
     */
    private static function collectSortedPeriodFaaliyetRows(?AylikFaaliyet $record): array
    {
        if (! $record instanceof AylikFaaliyet) {
            return [];
        }

        $merged = [];
        foreach (static::periodSiblingReports($record) as $sibling) {
            $reportHafta = ReportPeriodWeeks::normalizeReportHafta($sibling->hafta ?? null);
            $rows = is_array($sibling->faaliyetler) ? $sibling->faaliyetler : [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (($row['hafta'] ?? null) === null || $row['hafta'] === '') {
                    if ($reportHafta !== null) {
                        $row['hafta'] = ReportPeriodWeeks::isMonthlyPeriod($reportHafta)
                            ? ReportPeriodWeeks::MONTHLY_VALUE
                            : (int) $reportHafta;
                    }
                }
                $merged[] = $row;
            }
        }

        usort($merged, function (array $a, array $b): int {
            $wa = static::haftaSortKey($a['hafta'] ?? null);
            $wb = static::haftaSortKey($b['hafta'] ?? null);
            if ($wa !== $wb) {
                return $wa <=> $wb;
            }

            return strcmp(
                trim((string) ($a['faaliyet_kodu'] ?? '')),
                trim((string) ($b['faaliyet_kodu'] ?? ''))
            );
        });

        return $merged;
    }

    /**
     * @return Collection<int, AylikFaaliyet>
     */
    private static function periodSiblingReports(AylikFaaliyet $record): Collection
    {
        $userId = (int) ($record->user_id ?? 0);
        $yil = (int) ($record->yil ?? 0);
        $ay = AylikFaaliyetPeriodMerge::normalizeAy((string) ($record->ay ?? ''));
        if ($userId <= 0 || $yil <= 0 || $ay === '') {
            return collect([$record]);
        }

        $variants = AylikFaaliyetPeriodMerge::ayQueryVariants($ay);
        $siblings = AylikFaaliyet::query()
            ->where('user_id', $userId)
            ->where('yil', $yil)
            ->whereIn('ay', $variants)
            ->orderBy('id')
            ->get()
            ->sortBy(function (AylikFaaliyet $sibling): int {
                return (static::haftaSortKey($sibling->hafta ?? null) * 1_000_000)
                    + (int) ($sibling->id ?? 0);
            })
            ->values();

        return $siblings->isEmpty() ? collect([$record]) : $siblings;
    }

    private static function haftaSortKey(mixed $hafta): int
    {
        $raw = mb_strtolower(trim((string) ($hafta ?? '')), 'UTF-8');
        if (in_array($raw, ['aylik', 'aylık', 'monthly', '0'], true) || $hafta === 0) {
            return 99;
        }

        $normalized = ReportPeriodWeeks::normalizeReportHafta($hafta);
        if ($normalized === ReportPeriodWeeks::MONTHLY_VALUE) {
            return 99;
        }
        if ($normalized !== null && is_numeric($normalized)) {
            return (int) $normalized;
        }

        if (is_numeric($hafta)) {
            $week = (int) $hafta;
            if ($week >= 1 && $week <= ReportPeriodWeeks::WEEK_COUNT) {
                return $week;
            }
        }

        return 50;
    }

    /**
     * Rapor satırlarını, müdürlüğe ait katalogdaki eksik faaliyetlerle tamamlar.
     * $includePeriodSiblings true ise aynı ayın tüm hafta raporları birleştirilip
     * hafta sırasına göre listelenir (PDF).
     *
     * @return list<array<string, mixed>>
     */
    private static function rowsForReportPresentation(?AylikFaaliyet $record, bool $includePeriodSiblings = false): array
    {
        if ($includePeriodSiblings) {
            $rows = static::collectSortedPeriodFaaliyetRows($record);
        } else {
            $rows = is_array($record?->faaliyetler)
                ? array_values(array_filter($record->faaliyetler, fn ($row): bool => is_array($row)))
                : [];
        }

        if (! $record instanceof AylikFaaliyet) {
            return $rows;
        }

        $record->loadMissing('user');
        $mudurluk = trim((string) ($record->user?->name ?? ''));
        if ($mudurluk === '' && (int) ($record->user_id ?? 0) > 0) {
            $mudurluk = trim((string) (User::query()->whereKey((int) $record->user_id)->value('name') ?? ''));
        }

        $existingByCatalogId = [];
        $existingByCode = [];
        $codePrefixes = [];
        foreach ($rows as $row) {
            $catalogId = (int) ($row['activity_catalog_id'] ?? 0);
            if ($catalogId > 0) {
                $existingByCatalogId[$catalogId] = true;
            }
            $code = trim((string) ($row['faaliyet_kodu'] ?? ''));
            if ($code !== '') {
                $existingByCode[$code] = true;
                $parts = explode('-', $code);
                $prefix = trim((string) ($parts[0] ?? ''));
                if ($prefix !== '') {
                    $codePrefixes[$prefix] = true;
                }
            }
        }

        $catalogRows = collect();
        if ($mudurluk !== '') {
            $catalogOptions = ActivityCatalogFormatter::selectOptionsForMudurluk($mudurluk);
            if ($catalogOptions !== []) {
                $catalogIds = array_values(array_map('intval', array_keys($catalogOptions)));
                $catalogRows = ActivityCatalog::query()
                    ->whereIn('id', $catalogIds)
                    ->orderBy('faaliyet_kodu')
                    ->get(['id', 'faaliyet_kodu', 'faaliyet_ailesi', 'kapsam', 'olcu_birimi', 'baskanlik_bilgilendirme_seviyesi']);
            }
        }

        // Müdürlük adı "Md." gibi kısaltmalıysa eşleşme kaçabiliyor; mevcut kod öneklerinden tüm kataloğu tamamla.
        if ($codePrefixes !== []) {
            $prefixRows = collect();
            foreach (array_keys($codePrefixes) as $prefix) {
                $prefixRows = $prefixRows->merge(
                    ActivityCatalog::query()
                        ->where('faaliyet_kodu', 'like', $prefix.'-%')
                        ->orderBy('faaliyet_kodu')
                        ->get(['id', 'faaliyet_kodu', 'faaliyet_ailesi', 'kapsam', 'olcu_birimi', 'baskanlik_bilgilendirme_seviyesi'])
                );
            }
            $catalogRows = $catalogRows->merge($prefixRows);
        }

        if ($mudurluk !== '') {
            $normalizedMudurluk = TurkishString::normalizeForFuzzyMatch($mudurluk);
            if ($normalizedMudurluk !== '') {
                $mudurlukRows = ActivityCatalog::query()
                    ->orderBy('faaliyet_kodu')
                    ->get(['id', 'mudurluk', 'faaliyet_kodu', 'faaliyet_ailesi', 'kapsam', 'olcu_birimi', 'baskanlik_bilgilendirme_seviyesi'])
                    ->filter(function (ActivityCatalog $catalog) use ($normalizedMudurluk): bool {
                        $catalogMudurluk = TurkishString::normalizeForFuzzyMatch((string) $catalog->mudurluk);
                        if ($catalogMudurluk === '') {
                            return false;
                        }

                        return str_contains($catalogMudurluk, $normalizedMudurluk)
                            || str_contains($normalizedMudurluk, $catalogMudurluk);
                    })
                    ->values();
                $catalogRows = $catalogRows->merge($mudurlukRows);
            }
        }

        $catalogRows = $catalogRows
            ->unique(fn (ActivityCatalog $row) => (int) $row->id)
            ->sortBy('faaliyet_kodu')
            ->values();

        foreach ($catalogRows as $catalog) {
            $catalogId = (int) $catalog->id;
            $code = trim((string) $catalog->faaliyet_kodu);
            if (isset($existingByCatalogId[$catalogId]) || ($code !== '' && isset($existingByCode[$code]))) {
                continue;
            }

            $rows[] = [
                'activity_catalog_id' => $catalogId,
                'faaliyet_kodu' => $code,
                'faaliyet_turu' => 'Operasyonel',
                'kapsam_icerigi' => (string) $catalog->faaliyet_ailesi,
                'olcu_birimi' => (string) $catalog->olcu_birimi,
                'baskanlik_bilgilendirme_seviyesi' => ActivityCatalogMetadataByCode::mergeWithCatalog(
                    $code,
                    '',
                    (string) $catalog->baskanlik_bilgilendirme_seviyesi
                )['baskanlik_bilgilendirme_seviyesi'],
                'kapsam_verileri' => static::syncKapsamVerileri(
                    static::parseKapsamKalemleri((string) ($catalog->kapsam ?? '')),
                    []
                ),
                'gerceklesen' => null,
                'bekleyen_is' => null,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            $wa = static::haftaSortKey($a['hafta'] ?? null);
            $wb = static::haftaSortKey($b['hafta'] ?? null);
            if ($wa !== $wb) {
                return $wa <=> $wb;
            }

            return strcmp(
                trim((string) ($a['faaliyet_kodu'] ?? '')),
                trim((string) ($b['faaliyet_kodu'] ?? ''))
            );
        });

        return $rows;
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
        $user = auth()->user();
        if ($user instanceof User && $user->isMaliHizmetlerAccount() && ! $user->isReportingSuperAdmin()) {
            return false;
        }

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

        // Yalnızca müdürlük raporlayan hesap yeni rapor oluşturabilir (Mali Hizmetler hariç).
        return $u->isMudurlukReportingAccount() && ! $u->isMaliHizmetlerAccount();
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

    /**
     * Sunucuda güvenli senkron: yalnızca bilgilendirme seviyesi güncellenir.
     * Faaliyet kodları, katalog id, kapsam kalemleri ve mevcut satırlar değişmez/silinmez.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function refreshFaaliyetMetadataFromCatalog(array $data, ?string $mudurlukAdi): array
    {
        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        $codes = [];
        foreach ($data['faaliyetler'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = trim((string) ($row['faaliyet_kodu'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        $catalogByCode = [];
        if ($codes !== []) {
            $catalogRows = ActivityCatalog::query()
                ->whereIn('faaliyet_kodu', array_values(array_unique($codes)))
                ->get([
                    'id',
                    'faaliyet_kodu',
                    'faaliyet_ailesi',
                    'kapsam',
                    'olcu_birimi',
                    'baskanlik_bilgilendirme_seviyesi',
                ]);
            foreach ($catalogRows as $catalog) {
                $code = trim((string) $catalog->faaliyet_kodu);
                if ($code !== '') {
                    $catalogByCode[$code] = $catalog;
                }
            }
        }

        foreach ($data['faaliyetler'] as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = trim((string) ($row['faaliyet_kodu'] ?? ''));
            if ($code === '') {
                continue;
            }

            $catalog = $catalogByCode[$code] ?? null;
            $data['faaliyetler'][$i] = static::applyCatalogMetadataToFaaliyetRow(
                $row,
                $catalog instanceof ActivityCatalog ? $catalog : null,
                preserveExistingIdentity: true,
                metadataOnly: true
            );
        }

        return $data;
    }

    /**
     * Edit ekranı açılırken faaliyet satırlarını güncel katalog kapsam kalemleriyle hizalar.
     * Eski kayıtlardaki mevcut sayısal değerleri korur, eksik yeni kalemleri otomatik ekler.
     * Mevcut faaliyet kodları ve kayıtlı satırlar silinmez.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function syncFaaliyetlerWithCurrentCatalog(array $data, ?string $mudurlukAdi): array
    {
        \App\Support\CatalogKalemRevisions::ensureApplied();

        $data = ActivityCatalogFormatter::hydrateActivityCatalogIdsInFaaliyetler($data, $mudurlukAdi);

        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        $catalogIds = [];
        $codes = [];
        foreach ($data['faaliyetler'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $catalogId = (int) ($row['activity_catalog_id'] ?? 0);
            if ($catalogId > 0) {
                $catalogIds[] = $catalogId;
            }
            $code = trim((string) ($row['faaliyet_kodu'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        $allowedIds = [];
        $trimMudurluk = trim((string) ($mudurlukAdi ?? ''));
        if ($trimMudurluk !== '') {
            $allowedIds = array_map('intval', array_keys(ActivityCatalogFormatter::selectOptionsForMudurluk($trimMudurluk)));
        }

        $catalogQuery = ActivityCatalog::query();
        if ($allowedIds !== []) {
            $catalogQuery->whereIn('id', array_values(array_unique($allowedIds)));
        } elseif ($catalogIds !== [] || $codes !== []) {
            $catalogQuery->where(function (Builder $q) use ($catalogIds, $codes): void {
                if ($catalogIds !== []) {
                    $q->orWhereIn('id', array_values(array_unique($catalogIds)));
                }
                if ($codes !== []) {
                    $q->orWhereIn('faaliyet_kodu', array_values(array_unique($codes)));
                }
            });
        } else {
            return $data;
        }

        $catalogRows = $catalogQuery->get([
            'id',
            'faaliyet_kodu',
            'faaliyet_ailesi',
            'kapsam',
            'olcu_birimi',
            'baskanlik_bilgilendirme_seviyesi',
        ]);
        $catalogById = [];
        $catalogByCode = [];
        foreach ($catalogRows as $catalog) {
            $catalogById[(int) $catalog->id] = $catalog;
            $code = trim((string) $catalog->faaliyet_kodu);
            if ($code !== '') {
                $catalogByCode[$code] = $catalog;
            }
        }

        foreach ($data['faaliyetler'] as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $catalog = null;
            $rowCode = trim((string) ($row['faaliyet_kodu'] ?? ''));
            if ($rowCode !== '' && isset($catalogByCode[$rowCode])) {
                $catalog = $catalogByCode[$rowCode];
            } else {
                $catalogId = (int) ($row['activity_catalog_id'] ?? 0);
                if ($catalogId > 0 && isset($catalogById[$catalogId])) {
                    $catalog = $catalogById[$catalogId];
                }
            }

            if (! $catalog instanceof ActivityCatalog) {
                if ($rowCode !== '') {
                    $data['faaliyetler'][$i] = static::applyCatalogMetadataToFaaliyetRow(
                        $row,
                        null,
                        preserveExistingIdentity: true,
                        metadataOnly: false
                    );
                }

                continue;
            }

            $existingKapsamRows = $row['kapsam_verileri'] ?? [];
            $data['faaliyetler'][$i] = static::applyCatalogMetadataToFaaliyetRow(
                $row,
                $catalog,
                preserveExistingIdentity: true,
                metadataOnly: false
            );
            $data['faaliyetler'][$i]['kapsam_verileri'] = static::syncKapsamVerileri(
                static::parseKapsamKalemleri((string) ($catalog->kapsam ?? '')),
                is_array($existingKapsamRows) ? $existingKapsamRows : []
            );
        }

        $data['faaliyetler'] = array_values(array_filter(
            $data['faaliyetler'],
            fn ($row): bool => is_array($row)
        ));

        return $data;
    }

    /**
     * Katalogdan gelen salt okunur faaliyet meta alanlarını (faaliyet kodu olan satırlar) günceller.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function applyCatalogMetadataToFaaliyetRow(
        array $row,
        ?ActivityCatalog $catalog,
        bool $preserveExistingIdentity = false,
        bool $metadataOnly = false
    ): array {
        $rowCode = trim((string) ($row['faaliyet_kodu'] ?? ''));
        $catalogCode = $catalog instanceof ActivityCatalog
            ? trim((string) ($catalog->faaliyet_kodu ?? ''))
            : '';

        if ($rowCode === '' && $catalogCode === '') {
            return $row;
        }

        if ($catalog instanceof ActivityCatalog) {
            if (! $preserveExistingIdentity) {
                $row['activity_catalog_id'] = (int) $catalog->id;
                if ($catalogCode !== '') {
                    $row['faaliyet_kodu'] = $catalogCode;
                }
            } elseif ((int) ($row['activity_catalog_id'] ?? 0) <= 0) {
                $row['activity_catalog_id'] = (int) $catalog->id;
            }

            if (! $metadataOnly) {
                $row['kapsam_icerigi'] = trim((string) ($catalog->faaliyet_ailesi ?? ''));
                $row['olcu_birimi'] = trim((string) ($catalog->olcu_birimi ?? ''));
            }
        }

        $lookupCode = $rowCode !== '' ? $rowCode : $catalogCode;
        if ($catalog instanceof ActivityCatalog) {
            $metadata = ActivityCatalogMetadataByCode::mergeWithCatalog(
                $lookupCode,
                '',
                (string) ($catalog->baskanlik_bilgilendirme_seviyesi ?? '')
            );
        } else {
            $metadata = ActivityCatalogMetadataByCode::resolveForCode($lookupCode);
        }

        $row['baskanlik_bilgilendirme_seviyesi'] = $metadata['baskanlik_bilgilendirme_seviyesi'];
        unset($row['raporlama_sikligi']);

        return $row;
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

        $items = [];

        foreach ($state as $row) {
            if (! is_array($row)) {
                continue;
            }

            $kalem = trim((string) ($row['kalem'] ?? ''));
            if ($kalem === '') {
                continue;
            }

            $ger = $row['gerceklesen'] ?? null;
            $acik = AylikFaaliyetWeeklyCarryover::kapsamPendingAmount($row);
            $gerText = filled($ger) ? (string) $ger : '—';
            $acikText = $acik > 0.0 ? (string) (floor($acik) === $acik ? (int) $acik : $acik) : '0';
            $revizeTarih = AylikFaaliyetWeeklyCarryover::formatDisplayDate($row['acikta_revize_tarihi'] ?? null);
            $revizeNotu = trim((string) ($row['acikta_revize_notu'] ?? ''));
            $revizeHtml = '';
            if ($revizeNotu !== '' || $revizeTarih) {
                $revizeHtml = '<div style="font-size:11px;color:#92400e;margin-top:4px;">'
                    .'<b>Revize:</b> '
                    .e($revizeTarih ?? '—')
                    .($revizeNotu !== '' ? ' — '.e($revizeNotu) : '')
                    .'</div>';
            }

            $items[] =
                '<div style="border:1px solid #e5e7eb;border-radius:10px;padding:8px 10px;margin-bottom:6px;background:#ffffff;">'
                .'<div style="font-weight:600;color:#111827;margin-bottom:4px;">'.e($kalem).'</div>'
                .'<div style="font-size:12px;line-height:1.5;color:#374151;">'
                .'Gerçekleşen: <b>'.e($gerText).'</b> &nbsp;|&nbsp; '
                .'Açıkta Kalan: <b>'.e($acikText).'</b>'
                .'</div>'
                .$revizeHtml
                .'</div>';
        }

        if ($items === []) {
            return '—';
        }

        return '<div style="display:block;">'.implode('', $items).'</div>';
    }

    private static function visualPerformanceSummaryHtml(?AylikFaaliyet $record): string
    {
        // İş listesine eklenmemiş / miktar girilmemiş satırlar (katalog doldurması) raporda yer almaz.
        // Girilmiş 0 değerleri görünür.
        $summary = static::summarizeReportForPresentation($record, false, true);
        $anyDone = collect($summary['items'])->contains(fn (array $i): bool => ! (bool) ($i['missing_done'] ?? true));
        $anyPlan = collect($summary['items'])->contains(fn (array $i): bool => ! (bool) ($i['missing_plan'] ?? true));
        $anyPending = collect($summary['items'])->contains(fn (array $i): bool => ! (bool) ($i['missing_pending'] ?? true)
            || ! (bool) ($i['missing_plan'] ?? true)
            || ! (bool) ($i['missing_done'] ?? true));
        $totalDone = $anyDone ? number_format((float) $summary['total_done'], 0, ',', '.') : '';
        $totalPending = $anyPending ? number_format((float) $summary['total_pending'], 0, ',', '.') : '';
        $totalPlan = $anyPlan ? number_format((float) $summary['total_plan'], 0, ',', '.') : '';
        $completion = (int) $summary['completion'];
        $completedRows = (int) $summary['completed_rows'];
        $pendingRows = (int) $summary['pending_rows'];
        $totalsMissing = (bool) $summary['totals_missing'];
        $totalDoneColor = $totalsMissing ? '#b91c1c' : '#065f46';
        $totalPendingColor = $totalsMissing ? '#b91c1c' : '#1e3a8a';
        $totalPlanColor = $totalsMissing ? '#b91c1c' : '#9a3412';
        $completionLabel = $anyPlan ? '%'.$completion : '';

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
            $doneProvided = ! (bool) ($item['missing_done'] ?? true);
            $planProvided = ! (bool) ($item['missing_plan'] ?? true);
            $pendingProvided = ! (bool) ($item['missing_pending'] ?? true);
            $done = static::formatPdfQuantityWhenProvided($item['done'] ?? 0, $doneProvided);
            $plan = static::formatPdfQuantityWhenProvided($item['plan'] ?? 0, $planProvided);
            $pending = static::formatPdfQuantityWhenProvided(
                $item['pending'] ?? 0,
                $pendingProvided || $planProvided || $doneProvided
            );
            $doneColor = $doneProvided ? '#111827' : '#b91c1c';
            $pendingColor = ($pendingProvided || $planProvided || $doneProvided) ? '#111827' : '#b91c1c';
            $planColor = $planProvided ? '#111827' : '#b91c1c';
            $unit = trim((string) ($item['unit'] ?? ''));
            $unitSuffix = $unit !== '' ? ' '.$unit : '';
            $infoLevel = trim((string) ($item['info_level'] ?? ''));
            $sapmaNedeni = trim((string) ($item['sapma_nedeni'] ?? ''));
            $revizeSebebi = trim((string) ($item['revize_sebebi'] ?? ''));
            $kararIhtiyaci = trim((string) ($item['karar_ihtiyaci'] ?? ''));
            $gerekliRevize = (bool) ($item['gerekli_revize'] ?? false);
            $kapsamRows = is_array($item['kapsam_rows'] ?? null) ? $item['kapsam_rows'] : [];
            $infoHtml = $infoLevel !== ''
                ? '<span style="font-size:11px;color:#b91c1c;background:#fee2e2;padding:2px 8px;border-radius:9999px;">Bilgilendirme: '.e($infoLevel).'</span>'
                : '';
            $kapsamHtml = '';
            if ($kapsamRows !== []) {
                $rowsHtml = '';
                foreach ($kapsamRows as $krow) {
                    if (! is_array($krow) || ! static::kapsamRowHasEnteredQuantity($krow)) {
                        continue;
                    }
                    $kalem = trim((string) ($krow['kalem'] ?? ''));
                    if ($kalem === '') {
                        continue;
                    }
                    $kDone = static::formatPdfQuantity($krow['gerceklesen'] ?? null);
                    $kPending = static::formatPdfQuantityWhenProvided(
                        $krow['acikta_kalan'] ?? 0,
                        static::hasProvidedNumericValue($krow['acikta_kalan'] ?? null)
                            || static::hasProvidedNumericValue($krow['ongorulen'] ?? null)
                            || static::hasProvidedNumericValue($krow['deger'] ?? null)
                            || static::hasProvidedNumericValue($krow['gerceklesen'] ?? null)
                    );
                    $revizeTarih = AylikFaaliyetWeeklyCarryover::formatDisplayDate($krow['acikta_revize_tarihi'] ?? null);
                    $revizeNotu = trim((string) ($krow['acikta_revize_notu'] ?? ''));
                    $revizeCell = '—';
                    if ($revizeNotu !== '' || $revizeTarih) {
                        $revizeCell = e($revizeTarih ?? '—').($revizeNotu !== '' ? '<br><span style="color:#6b7280;">'.e($revizeNotu).'</span>' : '');
                    }
                    $rowsHtml .= '<tr>'
                        .'<td style="padding:4px 6px;border:1px solid #e5e7eb;">'.e($kalem).'</td>'
                        .'<td style="padding:4px 6px;border:1px solid #e5e7eb;text-align:right;">'.e($kDone).'</td>'
                        .'<td style="padding:4px 6px;border:1px solid #e5e7eb;text-align:right;">'.e($kPending).'</td>'
                        .'<td style="padding:4px 6px;border:1px solid #e5e7eb;font-size:10px;">'.$revizeCell.'</td>'
                        .'</tr>';
                }
                if ($rowsHtml !== '') {
                    $kapsamHtml = '<div style="margin-top:8px;">'
                        .'<div style="font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;">Kalem Kalem Girdi</div>'
                        .'<table style="width:100%;border-collapse:collapse;font-size:11px;">'
                        .'<thead><tr style="background:#f9fafb;"><th style="padding:4px 6px;border:1px solid #e5e7eb;text-align:left;">Kalem</th><th style="padding:4px 6px;border:1px solid #e5e7eb;text-align:right;">Gerçekleşen</th><th style="padding:4px 6px;border:1px solid #e5e7eb;text-align:right;">Açıkta</th><th style="padding:4px 6px;border:1px solid #e5e7eb;text-align:left;">Revize Notu</th></tr></thead>'
                        .'<tbody>'.$rowsHtml.'</tbody>'
                        .'</table>'
                        .'</div>';
                }
            }

            $itemCompletion = $planProvided ? '%'.((int) ($item['completion'] ?? 0)) : '';
            $cardsHtml .= '<div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;margin-bottom:8px;box-sizing:border-box;">'
                .'<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap;">'
                .'<div style="min-width:0;"><div style="font-weight:700;color:#111827;word-break:break-word;">'.e((string) $item['code']).'</div><div style="font-size:12px;color:#4b5563;word-break:break-word;">'.e((string) $item['title']).'</div>'
                .(trim((string) ($item['week_label'] ?? '')) !== '' && trim((string) ($item['week_label'] ?? '')) !== '—'
                    ? '<div style="font-size:11px;color:#6b7280;margin-top:2px;">'.e((string) $item['week_label']).'</div>'
                    : '')
                .'</div>'
                .'<span style="font-size:12px;padding:3px 10px;border-radius:9999px;background:'.e((string) $item['badge_bg']).';color:'.e((string) $item['badge_text']).';">'.e((string) $item['status_label']).'</span>'
                .'</div>'
                .'<div style="display:block;margin-top:8px;font-size:12px;color:#374151;line-height:1.6;">'
                .'<div>Yapılan: <b style="color:'.$doneColor.';">'.e($done !== '' ? $done.$unitSuffix : '').'</b></div>'
                .'<div>Açıkta Bekleyen: <b style="color:'.$pendingColor.';">'.e($pending !== '' ? $pending.$unitSuffix : '').'</b></div>'
                .'<div>Toplam İş: <b style="color:'.$planColor.';">'.e($plan !== '' ? $plan.$unitSuffix : '').'</b></div>'
                .'<div>Sapma: <b>'.e($sapmaNedeni !== '' ? $sapmaNedeni : '—').'</b></div>'
                .'<div>Revize: <b>'.e($gerekliRevize ? 'Evet' : 'Hayır').'</b></div>'
                .'<div>Revize sebebi: <b>'.e($revizeSebebi !== '' ? $revizeSebebi : '—').'</b></div>'
                .'<div>Karar ihtiyacı: <b>'.e($kararIhtiyaci !== '' ? $kararIhtiyaci : '—').'</b></div>'
                .($infoHtml !== '' ? '<div style="margin-top:4px;">'.$infoHtml.'</div>' : '')
                .$kapsamHtml
                .'</div>'
                .'<div style="margin-top:10px;background:#e5e7eb;height:9px;border-radius:9999px;overflow:hidden;">'
                .'<div style="height:100%;width:'.$width.'%;background:'.e((string) $item['bar_color']).';"></div>'
                .'</div>'
                .'<div style="margin-top:6px;font-size:11px;color:#6b7280;">'
                .($itemCompletion !== '' ? 'Tamamlanma oranı: <b>'.e($itemCompletion).'</b>' : '')
                .'</div>'
                .'</div>';
        }

        $riskItems = collect($summary['items'])
            ->filter(fn (array $item): bool => ((float) ($item['pending'] ?? 0.0) > 0.0))
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

        if ($cardsHtml === '') {
            return '<div style="font-size:13px;color:#6b7280;padding:12px;">Bu dönemde iş listesine miktar girilmiş faaliyet bulunmuyor.</div>';
        }

        return '<div>'
            .'<table style="width:100%;border-collapse:separate;border-spacing:8px;table-layout:fixed;">'
            .'<tr>'
            .'<td style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;padding:10px;vertical-align:top;"><div style="font-size:12px;color:#065f46;">Yapılan İş</div><div style="font-size:22px;font-weight:700;color:'.$totalDoneColor.';">'.e($totalDone).'</div></td>'
            .'<td style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:10px;vertical-align:top;"><div style="font-size:12px;color:#1e3a8a;">Açıkta Bekleyen İş</div><div style="font-size:22px;font-weight:700;color:'.$totalPendingColor.';">'.e($totalPending).'</div></td>'
            .'<td style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:10px;vertical-align:top;"><div style="font-size:12px;color:#9a3412;">Toplam İş</div><div style="font-size:22px;font-weight:700;color:'.$totalPlanColor.';">'.e($totalPlan).'</div></td>'
            .'<td style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:10px;padding:10px;vertical-align:top;"><div style="font-size:12px;color:#5b21b6;">Genel Tamamlanma</div><div style="font-size:22px;font-weight:700;color:#5b21b6;">'.e($completionLabel).'</div></td>'
            .'</tr></table>'
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
        $summary = static::summarizeReportForPresentation($record, true, true);
        $mudurluk = trim((string) ($record?->user?->name ?? 'Belirtilmemiş'));
        $yil = (int) ($record?->yil ?? 0);
        $ay = (int) preg_replace('/\D/', '', (string) ($record?->ay ?? ''));
        $period = $yil > 0 && $ay >= 1 && $ay <= 12
            ? ReportPeriodWeeks::monthPeriodLabel($yil, $ay)
            : trim((string) (($record?->yil ?? '—').' / '.str_pad((string) ($record?->ay ?? '—'), 2, '0', STR_PAD_LEFT)));
        $savedAt = static::reportRecordSavedAtLabel($record) ?? now()->format('d.m.Y H:i');
        $rowsHtml = '';

        foreach ($summary['items'] as $item) {
            $doneColor = (bool) ($item['missing_done'] ?? false) ? '#b91c1c' : '#111827';
            $pendingColor = (bool) ($item['missing_pending'] ?? false) ? '#b91c1c' : '#111827';
            $planColor = (bool) ($item['missing_plan'] ?? false) ? '#b91c1c' : '#111827';
            $kapsamDetailHtml = '';
            $kapsamRows = is_array($item['kapsam_rows'] ?? null) ? $item['kapsam_rows'] : [];
            if ($kapsamRows !== []) {
                $kalemRowsHtml = '';
                foreach ($kapsamRows as $kapsam) {
                    if (! is_array($kapsam)) {
                        continue;
                    }
                    $kalem = trim((string) ($kapsam['kalem'] ?? ''));
                    if ($kalem === '') {
                        continue;
                    }
                    $kPlan = static::formatPdfQuantity($kapsam['ongorulen'] ?? $kapsam['deger'] ?? null);
                    $kDone = static::formatPdfQuantity($kapsam['gerceklesen'] ?? null);
                    $pendingProvided = static::hasProvidedNumericValue($kapsam['acikta_kalan'] ?? null)
                        || static::hasProvidedNumericValue($kapsam['ongorulen'] ?? null)
                        || static::hasProvidedNumericValue($kapsam['deger'] ?? null)
                        || static::hasProvidedNumericValue($kapsam['gerceklesen'] ?? null);
                    $kPending = static::formatPdfQuantityWhenProvided(
                        $kapsam['acikta_kalan'] ?? AylikFaaliyetWeeklyCarryover::kapsamPendingAmount($kapsam),
                        $pendingProvided
                    );
                    $weeklyNotes = '';
                    $islemTuruLabel = KapsamIslemTuru::label(
                        KapsamIslemTuru::normalize($kapsam['islem_turu'] ?? null)
                    );
                    if ($islemTuruLabel) {
                        $weeklyNotes .= '<div style="font-size:6px;color:#4b5563;margin-top:1px;">İşlem türü: '.e($islemTuruLabel).'</div>';
                    }
                    $dateRange = static::formatKapsamDateRange(
                        isset($kapsam['baslangic_tarihi']) ? (string) $kapsam['baslangic_tarihi'] : null,
                        isset($kapsam['bitis_tarihi']) ? (string) $kapsam['bitis_tarihi'] : null
                    );
                    if ($dateRange) {
                        $weeklyNotes .= '<div style="font-size:6px;color:#4b5563;margin-top:1px;">Süre: '.e($dateRange).'</div>';
                    }
                    $kayitlar = $kapsam['haftalik_kayitlar'] ?? [];
                    if (is_array($kayitlar)) {
                        foreach ($kayitlar as $kayit) {
                            if (! is_array($kayit)) {
                                continue;
                            }
                            $tarih = AylikFaaliyetWeeklyCarryover::formatDisplayDate($kayit['yapilma_tarihi'] ?? null) ?? '—';
                            $haftaNo = (int) ($kayit['hafta'] ?? 0);
                            $weekLabel = ($yil > 0 && $ay >= 1 && $ay <= 12 && $haftaNo >= 1)
                                ? (ReportPeriodWeeks::weekLabelForRecord($yil, $ay, $haftaNo) ?? ('H'.$haftaNo))
                                : ('H'.$haftaNo);
                            $miktarText = static::formatPdfQuantity($kayit['miktar'] ?? null);
                            $weeklyNotes .= '<div style="font-size:6px;color:#6b7280;margin-top:1px;">'
                                .e($weekLabel)
                                .' · Kayıt: '.$tarih
                                .($miktarText !== '' ? ' · '.e($miktarText) : '')
                                .' — '.e((string) ($kayit['aciklama'] ?? ''))
                                .'</div>';
                        }
                    }
                    $kalemRowsHtml .= '<tr>'
                        .'<td style="border:1px solid #e5e7eb;padding:1px 3px;">'.e($kalem).$weeklyNotes.'</td>'
                        .'<td style="border:1px solid #e5e7eb;padding:1px 3px;text-align:right;">'.e($kDone).'</td>'
                        .'<td style="border:1px solid #e5e7eb;padding:1px 3px;text-align:right;">'.e($kPending).'</td>'
                        .'<td style="border:1px solid #e5e7eb;padding:1px 3px;text-align:right;">'.e($kPlan).'</td>'
                        .'</tr>';
                }
                if ($kalemRowsHtml !== '') {
                    $kapsamDetailHtml = '<div style="margin-top:3px;">'
                        .'<div style="font-size:6.5px;font-weight:700;color:#374151;margin-bottom:1px;">Alt kalemler</div>'
                        .'<table style="width:100%;border-collapse:collapse;font-size:6.5px;">'
                        .'<thead><tr style="background:#f9fafb;">'
                        .'<th style="border:1px solid #e5e7eb;padding:1px 3px;text-align:left;">Kalem</th>'
                        .'<th style="border:1px solid #e5e7eb;padding:1px 3px;text-align:right;width:16%;">Yapılan</th>'
                        .'<th style="border:1px solid #e5e7eb;padding:1px 3px;text-align:right;width:16%;">Açıkta</th>'
                        .'<th style="border:1px solid #e5e7eb;padding:1px 3px;text-align:right;width:16%;">Toplam</th>'
                        .'</tr></thead>'
                        .'<tbody>'.$kalemRowsHtml.'</tbody>'
                        .'</table></div>';
                }
            }
            $sonTarih = '';
            foreach ($kapsamRows as $kapsam) {
                if (! is_array($kapsam)) {
                    continue;
                }
                $formatted = AylikFaaliyetWeeklyCarryover::formatDisplayDate($kapsam['son_yapilma_tarihi'] ?? null);
                if ($formatted) {
                    $sonTarih = $formatted;
                    break;
                }
            }
            $doneProvided = ! (bool) ($item['missing_done'] ?? true);
            $planProvided = ! (bool) ($item['missing_plan'] ?? true);
            $pendingProvided = ! (bool) ($item['missing_pending'] ?? true);
            $doneText = static::formatPdfQuantityWhenProvided($item['done'] ?? 0, $doneProvided);
            $planText = static::formatPdfQuantityWhenProvided($item['plan'] ?? 0, $planProvided);
            // Açıkta: plan/yapılan/açıkta alanlarından biri girildiyse 0 dahil göster.
            $pendingText = static::formatPdfQuantityWhenProvided(
                $item['pending'] ?? 0,
                $pendingProvided || $planProvided || $doneProvided
            );
            $completionText = $planProvided
                ? '%'.((int) ($item['completion'] ?? 0))
                : '';
            $rowsHtml .= '<tr>'
                .'<td>'.e((string) $item['code']).'</td>'
                .'<td>'.e((string) $item['title']).$kapsamDetailHtml.'</td>'
                .'<td style="color:'.$doneColor.';">'.e($doneText).'</td>'
                .'<td style="color:'.$pendingColor.';">'.e($pendingText).'</td>'
                .'<td style="color:'.$planColor.';">'.e($planText).'</td>'
                .'<td>'.e($completionText).'</td>'
                .'<td>'.e((string) $item['status_label']).'</td>'
                .'<td>'.e($sonTarih !== '' ? $sonTarih : '—').'</td>'
                .'</tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="8">Kayıtlı faaliyet bulunamadı.</td></tr>';
        }

        $anyDone = collect($summary['items'])->contains(fn (array $i): bool => ! (bool) ($i['missing_done'] ?? true));
        $anyPlan = collect($summary['items'])->contains(fn (array $i): bool => ! (bool) ($i['missing_plan'] ?? true));
        $anyPending = collect($summary['items'])->contains(fn (array $i): bool => ! (bool) ($i['missing_pending'] ?? true)
            || ! (bool) ($i['missing_plan'] ?? true)
            || ! (bool) ($i['missing_done'] ?? true));
        $summaryDone = $anyDone ? number_format((float) ($summary['total_done'] ?? 0), 0, ',', '.') : '';
        $summaryPending = $anyPending ? number_format((float) ($summary['total_pending'] ?? 0), 0, ',', '.') : '';
        $summaryPlan = $anyPlan ? number_format((float) ($summary['total_plan'] ?? 0), 0, ',', '.') : '';
        $summaryCompletion = $anyPlan
            ? '%'.((int) ($summary['completion'] ?? 0))
            : '';

        return '<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        @page { size: A4 landscape; margin: 6mm; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 7.5px; color: #111827; margin: 0; padding: 0; }
        .title { font-size: 11px; font-weight: 700; margin-bottom: 2px; }
        .meta { font-size: 7px; color: #4b5563; margin-bottom: 4px; }
        .summary { font-size: 7px; margin-bottom: 4px; color: #1f2937; }
        table { width: 100%; border-collapse: collapse; page-break-inside: avoid; }
        th, td { border: 1px solid #d1d5db; padding: 2px 3px; vertical-align: top; text-align: left; line-height: 1.2; }
        th { background: #f3f4f6; font-size: 7px; }
        td { font-size: 7px; }
    </style>
</head>
<body>
    <div class="title">Faaliyet Raporu — '.e($mudurluk).'</div>
    <div class="meta">Dönem: '.e($period).' | Kayıt tarihi: '.e($savedAt).'</div>
    <div class="summary">Yapılan: <b>'.e($summaryDone).'</b>
        · Açıkta: <b>'.e($summaryPending).'</b>
        · Toplam: <b>'.e($summaryPlan).'</b>'
        .($summaryCompletion !== '' ? ' · Tamamlanma: <b>'.e($summaryCompletion).'</b>' : '').'</div>

    <table>
        <thead>
            <tr>
                <th style="width:7%;">Kod</th>
                <th style="width:35%;">Faaliyet / Alt Kalemler</th>
                <th style="width:7%;">Yapılan</th>
                <th style="width:8%;">Açıkta</th>
                <th style="width:7%;">Toplam</th>
                <th style="width:7%;">%</th>
                <th style="width:9%;">Durum</th>
                <th style="width:9%;">Kayıt Tarihi</th>
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
    private static function summarizeReportForPresentation(
        ?AylikFaaliyet $record,
        bool $includePeriodSiblings = false,
        bool $omitEmptyQuantities = false
    ): array {
        $rows = static::rowsForReportPresentation($record, $includePeriodSiblings);
        $items = [];
        $totalDone = 0.0;
        $totalPending = 0.0;
        $totalPlan = 0.0;
        $completedRows = 0;
        $pendingRows = 0;
        $totalsMissing = false;
        $recordYil = (int) ($record?->yil ?? 0);
        $recordAy = (int) preg_replace('/\D/', '', (string) ($record?->ay ?? ''));

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $progress = static::resolveProgressFromFaaliyetRow($row);
            $done = $progress['done'];
            $pending = $progress['pending'];
            $plan = $progress['plan'];
            if ($omitEmptyQuantities && ! static::progressHasEnteredQuantity($progress)) {
                continue;
            }
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

            $weekNumber = $row['hafta'] ?? null;
            if (($weekNumber === null || $weekNumber === '') && $recordYil > 0 && $recordAy >= 1 && $recordAy <= 12) {
                $weekNumber = ReportPeriodWeeks::resolveWeekForReportPeriod($recordYil, $recordAy);
            }
            $weekLabel = ReportPeriodWeeks::weekLabelForRecord($recordYil, $recordAy, $weekNumber) ?? '—';

            $items[] = [
                'code' => trim((string) ($row['faaliyet_kodu'] ?? 'Faaliyet')),
                'title' => static::resolveReportRowTitle($row, $recordYil, $recordAy),
                'week_label' => $weekLabel,
                'unit' => trim((string) ($row['olcu_birimi'] ?? '')),
                'info_level' => trim((string) ($row['baskanlik_bilgilendirme_seviyesi'] ?? '')),
                'sapma_nedeni' => trim((string) ($row['sapma_nedeni'] ?? '')),
                'gerekli_revize' => (bool) ($row['gerekli_revize'] ?? false),
                'revize_sebebi' => trim((string) ($row['revize_sebebi'] ?? '')),
                'karar_ihtiyaci' => trim((string) ($row['karar_ihtiyaci'] ?? '')),
                'kapsam_rows' => $omitEmptyQuantities
                    ? array_values(array_filter(
                        static::kapsamRowsForSummary($row),
                        fn (array $kapsam): bool => static::kapsamRowHasEnteredQuantity($kapsam)
                    ))
                    : static::kapsamRowsForSummary($row),
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
     * @return list<array{
     *   kalem: string,
     *   ongorulen: float,
     *   gerceklesen: float,
     *   acikta_kalan: float,
     *   haftalik_kayitlar: list<mixed>,
     *   son_yapilma_tarihi: mixed,
     *   acikta_revize_tarihi: mixed,
     *   acikta_revize_notu: mixed
     * }>
     */
    private static function kapsamRowsForSummary(array $row): array
    {
        $kapsamRows = $row['kapsam_verileri'] ?? null;
        if (! is_array($kapsamRows) || $kapsamRows === []) {
            return [];
        }

        $out = [];
        foreach ($kapsamRows as $kapsamRow) {
            if (! is_array($kapsamRow)) {
                continue;
            }
            $kalem = trim((string) ($kapsamRow['kalem'] ?? ''));
            if ($kalem === '') {
                continue;
            }
            $plan = static::toFloatNumber($kapsamRow['ongorulen'] ?? $kapsamRow['deger'] ?? 0);
            $done = static::toFloatNumber($kapsamRow['gerceklesen'] ?? 0);
            $pending = AylikFaaliyetWeeklyCarryover::kapsamPendingAmount($kapsamRow);

            $out[] = [
                'kalem' => $kalem,
                'ongorulen' => max(0.0, $plan),
                'gerceklesen' => max(0.0, $done),
                'acikta_kalan' => max(0.0, $pending),
                'haftalik_kayitlar' => is_array($kapsamRow['haftalik_kayitlar'] ?? null) ? $kapsamRow['haftalik_kayitlar'] : [],
                'son_yapilma_tarihi' => $kapsamRow['son_yapilma_tarihi'] ?? null,
                'baslangic_tarihi' => $kapsamRow['baslangic_tarihi'] ?? null,
                'bitis_tarihi' => $kapsamRow['bitis_tarihi'] ?? null,
                'islem_turu' => KapsamIslemTuru::normalize($kapsamRow['islem_turu'] ?? null),
                'acikta_revize_tarihi' => $kapsamRow['acikta_revize_tarihi'] ?? null,
                'acikta_revize_notu' => $kapsamRow['acikta_revize_notu'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function resolveReportRowTitle(array $row, ?int $year = null, ?int $month = null): string
    {
        $weekLabel = ReportPeriodWeeks::weekLabelForRecord($year, $month, $row['hafta'] ?? null);

        $scope = trim((string) ($row['kapsam_icerigi'] ?? ''));
        if ($scope !== '') {
            return $weekLabel ? $scope.' | '.$weekLabel : $scope;
        }

        if ($weekLabel) {
            return $weekLabel;
        }

        $week = trim((string) ($row['hafta'] ?? ''));
        if ($week !== '' && ! ReportPeriodWeeks::isMonthlyPeriod($row['hafta'] ?? null)) {
            return 'Hafta: '.$week;
        }
        if (ReportPeriodWeeks::isMonthlyPeriod($row['hafta'] ?? null)) {
            return 'Aylık rapor';
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

    /**
     * Satırda en az bir miktar alanı girilmiş mi? (0 dahil; boş/null hariç)
     *
     * @param  array{missing_done?: bool, missing_pending?: bool, missing_plan?: bool}  $progress
     */
    public static function progressHasEnteredQuantity(array $progress): bool
    {
        return ! ((bool) ($progress['missing_done'] ?? true)
            && (bool) ($progress['missing_pending'] ?? true)
            && (bool) ($progress['missing_plan'] ?? true));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function faaliyetRowHasEnteredQuantity(array $row): bool
    {
        $kapsamRows = $row['kapsam_verileri'] ?? null;
        if (is_array($kapsamRows) && $kapsamRows !== []) {
            foreach ($kapsamRows as $kapsamRow) {
                if (is_array($kapsamRow) && static::kapsamRowHasEnteredQuantity($kapsamRow)) {
                    return true;
                }
            }

            return false;
        }

        return static::hasProvidedNumericValue($row['hedef'] ?? null)
            || static::hasProvidedNumericValue($row['ongorulen'] ?? null)
            || static::hasProvidedNumericValue($row['deger'] ?? null)
            || static::hasProvidedNumericValue($row['gerceklesen'] ?? null)
            || static::hasProvidedNumericValue($row['bekleyen_is'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $kapsam
     */
    public static function kapsamRowHasEnteredQuantity(array $kapsam): bool
    {
        return static::hasProvidedNumericValue($kapsam['ongorulen'] ?? null)
            || static::hasProvidedNumericValue($kapsam['deger'] ?? null)
            || static::hasProvidedNumericValue($kapsam['gerceklesen'] ?? null)
            || static::hasProvidedNumericValue($kapsam['acikta_kalan'] ?? null);
    }

    /**
     * PDF miktar gösterimi: girilmiş değerler (0 dahil) yazılır; boş/null yazılmaz.
     */
    public static function formatPdfQuantity(mixed $value): string
    {
        if (! static::hasProvidedNumericValue($value)) {
            return '';
        }

        if (is_string($value)) {
            $normalized = trim(str_replace(["\xc2\xa0", ' '], '', $value));
            $value = is_numeric($normalized) ? (float) $normalized : 0.0;
        }

        return number_format((float) $value, 0, ',', '.');
    }

    /**
     * Özet satırındaki hesaplanmış miktar: alan girilmediyse boş, girildiyse 0 dahil göster.
     */
    public static function formatPdfQuantityWhenProvided(mixed $value, bool $wasProvided): string
    {
        if (! $wasProvided) {
            return '';
        }

        return number_format((float) $value, 0, ',', '.');
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
        $key = (int) ($record->user_id ?? 0).'|'.(int) ($record->yil ?? 0).'|'.str_pad((string) ($record->ay ?? ''), 2, '0', STR_PAD_LEFT);
        if (array_key_exists($key, static::$extraordinarySituationSummaryCache)) {
            return static::$extraordinarySituationSummaryCache[$key];
        }

        $last = ExtraordinarySituation::query()
            ->with('reporter:id,name')
            ->where('target_user_id', (int) $record->user_id)
            ->where('yil', (int) $record->yil)
            ->where('ay', str_pad((string) $record->ay, 2, '0', STR_PAD_LEFT))
            ->latest('id')
            ->first();

        if (! $last instanceof ExtraordinarySituation) {
            return static::$extraordinarySituationSummaryCache[$key] = '—';
        }

        $reporterName = trim((string) ($last->reporter?->name ?? ''));
        if ($reporterName === '') {
            $reporterName = 'Sistem';
        }
        $message = trim((string) $last->message);
        if ($message === '') {
            return static::$extraordinarySituationSummaryCache[$key] = $reporterName;
        }

        return static::$extraordinarySituationSummaryCache[$key] = $reporterName.': '.$message;
    }

    private static function extraordinarySituationDetailText(?AylikFaaliyet $record): string
    {
        if (! $record instanceof AylikFaaliyet) {
            return '—';
        }

        $rows = ExtraordinarySituation::query()
            ->with('reporter:id,name')
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
            $reporterName = trim((string) ($row->reporter?->name ?? ''));
            $reporterName = $reporterName !== '' ? e($reporterName) : 'Sistem';
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
                    'haftalik_kayitlar' => is_array($satir['haftalik_kayitlar'] ?? null) ? $satir['haftalik_kayitlar'] : [],
                    'son_yapilma_tarihi' => $satir['son_yapilma_tarihi'] ?? null,
                    'baslangic_tarihi' => $satir['baslangic_tarihi'] ?? null,
                    'bitis_tarihi' => $satir['bitis_tarihi'] ?? null,
                    'islem_turu' => KapsamIslemTuru::normalize($satir['islem_turu'] ?? null),
                    'acikta_revize_tarihi' => $satir['acikta_revize_tarihi'] ?? null,
                    'acikta_revize_notu' => $satir['acikta_revize_notu'] ?? null,
                    'acikta_kapatildi' => (bool) ($satir['acikta_kapatildi'] ?? false),
                    'acikta_kapatma_notu' => $satir['acikta_kapatma_notu'] ?? null,
                ];
            }
        }

        $out = [];
        foreach ($kalemler as $kalem) {
            $prev = $harita[$kalem] ?? [
                'ongorulen' => null,
                'gerceklesen' => null,
                'haftalik_kayitlar' => [],
                'son_yapilma_tarihi' => null,
                'baslangic_tarihi' => null,
                'bitis_tarihi' => null,
                'islem_turu' => null,
                'acikta_revize_tarihi' => null,
                'acikta_revize_notu' => null,
                'acikta_kapatildi' => false,
                'acikta_kapatma_notu' => null,
            ];
            $row = [
                'kalem' => $kalem,
                'ongorulen' => $prev['ongorulen'],
                'gerceklesen' => $prev['gerceklesen'],
                'haftalik_kayitlar' => $prev['haftalik_kayitlar'],
                'son_yapilma_tarihi' => $prev['son_yapilma_tarihi'],
                'baslangic_tarihi' => $prev['baslangic_tarihi'],
                'bitis_tarihi' => $prev['bitis_tarihi'],
                'islem_turu' => $prev['islem_turu'],
                'acikta_revize_tarihi' => $prev['acikta_revize_tarihi'],
                'acikta_revize_notu' => $prev['acikta_revize_notu'],
                'acikta_kapatildi' => $prev['acikta_kapatildi'],
                'acikta_kapatma_notu' => $prev['acikta_kapatma_notu'],
            ];
            $row['acikta_kalan'] = AylikFaaliyetRepeaterLock::kapsamSatirAciktaKalan($row);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyAutoHaftaToFaaliyetler(array $data): array
    {
        return static::hydrateFaaliyetHaftaFields($data);
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
