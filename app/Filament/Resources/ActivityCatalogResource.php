<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityCatalogResource\Pages;
use App\Models\ActivityCatalog;
use App\Services\ActivityCatalogSyncService;
use App\Support\ActivityCatalogReportSync;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

class ActivityCatalogResource extends Resource
{
    protected static ?string $model = ActivityCatalog::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Faaliyet Kataloğu';

    protected static ?string $navigationGroup = 'Yönetim';

    protected static ?string $pluralLabel = 'Faaliyet Katalog Verileri';

    public static function canViewAny(): bool
    {
        return auth()->id() === 1;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Faaliyet Detayları')
                    ->schema([
                        Forms\Components\TextInput::make('mudurluk')->label('Müdürlük')->required(),
                        Forms\Components\TextInput::make('faaliyet_kodu')->label('Kod')->required(),
                        Forms\Components\TextInput::make('faaliyet_ailesi')->label('Faaliyet Ailesi')->required(),
                        Forms\Components\TextInput::make('kategori')->label('Kategori'),
                        Forms\Components\Textarea::make('kapsam')->label('Kapsam')->columnSpanFull(),
                        Forms\Components\TextInput::make('olcu_birimi')->label('Ölçü Birimi'),
                        Forms\Components\TextInput::make('kpi_sla')->label('KPI / SLA Hedefi'),
                        Forms\Components\TextInput::make('raporlama_sikligi')->label('Raporlama Sıklığı'),
                        Forms\Components\TextInput::make('baskanlik_bilgilendirme_seviyesi')->label('Başkanlık Bilgilendirme Seviyesi'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('faaliyet_kodu')
                    ->label('Kod')
                    ->searchable()
                    ->sortable()
                    ->badge(),
                Tables\Columns\TextColumn::make('mudurluk')
                    ->label('Müdürlük')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('faaliyet_ailesi')
                    ->label('Faaliyet Ailesi')
                    ->searchable()
                    ->description(fn (ActivityCatalog $record): string => 'Ölçü Birimi: '.((string) ($record->olcu_birimi ?: '-')))
                    ->wrap(),
                Tables\Columns\TextColumn::make('olcu_birimi')
                    ->label('Birim')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('kpi_sla')
                    ->label('KPI / SLA')
                    ->limit(30),
                Tables\Columns\TextColumn::make('raporlama_sikligi')
                    ->label('Raporlama Sıklığı')
                    ->badge()
                    ->color('warning'),
                Tables\Columns\TextColumn::make('baskanlik_bilgilendirme_seviyesi')
                    ->label('Başkanlık Bilgilendirme Seviyesi')
                    ->badge()
                    ->color('danger'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('mudurluk')
                    ->label('Müdürlüğe Göre Filtrele')
                    ->options(ActivityCatalog::pluck('mudurluk', 'mudurluk')->toArray()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                static::syncToReportsAction(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (ActivityCatalog $record): void {
                        $record->setAttribute('_snapshot_code', (string) $record->faaliyet_kodu);
                    })
                    ->after(function (ActivityCatalog $record): void {
                        $code = trim((string) ($record->getAttribute('_snapshot_code') ?: $record->faaliyet_kodu));
                        if ($code !== '') {
                            app(ActivityCatalogSyncService::class)->removeAdminCatalogChange($code);
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('syncSelectedToReports')
                        ->label('Seçilenleri raporlara yansıt')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->modalHeading('Seçilen katalog kayıtlarını raporlara kalıcı yansıt')
                        ->modalDescription('Önizlemeyi kontrol edin. Onaylarsanız seçilen faaliyetlerin TÜM ilgili raporları kalıcı güncellenir; her raporu tek tek açmanız gerekmez.')
                        ->modalSubmitActionLabel('Kalıcı uygula')
                        ->form(function (Collection $records): array {
                            $ids = $records->map(fn (ActivityCatalog $r) => (int) $r->id)->all();
                            $preview = ActivityCatalogReportSync::previewForCatalogIds($ids);

                            return [
                                Forms\Components\Placeholder::make('preview')
                                    ->label('Önizleme (uygulamadan önce)')
                                    ->content(new HtmlString(ActivityCatalogReportSync::previewToHtml($preview))),
                                Forms\Components\Checkbox::make('confirm')
                                    ->label('Tüm ilgili raporlara kalıcı uygulamayı onaylıyorum')
                                    ->accepted()
                                    ->required(),
                            ];
                        })
                        ->action(function (Collection $records): void {
                            $ids = $records->map(fn (ActivityCatalog $r) => (int) $r->id)->all();
                            $stats = ActivityCatalogReportSync::applyForCatalogIds($ids);
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
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function (Collection $records): void {
                            foreach ($records as $record) {
                                if ($record instanceof ActivityCatalog) {
                                    $record->setAttribute('_snapshot_code', (string) $record->faaliyet_kodu);
                                }
                            }
                        })
                        ->after(function (Collection $records): void {
                            $sync = app(ActivityCatalogSyncService::class);
                            foreach ($records as $record) {
                                if (! $record instanceof ActivityCatalog) {
                                    continue;
                                }
                                $code = trim((string) ($record->getAttribute('_snapshot_code') ?: $record->faaliyet_kodu));
                                if ($code !== '') {
                                    $sync->removeAdminCatalogChange($code);
                                }
                            }
                        }),
                ]),
            ]);
    }

    public static function syncToReportsAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('syncToReports')
            ->label('Raporlara yansıt')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->modalHeading(fn (ActivityCatalog $record): string => 'Raporlara kalıcı yansıt: '.$record->faaliyet_kodu)
            ->modalDescription('Önizlemeyi kontrol edin, ardından onaylayın. Bu faaliyet koduna ait TÜM raporlar kalıcı güncellenir; her raporu tek tek açmanız gerekmez.')
            ->modalSubmitActionLabel('Kalıcı uygula')
            ->form(function (ActivityCatalog $record): array {
                $preview = ActivityCatalogReportSync::previewForCatalog($record);

                return [
                    Forms\Components\Placeholder::make('preview')
                        ->label('Önizleme (uygulamadan önce)')
                        ->content(new HtmlString(ActivityCatalogReportSync::previewToHtml($preview))),
                    Forms\Components\Checkbox::make('confirm')
                        ->label('Tüm ilgili raporlara kalıcı uygulamayı onaylıyorum')
                        ->accepted()
                        ->required()
                        ->visible(fn (): bool => (int) ($preview['summary']['reports'] ?? 0) > 0),
                ];
            })
            ->action(function (ActivityCatalog $record): void {
                $stats = ActivityCatalogReportSync::applyForCatalog($record);
                $notification = Notification::make()
                    ->title($stats['reports'] > 0 ? 'Raporlara kalıcı uygulandı' : 'Uygulanacak değişiklik yok')
                    ->body($stats['reports'] > 0
                        ? sprintf(
                            '%d raporda %d satır kalıcı güncellendi (%d alan). Bundan sonra her raporu tek tek değiştirmeniz gerekmez.',
                            $stats['reports'],
                            $stats['rows'],
                            $stats['change_fields']
                        )
                        : 'Bu kayıt için raporlarda fark bulunamadı.')
                    ->persistent();

                if ($stats['reports'] > 0) {
                    $notification->success();
                } else {
                    $notification->info();
                }

                $notification->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityCatalogs::route('/'),
            'create' => Pages\CreateActivityCatalog::route('/create'),
            'edit' => Pages\EditActivityCatalog::route('/{record}/edit'),
        ];
    }
}
