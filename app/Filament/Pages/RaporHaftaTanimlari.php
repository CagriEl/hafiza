<?php

namespace App\Filament\Pages;

use App\Models\RaporHaftaTanimi;
use App\Models\User;
use App\Support\ReportPeriodWeeks;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class RaporHaftaTanimlari extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Hafta Tanımları';

    protected static ?string $title = 'Rapor Hafta Tanımları';

    protected static ?string $navigationGroup = 'Yönetim';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.rapor-hafta-tanimlari';

    protected static ?string $slug = 'rapor-hafta-tanimlari';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageRaporHaftalari();
    }

    public function mount(): void
    {
        $now = now();
        $this->form->fill([
            'yil' => (int) $now->year,
            'ay' => (int) $now->month,
        ]);

        $this->loadPeriodWeeks();
    }

    public function form(Form $form): Form
    {
        $weekFields = [];
        for ($hafta = 1; $hafta <= ReportPeriodWeeks::WEEK_COUNT; $hafta++) {
            $weekRequired = $hafta < ReportPeriodWeeks::WEEK_COUNT;
            $weekFields[] = Section::make($hafta.'. Hafta'.($weekRequired ? '' : ' (opsiyonel)'))
                ->schema([
                    Grid::make(2)->schema([
                        DatePicker::make("haftalar.{$hafta}.baslangic")
                            ->label('Başlangıç')
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->required($weekRequired)
                            ->live(),
                        DatePicker::make("haftalar.{$hafta}.bitis")
                            ->label('Bitiş')
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->required($weekRequired)
                            ->afterOrEqual("haftalar.{$hafta}.baslangic"),
                    ]),
                ])
                ->compact()
                ->columnSpan(1);
        }

        return $form
            ->schema([
                Section::make('Dönem')
                    ->description('Yıl ve ay seçin. 1.–4. hafta tarih aralıkları zorunludur; 5. hafta isteğe bağlıdır. İsterseniz otomatik öneriyi yükleyip düzenleyebilirsiniz.')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('yil')
                                ->label('Yıl')
                                ->options(self::yearOptions())
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn () => $this->loadPeriodWeeks()),
                            Select::make('ay')
                                ->label('Ay')
                                ->options(ReportPeriodWeeks::turkishMonthNames())
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn () => $this->loadPeriodWeeks()),
                        ]),
                    ]),
                Section::make('Hafta Tarih Aralıkları')
                    ->schema($weekFields)
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function updatedDataYil(): void
    {
        $this->loadPeriodWeeks();
    }

    public function updatedDataAy(): void
    {
        $this->loadPeriodWeeks();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('otomatikOner')
                ->label('Otomatik öner')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->action(fn () => $this->fillComputedWeeks()),
            Action::make('kaydet')
                ->label('Kaydet')
                ->icon('heroicon-o-check')
                ->action(fn () => $this->save()),
            Action::make('sil')
                ->label('Bu ayı sil')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Hafta tanımlarını sil')
                ->modalDescription('Seçili yıl/ay için kayıtlı tüm hafta tanımları silinecek. Raporlama otomatik takvime döner.')
                ->visible(fn (): bool => $this->hasSavedDefinitions())
                ->action(fn () => $this->deletePeriod()),
        ];
    }

    public function save(): void
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $user->canManageRaporHaftalari()) {
            return;
        }

        $state = $this->form->getState();
        $yil = (int) ($state['yil'] ?? 0);
        $ay = (int) preg_replace('/\D/', '', (string) ($state['ay'] ?? ''));
        $ayPadded = str_pad((string) $ay, 2, '0', STR_PAD_LEFT);
        $haftalar = $state['haftalar'] ?? [];

        if ($yil <= 0 || $ay < 1 || $ay > 12) {
            Notification::make()->title('Geçersiz dönem')->danger()->send();

            return;
        }

        for ($hafta = 1; $hafta <= ReportPeriodWeeks::WEEK_COUNT; $hafta++) {
            $baslangic = $haftalar[$hafta]['baslangic'] ?? null;
            $bitis = $haftalar[$hafta]['bitis'] ?? null;
            $weekRequired = $hafta < ReportPeriodWeeks::WEEK_COUNT;
            $hasStart = filled($baslangic);
            $hasEnd = filled($bitis);

            if (! $hasStart && ! $hasEnd) {
                if ($weekRequired) {
                    Notification::make()
                        ->title($hafta.'. hafta için tarih aralığı zorunludur')
                        ->danger()
                        ->send();

                    return;
                }

                // 5. hafta boş bırakıldıysa mevcut tanımı kaldır.
                RaporHaftaTanimi::query()
                    ->where('yil', $yil)
                    ->where('ay', $ayPadded)
                    ->where('hafta', $hafta)
                    ->delete();

                continue;
            }

            if ($hasStart xor $hasEnd) {
                Notification::make()
                    ->title($hafta.'. hafta için başlangıç ve bitiş birlikte girilmelidir')
                    ->danger()
                    ->send();

                return;
            }

            $start = Carbon::parse($baslangic)->startOfDay();
            $end = Carbon::parse($bitis)->startOfDay();

            if ($end->lt($start)) {
                Notification::make()
                    ->title($hafta.'. hafta bitiş tarihi başlangıçtan önce olamaz')
                    ->danger()
                    ->send();

                return;
            }

            // Preserve original creator on update.
            $existing = RaporHaftaTanimi::query()
                ->where('yil', $yil)
                ->where('ay', $ayPadded)
                ->where('hafta', $hafta)
                ->first();

            RaporHaftaTanimi::query()->updateOrCreate(
                [
                    'yil' => $yil,
                    'ay' => $ayPadded,
                    'hafta' => $hafta,
                ],
                [
                    'baslangic' => $start->toDateString(),
                    'bitis' => $end->toDateString(),
                    'updated_by' => $user->id,
                    'created_by' => $existing?->created_by ?? $user->id,
                ]
            );
        }

        Notification::make()
            ->title('Hafta tanımları kaydedildi')
            ->success()
            ->send();

        $this->loadPeriodWeeks();
    }

    public function deletePeriod(): void
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $user->canManageRaporHaftalari()) {
            return;
        }

        [$yil, $ay] = $this->selectedPeriod();
        if ($yil === null || $ay === null) {
            return;
        }

        $ayPadded = str_pad((string) $ay, 2, '0', STR_PAD_LEFT);

        RaporHaftaTanimi::query()
            ->where('yil', $yil)
            ->where('ay', $ayPadded)
            ->delete();

        Notification::make()
            ->title('Hafta tanımları silindi')
            ->success()
            ->send();

        $this->fillComputedWeeks();
    }

    public function loadSavedPeriod(int $yil, string|int $ay): void
    {
        $this->form->fill([
            'yil' => $yil,
            'ay' => (int) $ay,
        ]);
        $this->loadPeriodWeeks();
    }

    /**
     * @return Collection<int, object{yil: int, ay: string, hafta_sayisi: int, baslangic: string, bitis: string}>
     */
    public function getDefinedPeriodsProperty(): Collection
    {
        return RaporHaftaTanimi::query()
            ->selectRaw('yil, ay, COUNT(*) as hafta_sayisi, MIN(baslangic) as baslangic, MAX(bitis) as bitis')
            ->groupBy('yil', 'ay')
            ->orderByDesc('yil')
            ->orderByDesc('ay')
            ->get();
    }

    protected function loadPeriodWeeks(): void
    {
        [$yil, $ay] = $this->selectedPeriod();
        if ($yil === null || $ay === null) {
            return;
        }

        $custom = ReportPeriodWeeks::customWeeksForMonth($yil, $ay);
        $weeks = $custom ?? ReportPeriodWeeks::computedWeeksForMonth($yil, $ay);

        $haftalar = [];
        foreach ($weeks as $week) {
            $hafta = (int) $week['hafta'];
            $haftalar[$hafta] = [
                'baslangic' => $week['baslangic']->toDateString(),
                'bitis' => $week['bitis']->toDateString(),
            ];
        }

        for ($hafta = 1; $hafta <= ReportPeriodWeeks::WEEK_COUNT; $hafta++) {
            $haftalar[$hafta] ??= ['baslangic' => null, 'bitis' => null];
        }

        $this->data = array_merge($this->data ?? [], [
            'yil' => $yil,
            'ay' => $ay,
            'haftalar' => $haftalar,
        ]);
    }

    protected function fillComputedWeeks(): void
    {
        [$yil, $ay] = $this->selectedPeriod();
        if ($yil === null || $ay === null) {
            return;
        }

        $haftalar = [];
        foreach (ReportPeriodWeeks::computedWeeksForMonth($yil, $ay) as $week) {
            $haftalar[(int) $week['hafta']] = [
                'baslangic' => $week['baslangic']->toDateString(),
                'bitis' => $week['bitis']->toDateString(),
            ];
        }

        $this->data = array_merge($this->data ?? [], [
            'haftalar' => $haftalar,
        ]);

        Notification::make()
            ->title('Otomatik hafta aralıkları yüklendi')
            ->success()
            ->send();
    }

    protected function hasSavedDefinitions(): bool
    {
        [$yil, $ay] = $this->selectedPeriod();
        if ($yil === null || $ay === null) {
            return false;
        }

        return RaporHaftaTanimi::hasDefinitionsForPeriod($yil, $ay);
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    protected function selectedPeriod(): array
    {
        $yil = (int) ($this->data['yil'] ?? 0);
        $ay = (int) preg_replace('/\D/', '', (string) ($this->data['ay'] ?? ''));

        if ($yil <= 0 || $ay < 1 || $ay > 12) {
            return [null, null];
        }

        return [$yil, $ay];
    }

    /**
     * @return array<int, int>
     */
    protected static function yearOptions(): array
    {
        $current = (int) now()->year;
        $options = [];
        for ($year = $current - 1; $year <= $current + 2; $year++) {
            $options[$year] = $year;
        }

        return $options;
    }
}
