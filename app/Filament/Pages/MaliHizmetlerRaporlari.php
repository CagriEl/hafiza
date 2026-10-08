<?php

namespace App\Filament\Pages;

use App\Support\MaliHizmetlerAccess;
use App\Support\MaliHizmetlerPeriod;
use App\Support\MaliHizmetlerRaporForm;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;

class MaliHizmetlerRaporlari extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationLabel = 'Mali Raporları';

    protected static ?string $title = 'Mali Raporları';

    protected static ?string $navigationGroup = 'Raporlama';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'mali-hizmetler-raporlari';

    protected static string $view = 'filament.pages.mali-hizmetler-raporlari';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return MaliHizmetlerAccess::userCanManageMaliRaporlar(auth()->user());
    }

    public function mount(): void
    {
        $period = MaliHizmetlerPeriod::currentWeekAttributes();
        $this->form->fill($period);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\Section::make('Rapor Haftası')
                    ->description('Ödeme planı grafikleri seçili hafta için gösterilir.')
                    ->schema(MaliHizmetlerRaporForm::userPeriodSchema())
                    ->columns(1),
            ])
            ->statePath('data');
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\MaliHizmetlerChart::class,
        ];
    }

    /**
     * @return array{yil: int, ay: string, hafta: int}
     */
    public function getSelectedPeriod(): array
    {
        return MaliHizmetlerPeriod::normalizePeriodAttributes($this->data ?? []);
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    public function getDonemLabel(): string
    {
        $period = MaliHizmetlerPeriod::normalizePeriodAttributes($this->data ?? []);

        return MaliHizmetlerPeriod::periodLabel(
            $period['yil'],
            $period['ay'],
            $period['hafta'],
        );
    }
}
