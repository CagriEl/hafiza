<?php

namespace App\Filament\Pages;

use App\Models\KoordinasyonHafta;
use App\Models\KoordinasyonTakipMadde;
use App\Models\User;
use App\Support\AnalizEkibiKoordinasyonPlan;
use App\Support\ReportPeriodWeeks;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AnalizEkibiKoordinasyonTakibi extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Koordinasyon Takibi';

    protected static ?string $title = 'Analiz Ekibi Koordinasyon Takibi';

    protected static ?string $navigationGroup = 'Raporlama';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'analiz-ekibi-koordinasyon-takibi';

    protected static string $view = 'filament.pages.analiz-ekibi-koordinasyon-takibi';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $maddeData = [];

    public ?int $editingMaddeId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isReportingSuperAdmin();
    }

    public function mount(): void
    {
        $yil = (int) now()->year;
        $ay = (int) now()->month;
        $hafta = (int) ReportPeriodWeeks::resolveWeekForReportPeriod($yil, $ay);

        $haftaKayit = $this->resolveHaftaKayit($yil, $ay, $hafta);

        $this->form->fill([
            'yil' => $yil,
            'ay' => $ay,
            'hafta' => $hafta,
            'checklist' => array_merge(
                KoordinasyonHafta::defaultChecklist(),
                (array) ($haftaKayit->checklist ?? []),
            ),
            'ozet_not' => $haftaKayit->ozet_not,
        ]);

        $this->resetMaddeForm();
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
                Section::make('Hafta seçimi')
                    ->description('Seçilen haftanın checklist’i, özet notu ve takip maddeleri bu ekranda yönetilir.')
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('yil')
                                ->label('Yıl')
                                ->options($years)
                                ->required()
                                ->live(debounce: 300)
                                ->afterStateUpdated(fn () => $this->reloadHaftaState()),
                            Select::make('ay')
                                ->label('Ay')
                                ->options(ReportPeriodWeeks::turkishMonthNames())
                                ->required()
                                ->live(debounce: 300)
                                ->afterStateUpdated(fn () => $this->reloadHaftaState()),
                            Select::make('hafta')
                                ->label('Hafta')
                                ->options(function (Get $get): array {
                                    $yil = (int) ($get('yil') ?? now()->year);
                                    $ay = (int) ($get('ay') ?? now()->month);

                                    return ReportPeriodWeeks::selectOptions($yil, $ay);
                                })
                                ->required()
                                ->live(debounce: 300)
                                ->afterStateUpdated(fn () => $this->reloadHaftaState()),
                        ]),
                        Grid::make(2)->schema([
                            Checkbox::make('checklist.pazartesi_sync')
                                ->label('Pazartesi sync yapıldı'),
                            Checkbox::make('checklist.saha_turu')
                                ->label('Saha / kaynak teyidi turu yapıldı'),
                            Checkbox::make('checklist.cuma_kapanis')
                                ->label('Cuma kapanış yapıldı'),
                            Checkbox::make('checklist.ust_yonetime_ozet')
                                ->label('Üst yönetime özet iletildi'),
                        ]),
                        Textarea::make('ozet_not')
                            ->label('Haftalık özet not')
                            ->rows(3)
                            ->placeholder('Engeller, kararlar, üst yönetime taşınacak konular…'),
                    ]),
            ])
            ->statePath('data');
    }

    public function maddeForm(Form $form): Form
    {
        return $form
            ->schema([
                Section::make($this->editingMaddeId ? 'Maddeyi düzenle' : 'Yeni takip maddesi')
                    ->schema([
                        TextInput::make('baslik')
                            ->label('Konu / kalem')
                            ->required()
                            ->maxLength(255),
                        Grid::make(2)->schema([
                            Select::make('analiz_user_id')
                                ->label('Sorumlu analiz')
                                ->options(fn (): array => $this->analizOptions())
                                ->searchable()
                                ->nullable(),
                            Select::make('directorate_user_id')
                                ->label('Müdürlük')
                                ->options(fn (): array => $this->mudurlukOptions())
                                ->searchable()
                                ->nullable(),
                        ]),
                        Grid::make(2)->schema([
                            Select::make('durum')
                                ->label('Durum')
                                ->options(KoordinasyonTakipMadde::durumOptions())
                                ->required()
                                ->default(KoordinasyonTakipMadde::DURUM_SUPHE),
                            Toggle::make('saha_kontrolu')
                                ->label('Saha / kaynak kontrolü yapıldı')
                                ->inline(false),
                        ]),
                        Textarea::make('notlar')
                            ->label('Not')
                            ->rows(2),
                    ]),
            ])
            ->statePath('maddeData');
    }

    protected function getForms(): array
    {
        return [
            'form',
            'maddeForm',
        ];
    }

    public function reloadHaftaState(): void
    {
        $yil = (int) ($this->data['yil'] ?? now()->year);
        $ay = (int) ($this->data['ay'] ?? now()->month);
        $options = ReportPeriodWeeks::selectOptions($yil, $ay);
        $hafta = (int) ($this->data['hafta'] ?? 0);

        if ($options !== [] && ! array_key_exists($hafta, $options)) {
            $hafta = (int) array_key_first($options);
            $this->data['hafta'] = $hafta;
        }

        $kayit = $this->resolveHaftaKayit($yil, $ay, $hafta);
        $this->data['checklist'] = array_merge(
            KoordinasyonHafta::defaultChecklist(),
            (array) ($kayit->checklist ?? []),
        );
        $this->data['ozet_not'] = $kayit->ozet_not;
        $this->resetMaddeForm();
    }

    public function saveHaftaOzeti(): void
    {
        $yil = (int) ($this->data['yil'] ?? 0);
        $ay = (int) ($this->data['ay'] ?? 0);
        $hafta = (int) ($this->data['hafta'] ?? 0);

        if ($yil < 1 || $ay < 1 || $hafta < 1) {
            throw ValidationException::withMessages(['data.yil' => 'Hafta seçimi eksik.']);
        }

        $kayit = $this->resolveHaftaKayit($yil, $ay, $hafta);
        $kayit->checklist = array_merge(
            KoordinasyonHafta::defaultChecklist(),
            (array) ($this->data['checklist'] ?? []),
        );
        $kayit->ozet_not = $this->data['ozet_not'] ?? null;
        $kayit->updated_by = auth()->id();
        $kayit->save();

        Notification::make()
            ->title('Haftalık özet kaydedildi')
            ->success()
            ->send();
    }

    public function saveMadde(): void
    {
        $state = $this->maddeData;
        $baslik = trim((string) ($state['baslik'] ?? ''));
        if ($baslik === '') {
            throw ValidationException::withMessages(['maddeData.baslik' => 'Konu zorunlu.']);
        }

        $yil = (int) ($this->data['yil'] ?? now()->year);
        $ay = (int) ($this->data['ay'] ?? now()->month);
        $hafta = (int) ($this->data['hafta'] ?? 1);
        $durum = (string) ($state['durum'] ?? KoordinasyonTakipMadde::DURUM_SUPHE);

        $payload = [
            'yil' => $yil,
            'ay' => $ay,
            'hafta' => $hafta,
            'baslik' => $baslik,
            'analiz_user_id' => ($state['analiz_user_id'] ?? null) ?: null,
            'directorate_user_id' => ($state['directorate_user_id'] ?? null) ?: null,
            'durum' => $durum,
            'saha_kontrolu' => (bool) ($state['saha_kontrolu'] ?? false),
            'notlar' => $state['notlar'] ?? null,
            'kapanis_at' => $durum === KoordinasyonTakipMadde::DURUM_TEYIT ? now() : null,
        ];

        if ($this->editingMaddeId) {
            $madde = KoordinasyonTakipMadde::query()->findOrFail($this->editingMaddeId);
            $madde->fill($payload);
            if ($durum !== KoordinasyonTakipMadde::DURUM_TEYIT) {
                $madde->kapanis_at = null;
            } elseif ($madde->kapanis_at === null) {
                $madde->kapanis_at = now();
            }
            $madde->save();
            $msg = 'Madde güncellendi';
        } else {
            $payload['created_by'] = auth()->id();
            KoordinasyonTakipMadde::query()->create($payload);
            $msg = 'Madde eklendi';
        }

        $this->resetMaddeForm();

        Notification::make()
            ->title($msg)
            ->success()
            ->send();
    }

    public function editMadde(int $id): void
    {
        $madde = KoordinasyonTakipMadde::query()->findOrFail($id);
        $this->editingMaddeId = $madde->id;
        $this->maddeData = [
            'baslik' => $madde->baslik,
            'analiz_user_id' => $madde->analiz_user_id,
            'directorate_user_id' => $madde->directorate_user_id,
            'durum' => $madde->durum,
            'saha_kontrolu' => $madde->saha_kontrolu,
            'notlar' => $madde->notlar,
        ];
    }

    public function cancelEditMadde(): void
    {
        $this->resetMaddeForm();
    }

    public function deleteMadde(int $id): void
    {
        KoordinasyonTakipMadde::query()->whereKey($id)->delete();
        if ($this->editingMaddeId === $id) {
            $this->resetMaddeForm();
        }

        Notification::make()
            ->title('Madde silindi')
            ->success()
            ->send();
    }

    public function markMaddeDurum(int $id, string $durum): void
    {
        if (! array_key_exists($durum, KoordinasyonTakipMadde::durumOptions())) {
            return;
        }

        $madde = KoordinasyonTakipMadde::query()->findOrFail($id);
        $madde->durum = $durum;
        $madde->kapanis_at = $durum === KoordinasyonTakipMadde::DURUM_TEYIT ? now() : null;
        $madde->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPortfolio(): array
    {
        return AnalizEkibiKoordinasyonPlan::portfolio();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPlaybook(): array
    {
        return AnalizEkibiKoordinasyonPlan::playbook();
    }

    /**
     * @return Collection<int, KoordinasyonTakipMadde>
     */
    public function getMaddeler(): Collection
    {
        [$yil, $ay, $hafta] = $this->period();

        $order = [
            KoordinasyonTakipMadde::DURUM_DUZELTME => 0,
            KoordinasyonTakipMadde::DURUM_SUPHE => 1,
            KoordinasyonTakipMadde::DURUM_TEYIT => 2,
        ];

        return KoordinasyonTakipMadde::query()
            ->with(['analizUser:id,name', 'directorate:id,name'])
            ->where('yil', $yil)
            ->where('ay', $ay)
            ->where('hafta', $hafta)
            ->orderByDesc('id')
            ->get()
            ->sortBy(fn (KoordinasyonTakipMadde $m): int => $order[$m->durum] ?? 9)
            ->values();
    }

    /**
     * @return array{teyit: int, suphe: int, duzeltme: int, saha: int, toplam: int}
     */
    public function getKpi(): array
    {
        $maddeler = $this->getMaddeler();

        return [
            'teyit' => $maddeler->where('durum', KoordinasyonTakipMadde::DURUM_TEYIT)->count(),
            'suphe' => $maddeler->where('durum', KoordinasyonTakipMadde::DURUM_SUPHE)->count(),
            'duzeltme' => $maddeler->where('durum', KoordinasyonTakipMadde::DURUM_DUZELTME)->count(),
            'saha' => $maddeler->where('saha_kontrolu', true)->count(),
            'toplam' => $maddeler->count(),
        ];
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    protected function resetMaddeForm(): void
    {
        $this->editingMaddeId = null;
        $this->maddeData = [
            'baslik' => '',
            'analiz_user_id' => null,
            'directorate_user_id' => null,
            'durum' => KoordinasyonTakipMadde::DURUM_SUPHE,
            'saha_kontrolu' => false,
            'notlar' => '',
        ];
    }

    protected function resolveHaftaKayit(int $yil, int $ay, int $hafta): KoordinasyonHafta
    {
        return KoordinasyonHafta::query()->firstOrNew([
            'yil' => $yil,
            'ay' => $ay,
            'hafta' => $hafta,
        ]);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    protected function period(): array
    {
        return [
            (int) ($this->data['yil'] ?? now()->year),
            (int) ($this->data['ay'] ?? now()->month),
            (int) ($this->data['hafta'] ?? 1),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function analizOptions(): array
    {
        return User::query()
            ->where('role', User::ROLE_ANALIZ_EKIBI)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function mudurlukOptions(): array
    {
        return User::queryMudurlukReportingAccounts()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
