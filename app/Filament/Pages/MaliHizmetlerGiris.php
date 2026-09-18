<?php

namespace App\Filament\Pages;

use App\Models\MaliHizmetlerRapor;
use App\Models\User;
use App\Support\MaliHizmetlerOdemeTalep;
use App\Support\MaliHizmetlerPeriod;
use App\Support\MaliHizmetlerRaporForm;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class MaliHizmetlerGiris extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Mali Veri Girişi';

    protected static ?string $title = 'Mali Veri Girişi';

    protected static ?string $navigationGroup = 'Raporlama';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.mali-hizmetler-giris';

    protected static ?string $slug = 'mali-hizmetler-giris';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->isMaliHizmetlerAccount()
            && ! $user->isReportingSuperAdmin();
    }

    public function mount(): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $period = MaliHizmetlerPeriod::currentWeekAttributes();
        $this->form->fill([
            'yil' => $period['yil'],
            'ay' => $period['ay'],
            'hafta' => $period['hafta'],
        ]);

        $this->loadPeriodData();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema(MaliHizmetlerRaporForm::dataEntrySchema(includePeriodSelector: true))
            ->statePath('data');
    }

    public function updatedDataYil(): void
    {
        $this->loadPeriodData();
    }

    public function updatedDataAy(): void
    {
        $this->loadPeriodData();
    }

    public function updatedDataHafta(): void
    {
        $this->loadPeriodData();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('kaydet')
                ->label('Kaydet')
                ->icon('heroicon-o-check')
                ->action(fn () => $this->save()),
        ];
    }

    public function save(): void
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $user->isMaliHizmetlerAccount()) {
            return;
        }

        $state = MaliHizmetlerPeriod::normalizeOdemeTalepleri($this->form->getState());
        $period = MaliHizmetlerPeriod::normalizePeriodAttributes($state);

        MaliHizmetlerRapor::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'yil' => $period['yil'],
                'ay' => $period['ay'],
                'hafta' => $period['hafta'],
            ],
            [
                'kasa_tutari' => 0,
                'haftalik_odeme_toplam' => $state['haftalik_odeme_toplam'] ?? 0,
                'odeme_talepleri' => $state['odeme_talepleri'] ?? [],
            ]
        );

        Notification::make()
            ->title('Ödeme planı kaydedildi')
            ->body($this->getDonemLabel())
            ->success()
            ->send();
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

    private function loadPeriodData(): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $period = MaliHizmetlerPeriod::normalizePeriodAttributes($this->data ?? []);
        $record = MaliHizmetlerPeriod::resolveRecordForUserAndPeriod(
            $user,
            $period['yil'],
            $period['ay'],
            $period['hafta'],
        );

        $this->data = array_merge($this->data ?? [], [
            'yil' => $period['yil'],
            'ay' => $period['ay'],
            'hafta' => $period['hafta'],
            'haftalik_odeme_toplam' => $record?->haftalik_odeme_toplam ?? 0,
            'odeme_talepleri' => $this->simplifyOdemeTalepleriForForm($record?->odeme_talepleri),
        ]);

        $this->form->fill($this->data);
    }

    /**
     * @param  mixed  $items
     * @return list<array<string, mixed>>
     */
    private function simplifyOdemeTalepleriForForm(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $out[] = MaliHizmetlerOdemeTalep::toFormRow($item);
        }

        return $out;
    }
}
