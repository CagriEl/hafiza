<?php

namespace App\Filament\Pages;

use App\Models\ActivityCatalog;
use App\Models\User;
use App\Support\ActivityCatalogReportSync;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\HtmlString;

class ActivityCatalogRaporSync extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Katalog → Rapor Sync';

    protected static ?string $title = 'Katalog Değişikliklerini Raporlara Yansıt';

    protected static ?string $navigationGroup = 'Yönetim';

    protected static ?int $navigationSort = 21;

    protected static ?string $slug = 'katalog-rapor-sync';

    protected static string $view = 'filament.pages.activity-catalog-rapor-sync';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isReportingSuperAdmin();
    }

    public function mount(): void
    {
        $this->form->fill([
            'mudurluk' => null,
            'catalog_id' => null,
        ]);
        $this->preview = null;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Kapsam')
                    ->description('Önizleyin, sonra bir kez uygulayın. Seçilen faaliyet(ler)e ait TÜM raporlar kalıcı güncellenir; her raporu tek tek açıp değiştirmeniz gerekmez. Sayısal veriler korunur.')
                    ->schema([
                        Select::make('mudurluk')
                            ->label('Müdürlük')
                            ->options(fn (): array => ActivityCatalog::query()
                                ->orderBy('mudurluk')
                                ->pluck('mudurluk', 'mudurluk')
                                ->all())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (): void {
                                $this->data['catalog_id'] = null;
                                $this->preview = null;
                            }),
                        Select::make('catalog_id')
                            ->label('Faaliyet (opsiyonel)')
                            ->helperText('Boş bırakılırsa seçilen müdürlüğün tüm faaliyetleri taranır.')
                            ->options(function (): array {
                                $mudurluk = trim((string) ($this->data['mudurluk'] ?? ''));
                                $q = ActivityCatalog::query()->orderBy('faaliyet_kodu');
                                if ($mudurluk !== '') {
                                    $q->where('mudurluk', $mudurluk);
                                }

                                return $q->get(['id', 'faaliyet_kodu', 'faaliyet_ailesi'])
                                    ->mapWithKeys(fn (ActivityCatalog $c): array => [
                                        $c->id => trim((string) $c->faaliyet_kodu).' — '.trim((string) $c->faaliyet_ailesi),
                                    ])
                                    ->all();
                            })
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn () => $this->preview = null),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function runPreview(): void
    {
        $this->preview = $this->buildPreview();

        $count = (int) ($this->preview['summary']['reports'] ?? 0);
        Notification::make()
            ->title($count > 0 ? 'Önizleme hazır' : 'Değişiklik bulunamadı')
            ->body($count > 0
                ? $count.' raporda güncelleme adayı var. Aşağıdaki listeyi kontrol edin.'
                : 'Seçime göre raporlarda katalogdan farklı satır yok.')
            ->color($count > 0 ? 'success' : 'gray')
            ->send();
    }

    public function runApply(): void
    {
        if ($this->preview === null) {
            Notification::make()
                ->title('Önce önizleyin')
                ->body('Uygulamadan önce Önizle ile değişiklikleri kontrol edin.')
                ->warning()
                ->send();

            return;
        }

        if ((int) ($this->preview['summary']['reports'] ?? 0) === 0) {
            Notification::make()
                ->title('Uygulanacak değişiklik yok')
                ->gray()
                ->send();

            return;
        }

        $stats = $this->buildApply();
        $this->preview = $this->buildPreview();

        Notification::make()
            ->title('Raporlara kalıcı uygulandı')
            ->body(sprintf(
                '%d raporda %d satır kalıcı güncellendi (%d alan). Bundan sonra her raporu tek tek değiştirmeniz gerekmez.',
                $stats['reports'],
                $stats['rows'],
                $stats['change_fields']
            ))
            ->success()
            ->persistent()
            ->send();
    }

    public function getPreviewHtml(): HtmlString
    {
        if ($this->preview === null) {
            return new HtmlString(
                '<div style="color:#6b7280;font-size:13px;">Henüz önizleme yok. Kapsamı seçip <b>Önizle</b>ye basın.</div>'
            );
        }

        return new HtmlString(ActivityCatalogReportSync::previewToHtml($this->preview));
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPreview(): array
    {
        $catalogId = (int) ($this->data['catalog_id'] ?? 0);
        $mudurluk = trim((string) ($this->data['mudurluk'] ?? ''));

        if ($catalogId > 0) {
            $catalog = ActivityCatalog::query()->find($catalogId);
            if (! $catalog instanceof ActivityCatalog) {
                return [
                    'summary' => ['reports' => 0, 'rows' => 0, 'change_fields' => 0],
                    'items' => [],
                    'truncated' => false,
                ];
            }

            return ActivityCatalogReportSync::previewForCatalog($catalog);
        }

        if ($mudurluk !== '') {
            return ActivityCatalogReportSync::previewForMudurluk($mudurluk);
        }

        Notification::make()
            ->title('Kapsam seçin')
            ->body('Müdürlük veya faaliyet seçmeden önizleme yapılamaz.')
            ->warning()
            ->send();

        return [
            'summary' => ['reports' => 0, 'rows' => 0, 'change_fields' => 0],
            'items' => [],
            'truncated' => false,
        ];
    }

    /**
     * @return array{reports: int, rows: int, change_fields: int}
     */
    private function buildApply(): array
    {
        $catalogId = (int) ($this->data['catalog_id'] ?? 0);
        $mudurluk = trim((string) ($this->data['mudurluk'] ?? ''));

        if ($catalogId > 0) {
            $catalog = ActivityCatalog::query()->findOrFail($catalogId);

            return ActivityCatalogReportSync::applyForCatalog($catalog);
        }

        return ActivityCatalogReportSync::applyForMudurluk($mudurluk);
    }
}
