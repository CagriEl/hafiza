<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\AnalizEkibiMudurlukRapor;
use App\Support\ReportPeriodWeeks;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;

class AnalizEkibiGenelBakis extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-bar';

    protected static ?string $navigationLabel = 'Genel Bakış';

    protected static ?string $title = 'Müdürlük Detay Raporu';

    protected static ?string $navigationGroup = 'Raporlama';

    protected static ?int $navigationSort = -1;

    protected static ?string $slug = 'analiz-ekibi-genel-bakis';

    protected static string $view = 'filament.pages.analiz-ekibi-genel-bakis';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isControlTeam();
    }

    public function mount(): void
    {
        $now = now();
        $this->form->fill([
            'yil' => (int) $now->year,
            'ay' => (int) $now->month,
        ]);
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
                Section::make('Rapor Dönemi')
                    ->description('Bağlı müdürlüklerinizin seçilen aya ait faaliyet özeti ve satır detayları.')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('yil')
                                ->label('Yıl')
                                ->options($years)
                                ->required()
                                ->live(),
                            Select::make('ay')
                                ->label('Ay')
                                ->options(ReportPeriodWeeks::turkishMonthNames())
                                ->required()
                                ->live(),
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
        $user = auth()->user();
        if (! $user instanceof User || ! $user->isControlTeam()) {
            return [
                'yil' => (int) now()->year,
                'ay' => str_pad((string) now()->month, 2, '0', STR_PAD_LEFT),
                'donem_etiketi' => '',
                'ozet' => [
                    'mudurluk_sayisi' => 0,
                    'rapor_olan' => 0,
                    'rapor_olmayan' => 0,
                    'hedef' => 0,
                    'gerceklesen' => 0,
                    'kalan' => 0,
                    'tamamlanma_orani' => null,
                    'revize_karar' => 0,
                    'dikkat_sayisi' => 0,
                    'gecen_ay_fark' => null,
                ],
                'mudurlukler' => [],
            ];
        }

        $yil = (int) ($this->data['yil'] ?? now()->year);
        $ay = (int) ($this->data['ay'] ?? now()->month);

        return AnalizEkibiMudurlukRapor::buildForUser($user, $yil, $ay);
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\AnalizEkibiMudurlukChart::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
