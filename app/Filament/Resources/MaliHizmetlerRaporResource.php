<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaliHizmetlerRaporResource\Pages;
use App\Models\MaliHizmetlerRapor;
use App\Models\User;
use App\Support\MaliHizmetlerAccess;
use App\Support\MaliHizmetlerPeriod;
use App\Support\MaliHizmetlerRaporForm;
use App\Support\QuerySafety;
use App\Support\ReportPeriodWeeks;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MaliHizmetlerRaporResource extends Resource
{
    protected static ?string $model = MaliHizmetlerRapor::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Mali Kayıtları';

    protected static ?string $navigationGroup = 'Raporlama';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Mali Rapor';

    protected static ?string $pluralModelLabel = 'Mali Hizmetler Raporları';

    public static function form(Form $form): Form
    {
        $isAdmin = auth()->user()?->isReportingSuperAdmin() === true;

        return $form
            ->schema([
                Forms\Components\Section::make('Rapor Dönemi')
                    ->schema(MaliHizmetlerRaporForm::adminPeriodSchema())
                    ->visible($isAdmin)
                    ->columns(1),
                ...MaliHizmetlerRaporForm::dataEntrySchema(),
            ]);
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();
        if ($user instanceof User && $user->isMaliHizmetlerAccount() && ! $user->isReportingSuperAdmin()) {
            return false;
        }

        return static::canViewAny();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('donem')
                    ->label('Dönem')
                    ->getStateUsing(fn (MaliHizmetlerRapor $record): string => $record->donemLabel())
                    ->sortable(['yil', 'ay', 'hafta']),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Müdürlük')
                    ->visible(fn (): bool => auth()->user()?->isReportingSuperAdmin() === true)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('haftalik_odeme_toplam')
                    ->label('Haftalık Ödeme')
                    ->money('TRY', locale: 'tr')
                    ->sortable(),
                Tables\Columns\TextColumn::make('talep_ozet')
                    ->label('Ödeme Talepleri')
                    ->getStateUsing(function (MaliHizmetlerRapor $record): string {
                        $count = is_array($record->odeme_talepleri) ? count($record->odeme_talepleri) : 0;
                        $bekleyen = $record->bekleyenTalepSayisi();
                        $toplam = $record->odemeTalepleriToplam();

                        return $count.' talep · '.number_format($toplam, 2, ',', '.').' ₺'
                            .($bekleyen > 0 ? ' · '.$bekleyen.' firma aranmadı' : '');
                    }),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Son Güncelleme')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('yil')
                    ->options([
                        now()->year - 1 => (string) (now()->year - 1),
                        now()->year => (string) now()->year,
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Düzenle'),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('user');
        if (! QuerySafety::shouldApplyFilters($query)) {
            return $query;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            return $query->whereRaw('0 = 1');
        }

        if ($user->isReportingSuperAdmin()) {
            return $query;
        }

        if ($user->isMaliHizmetlerAccount()) {
            return $query->where('user_id', $user->id);
        }

        $maliId = MaliHizmetlerAccess::maliHizmetlerUserId();
        if ($maliId === null) {
            return $query->whereRaw('0 = 1');
        }

        if ($user->isViceMayorAccount()) {
            $audience = $user->reportAudienceUserIds() ?? [];
            if (! in_array($maliId, $audience, true)) {
                return $query->whereRaw('0 = 1');
            }

            return $query->where('user_id', $maliId);
        }

        if ($user->isControlTeam()) {
            $dirIds = $user->assignedDirectorates()
                ->pluck('users.id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            if (! in_array($maliId, $dirIds, true)) {
                return $query->whereRaw('0 = 1');
            }

            return $query->where('user_id', $maliId);
        }

        return $query->whereRaw('0 = 1');
    }

    public static function canViewAny(): bool
    {
        return MaliHizmetlerAccess::userCanManageMaliRaporlar(auth()->user());
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        if ($user->isReportingSuperAdmin()) {
            return true;
        }

        return $user->isMaliHizmetlerAccount();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canView($record);
    }

    public static function canView(Model $record): bool
    {
        if (! $record instanceof MaliHizmetlerRapor) {
            return false;
        }

        return static::getEloquentQuery()->whereKey($record->getKey())->exists();
    }

    public static function canDelete(Model $record): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        return $user->isReportingSuperAdmin() || $user->isMaliHizmetlerAccount();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaliHizmetlerRapors::route('/'),
            'create' => Pages\CreateMaliHizmetlerRapor::route('/create'),
            'edit' => Pages\EditMaliHizmetlerRapor::route('/{record}/edit'),
        ];
    }
}
