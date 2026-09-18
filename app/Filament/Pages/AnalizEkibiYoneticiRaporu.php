<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\AnalizEkibiYoneticiRapor;
use App\Support\ReportPeriodWeeks;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;

class AnalizEkibiYoneticiRaporu extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Yönetici Raporu';

    protected static ?string $title = 'Haftalık Yönetici Raporu';

    protected static ?string $navigationGroup = 'Raporlama';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'analiz-ekibi-yonetici-raporu';

    protected static string $view = 'filament.pages.analiz-ekibi-yonetici-raporu';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    protected ?array $reportCache = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isReportingSuperAdmin();
    }

    public function mount(): void
    {
        $yil = (int) now()->year;
        $ay = (int) now()->month;

        $this->form->fill([
            'yil' => $yil,
            'ay' => $ay,
            'hafta' => ReportPeriodWeeks::resolveWeekForReportPeriod($yil, $ay),
        ]);
        $this->clearReportCache();
    }

    protected function clearReportCache(): void
    {
        $this->reportCache = null;
    }

    public function form(Form $form): Form
    {
        $current = (int) now()->year;
        $years = [];
        for ($year = $current - 1; $year <= $current + 1; $year++) {
            $years[$year] = $year;
        }

        return $form
            ->schema([
                Section::make('Rapor Haftası')
                    ->description('Tüm müdürlüklerin seçilen haftaya ait performans, 0 girilen kod ve açıkta kalan iş özeti.')
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('yil')
                                ->label('Yıl')
                                ->options($years)
                                ->required()
                                ->live(debounce: 300)
                                ->afterStateUpdated(function ($state, callable $set, Get $get): void {
                                    $ay = (int) ($get('ay') ?? now()->month);
                                    $yil = (int) ($state ?? now()->year);
                                    $options = ReportPeriodWeeks::selectOptions($yil, $ay);
                                    $hafta = (int) ($get('hafta') ?? 0);
                                    if ($options !== [] && ! array_key_exists($hafta, $options)) {
                                        $set('hafta', array_key_first($options));
                                    }
                                    $this->clearReportCache();
                                }),
                            Select::make('ay')
                                ->label('Ay')
                                ->options(ReportPeriodWeeks::turkishMonthNames())
                                ->required()
                                ->live(debounce: 300)
                                ->afterStateUpdated(function ($state, callable $set, Get $get): void {
                                    $yil = (int) ($get('yil') ?? now()->year);
                                    $ay = (int) ($state ?? now()->month);
                                    $options = ReportPeriodWeeks::selectOptions($yil, $ay);
                                    $hafta = (int) ($get('hafta') ?? 0);
                                    if ($options !== [] && ! array_key_exists($hafta, $options)) {
                                        $set('hafta', array_key_first($options));
                                    }
                                    $this->clearReportCache();
                                }),
                            Select::make('hafta')
                                ->label('Hafta')
                                ->options(function (Get $get): array {
                                    $yil = (int) ($get('yil') ?? now()->year);
                                    $ay = (int) ($get('ay') ?? now()->month);

                                    return ReportPeriodWeeks::selectOptions($yil, $ay);
                                })
                                ->required()
                                ->live(debounce: 300)
                                ->afterStateUpdated(fn () => $this->clearReportCache()),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * @return array<string, mixed>
     */
    public function getReport(): array
    {
        if (is_array($this->reportCache)) {
            return $this->reportCache;
        }

        $user = auth()->user();
        $yil = (int) ($this->data['yil'] ?? now()->year);
        $ay = (int) ($this->data['ay'] ?? now()->month);
        $hafta = $this->data['hafta'] ?? ReportPeriodWeeks::resolveWeekForReportPeriod($yil, $ay);

        if (! $user instanceof User) {
            return $this->reportCache = [
                'yil' => $yil,
                'ay' => str_pad((string) $ay, 2, '0', STR_PAD_LEFT),
                'hafta' => (string) $hafta,
                'donem_etiketi' => '',
                'ozet' => [
                    'mudurluk_sayisi' => 0,
                    'rapor_olan' => 0,
                    'rapor_olmayan' => 0,
                    'hedef' => 0,
                    'yapilan' => 0,
                    'acikta' => 0,
                    'tamamlanma_orani' => null,
                    'sifir_kod_sayisi' => 0,
                    'toplam_kod' => 0,
                    'acikta_kalem_sayisi' => 0,
                ],
                'olgunluk' => [
                    'veri_kalitesi' => 0,
                    'zamaninda_kapanis' => 0,
                    'risk_yonetimi' => 0,
                    'aksiyon_kapanis' => 0,
                ],
                'aksiyonlar' => [],
                'risk_haritasi' => [],
                'sifir_girilen_kodlar' => [],
                'acikta_kalan_isler' => [],
                'mudurlukler' => [],
            ];
        }

        return $this->reportCache = AnalizEkibiYoneticiRapor::buildWeeklyOverview(
            $user,
            $yil,
            $ay,
            $hafta,
        );
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }
}
