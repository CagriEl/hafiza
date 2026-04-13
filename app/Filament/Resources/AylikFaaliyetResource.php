<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AylikFaaliyetResource\Pages;
use App\Models\ActivityCatalog;
use App\Models\AylikFaaliyet;
use App\Models\User;
use App\Models\ViceMayor;
use App\Support\ActivityCatalogFormatter;
use App\Support\AylikFaaliyetEscalation;
use App\Support\AylikFaaliyetRepeaterLock;
use App\Support\CoordinationAccess;
use App\Support\QuerySafety;
use App\Support\ReportingModelReader;
use App\Support\TurkishString;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class AylikFaaliyetResource extends Resource
{
    protected static ?string $model = AylikFaaliyet::class;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'Aylık Rapor';

    protected static ?string $navigationGroup = 'Raporlama';

    protected static ?int $navigationSort = 2;

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
                    ->description('Kayıtlı satırlar salt okunur. Güncelleme için yeni satır ekleyip «Gerekli Revize» işaretleyin ve revize sebebini yazın.')
                    ->schema([
                        Repeater::make('faaliyetler')
                            ->label('İş Listesi')
                            ->schema([
                                Forms\Components\Hidden::make('_orig_index'),
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
                                        ->afterStateUpdated(function (Set $set, $state) {
                                            $catalog = ActivityCatalog::find($state);
                                            if ($catalog) {
                                                $set('olcu_birimi', $catalog->olcu_birimi);
                                                $set('faaliyet_kodu', $catalog->faaliyet_kodu);
                                            }
                                        })
                                        ->disabled(function ($state, Get $get, $livewire): bool {
                                            if (AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire)) {
                                                return true;
                                            }

                                            return filled($state);
                                        })
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

                                        return AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire);
                                    })
                                    ->afterStateUpdated(function (Set $set, $state): void {
                                        if ($state !== 'Koordinasyon') {
                                            $set('isbirligi_hangi_ihtiyac', null);
                                            $set('isbirligi_hedef_tarih', null);
                                            $set('isbirligi_bitis_suresi', null);
                                            $set('isbirligi_hedef_mudurluk_user_ids', []);
                                        }
                                    }),

                                Section::make('Müdürlüklerle İşbirliği')
                                    ->description('Koordinasyon faaliyetlerinde diğer müdürlüklerle planlanan ihtiyaç ve süre bilgileri. Yalnızca müdürlük hesapları düzenleyebilir.')
                                    ->schema([
                                        Forms\Components\Select::make('isbirligi_hedef_mudurluk_user_ids')
                                            ->label('İşbirliği Yapılacak Müdürlükler')
                                            ->multiple()
                                            ->searchable()
                                            ->preload()
                                            ->disabled(function (Get $get, $livewire): bool {
                                                if (AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire)) {
                                                    return true;
                                                }
                                                if (! is_object($livewire) || ! method_exists($livewire, 'getRecord')) {
                                                    return false;
                                                }
                                                $r = $livewire->getRecord();

                                                return $r instanceof AylikFaaliyet
                                                    && auth()->user() instanceof User
                                                    && CoordinationAccess::isIncomingPartnerOnRecord($r, (int) auth()->id());
                                            })
                                            ->options(function () {
                                                $viceIds = ViceMayor::query()->pluck('user_id')->all();
                                                $uid = (int) (auth()->id() ?? 0);

                                                return User::query()
                                                    ->where('id', '!=', 1)
                                                    ->whereNotIn('id', $viceIds)
                                                    ->when($uid > 0, fn (Builder $q) => $q->where('id', '!=', $uid))
                                                    ->orderBy('name')
                                                    ->pluck('name', 'id')
                                                    ->all();
                                            })
                                            ->required(fn (Get $get) => auth()->user()?->isMudurlukReportingAccount() && $get('faaliyet_turu') === 'Koordinasyon')
                                            ->rules([
                                                fn (Get $get) => Rule::when(
                                                    auth()->user()?->isMudurlukReportingAccount() && $get('faaliyet_turu') === 'Koordinasyon',
                                                    ['array', 'min:1']
                                                ),
                                            ]),

                                        Grid::make(3)->schema([
                                            Forms\Components\Textarea::make('isbirligi_hangi_ihtiyac')
                                                ->label('Hangi İhtiyaç')
                                                ->rows(3)
                                                ->disabled(function (Get $get, $livewire): bool {
                                                    if (AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire)) {
                                                        return true;
                                                    }
                                                    if (! is_object($livewire) || ! method_exists($livewire, 'getRecord')) {
                                                        return false;
                                                    }
                                                    $r = $livewire->getRecord();

                                                    return $r instanceof AylikFaaliyet
                                                        && auth()->user() instanceof User
                                                        && CoordinationAccess::isIncomingPartnerOnRecord($r, (int) auth()->id());
                                                })
                                                ->required(fn (Get $get) => auth()->user()?->isMudurlukReportingAccount() && $get('faaliyet_turu') === 'Koordinasyon')
                                                ->rules([
                                                    fn (Get $get) => Rule::when(
                                                        auth()->user()?->isMudurlukReportingAccount() && $get('faaliyet_turu') === 'Koordinasyon',
                                                        ['string', 'max:5000']
                                                    ),
                                                ]),

                                            Forms\Components\DatePicker::make('isbirligi_hedef_tarih')
                                                ->label('Hedef Tarih')
                                                ->native(false)
                                                ->displayFormat('d.m.Y')
                                                ->disabled(function (Get $get, $livewire): bool {
                                                    if (AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire)) {
                                                        return true;
                                                    }
                                                    if (! is_object($livewire) || ! method_exists($livewire, 'getRecord')) {
                                                        return false;
                                                    }
                                                    $r = $livewire->getRecord();

                                                    return $r instanceof AylikFaaliyet
                                                        && auth()->user() instanceof User
                                                        && CoordinationAccess::isIncomingPartnerOnRecord($r, (int) auth()->id());
                                                })
                                                ->minDate(Carbon::today()->startOfDay())
                                                ->required(fn (Get $get) => auth()->user()?->isMudurlukReportingAccount() && $get('faaliyet_turu') === 'Koordinasyon')
                                                ->rules([
                                                    fn (Get $get) => Rule::when(
                                                        auth()->user()?->isMudurlukReportingAccount() && $get('faaliyet_turu') === 'Koordinasyon',
                                                        ['date', 'after_or_equal:today']
                                                    ),
                                                ]),

                                            Forms\Components\TextInput::make('isbirligi_bitis_suresi')
                                                ->label('Bitiş Süresi')
                                                ->placeholder('Örn: 10 iş günü, 2 hafta')
                                                ->disabled(function (Get $get, $livewire): bool {
                                                    if (AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire)) {
                                                        return true;
                                                    }
                                                    if (! is_object($livewire) || ! method_exists($livewire, 'getRecord')) {
                                                        return false;
                                                    }
                                                    $r = $livewire->getRecord();

                                                    return $r instanceof AylikFaaliyet
                                                        && auth()->user() instanceof User
                                                        && CoordinationAccess::isIncomingPartnerOnRecord($r, (int) auth()->id());
                                                })
                                                ->maxLength(255)
                                                ->required(fn (Get $get) => auth()->user()?->isMudurlukReportingAccount() && $get('faaliyet_turu') === 'Koordinasyon')
                                                ->rules([
                                                    fn (Get $get) => Rule::when(
                                                        auth()->user()?->isMudurlukReportingAccount() && $get('faaliyet_turu') === 'Koordinasyon',
                                                        ['string', 'max:255']
                                                    ),
                                                ]),
                                        ]),
                                    ])
                                    ->visible(fn (Get $get) => auth()->user()?->isMudurlukReportingAccount() && $get('faaliyet_turu') === 'Koordinasyon'),

                                Grid::make(4)->schema([
                                    Forms\Components\Select::make('hafta')
                                        ->label('Rapor Haftası')
                                        ->options([1 => '1. Hafta', 2 => '2. Hafta', 3 => '3. Hafta', 4 => '4. Hafta'])
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
                                        ->required(),

                                    Forms\Components\TextInput::make('hedef')
                                        ->label('Haftalık Hedef')
                                        ->numeric()
                                        ->placeholder('Örn: 450')
                                        ->live()
                                        ->disabled(fn (Get $get, $livewire): bool => AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire)),

                                    Forms\Components\TextInput::make('gerceklesen')
                                        ->label('Gerçekleşen')
                                        ->numeric()
                                        ->placeholder('Örn: 395')
                                        ->live()
                                        ->disabled(fn (Get $get, $livewire): bool => AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire)),

                                    Forms\Components\TextInput::make('bekleyen_is')
                                        ->label('Açık/Bekleyen İş')
                                        ->numeric()
                                        ->placeholder('Örn: 18')
                                        ->disabled(fn (Get $get, $livewire): bool => AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire)),
                                ]),

                                Grid::make(2)->schema([
                                    Forms\Components\Textarea::make('sapma_nedeni')
                                        ->label('Sapma Nedeni')
                                        ->placeholder('Hedef gerçekleşmediyse nedenini yazınız...')
                                        ->rows(2)
                                        ->visible(fn (Get $get) => filled($get('hedef')) && $get('gerceklesen') < $get('hedef'))
                                        ->disabled(fn (Get $get, $livewire): bool => AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire)),

                                    Forms\Components\Textarea::make('risk_engel')
                                        ->label('Risk / Engel')
                                        ->placeholder('İşin önündeki engelleri belirtiniz...')
                                        ->rows(2)
                                        ->disabled(fn (Get $get, $livewire): bool => AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire)),
                                ]),

                                Grid::make(2)->schema([
                                    Forms\Components\Toggle::make('gerekli_revize')
                                        ->label('Gerekli Revize')
                                        ->inline(false)
                                        ->default(false)
                                        ->live()
                                        ->disabled(fn (Get $get, $livewire): bool => AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire))
                                        ->helperText('Yeni satırlarda işaretleyin; kayıtlı satırlar değiştirilemez.'),
                                    Forms\Components\Textarea::make('revize_sebebi')
                                        ->label('Revize Sebebi')
                                        ->rows(2)
                                        ->placeholder('Revize neden gerekli? Kisa aciklama yaziniz...')
                                        ->disabled(fn (Get $get, $livewire): bool => AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire))
                                        ->required(fn (Get $get): bool => (bool) $get('gerekli_revize'))
                                        ->visible(fn (Get $get): bool => (bool) $get('gerekli_revize'))
                                        ->extraAttributes(fn (Get $get): array => (bool) $get('gerekli_revize')
                                            ? ['class' => 'bg-amber-50 border-l-4 border-amber-500']
                                            : []),
                                ])
                                    ->extraAttributes(fn (Get $get): array => (bool) $get('gerekli_revize')
                                        ? ['class' => 'bg-amber-50 p-2 rounded-md']
                                        : []),

                                Grid::make(1)->schema([
                                    Forms\Components\TextInput::make('karar_ihtiyaci')
                                        ->label('📌 Üst Yönetim Karar İhtiyacı')
                                        ->placeholder('Başkanlık makamından beklenen karar veya destek nedir?')
                                        ->disabled(fn (Get $get, $livewire): bool => AylikFaaliyetRepeaterLock::mudurlukOwnsRecordAndRowIsLocked($get, $livewire)),

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('yil')->label('Yıl')->badge(),
                Tables\Columns\TextColumn::make('ay')->label('Ay'),
                Tables\Columns\TextColumn::make('user.name')->label('Müdürlük')->searchable(),

                Tables\Columns\TextColumn::make('performans_ozeti')
                    ->label('Haftalık Verimlilik')
                    ->getStateUsing(function ($record) {
                        $isler = is_string($record->faaliyetler) ? json_decode($record->faaliyetler, true) : $record->faaliyetler;
                        if (! is_array($isler)) {
                            return '-';
                        }

                        $toplamHedef = collect($isler)->sum('hedef');
                        $toplamGerceklesen = collect($isler)->sum('gerceklesen');

                        if ($toplamHedef == 0) {
                            return 'Sayısal Veri Yok';
                        }
                        $oran = round(($toplamGerceklesen / $toplamHedef) * 100);

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
                    ->label('Detay / Denetim')
                    ->visible(fn (AylikFaaliyet $record) => static::canEdit($record)),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfolistSection::make('Rapor')
                    ->schema([
                        TextEntry::make('user.name')->label('Müdürlük'),
                        TextEntry::make('yil')->label('Yıl'),
                        TextEntry::make('ay')->label('Ay'),
                    ])
                    ->columns(3),
                InfolistSection::make('Faaliyet satırları')
                    ->schema([
                        RepeatableEntry::make('faaliyetler')
                            ->schema([
                                TextEntry::make('faaliyet_kodu')->label('Kod'),
                                TextEntry::make('faaliyet_turu')->label('Tür'),
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
                                TextEntry::make('hafta')->label('Hafta'),
                                TextEntry::make('hedef')->label('Hedef'),
                                TextEntry::make('gerceklesen')->label('Gerçekleşen'),
                                TextEntry::make('sapma_nedeni')->label('Sapma')->placeholder('—'),
                                TextEntry::make('gerekli_revize')
                                    ->label('Revize')
                                    ->formatStateUsing(fn ($state): string => (bool) $state ? 'Evet' : 'Hayir'),
                                TextEntry::make('revize_sebebi')->label('Revize sebebi')->placeholder('—'),
                                TextEntry::make('karar_ihtiyaci')->label('Karar ihtiyacı')->placeholder('—'),
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

        return (int) $record->user_id === (int) $u->id;
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
        if ($u->isReportingSuperAdmin()) {
            return false;
        }
        if ($u->isViceMayorAccount()) {
            return false;
        }

        return true;
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
