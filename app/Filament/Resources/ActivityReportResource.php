<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityReportResource\Pages;
use App\Models\AylikFaaliyet;
use App\Models\User;
use App\Support\AylikFaaliyetRepeaterLock;
use App\Support\CoordinationAccess;
use App\Support\QuerySafety;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ActivityReportResource extends Resource
{
    protected static ?string $model = AylikFaaliyet::class;

    protected static ?string $navigationLabel = 'Raporlar';

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationGroup = 'Raporlama';

    protected static ?int $navigationSort = 1;

    /** @var array<int, list<int>> */
    private static array $controlTeamDirectorateIdsCache = [];

    public static function form(Form $form): Form
    {
        return AylikFaaliyetResource::form($form);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return AylikFaaliyetResource::infolist($infolist);
    }

    public static function table(Table $table): Table
    {
        $tabFromSession = fn (): string => (string) session('activity_report_active_tab', 'all');

        return AylikFaaliyetResource::table($table)
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Görüntüle')
                    ->url(fn (AylikFaaliyet $record): string => static::getUrl('view', [
                        'record' => $record,
                        'tab' => $tabFromSession(),
                    ]))
                    ->visible(fn (AylikFaaliyet $record) => static::canView($record) && ! static::canEdit($record)),
                Tables\Actions\EditAction::make()
                    ->label('Raporu düzenle')
                    ->url(fn (AylikFaaliyet $record): string => static::getUrl('edit', [
                        'record' => $record,
                        'tab' => $tabFromSession(),
                    ]))
                    ->visible(fn (AylikFaaliyet $record) => static::canEdit($record)),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $base = parent::getEloquentQuery()->with('user');
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

        if ($user->isControlTeam()) {
            $dirIds = static::controlTeamDirectorateIds($user);
            if ($dirIds === []) {
                return $base->whereRaw('0 = 1');
            }
            $incoming = CoordinationAccess::incomingAylikFaaliyetIdsForUserIds($dirIds);

            return $base->where(function (Builder $q) use ($dirIds, $incoming) {
                $q->whereIn('user_id', $dirIds);
                if ($incoming !== []) {
                    $q->orWhereIn('id', $incoming);
                }
            });
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
        $user = auth()->user();
        if ($user instanceof User && $user->isMaliHizmetlerAccount() && ! $user->isReportingSuperAdmin()) {
            return false;
        }

        return auth()->check();
    }

    public static function canView(Model $record): bool
    {
        if (! $record instanceof AylikFaaliyet) {
            return false;
        }

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
        if ($u->isControlTeam()) {
            $dirIds = static::controlTeamDirectorateIds($u);
            if ($dirIds === []) {
                return false;
            }
            if (in_array((int) $record->user_id, $dirIds, true)) {
                return true;
            }
            foreach ($dirIds as $dirId) {
                if (CoordinationAccess::isIncomingPartnerOnRecord($record, $dirId)) {
                    return true;
                }
            }

            return false;
        }
        if ($u->isMaliHizmetlerAccount() && ! $u->isReportingSuperAdmin()) {
            return false;
        }
        if ($u->isMudurlukReportingAccount()) {
            if (AylikFaaliyetRepeaterLock::actorOwnsAylikFaaliyetRecord($record, $u)) {
                return true;
            }

            return CoordinationAccess::isIncomingPartnerOnRecord($record, (int) $u->id);
        }

        return false;
    }

    public static function canEdit(Model $record): bool
    {
        if (! $record instanceof AylikFaaliyet) {
            return false;
        }

        if (static::isReportPeriodClosed($record)) {
            return false;
        }

        if (! static::canView($record)) {
            return false;
        }

        $u = auth()->user();
        if (! $u instanceof User) {
            return false;
        }

        if ($u->isReportingSuperAdmin()) {
            return true;
        }

        return $u->isMudurlukReportingAccount()
            && AylikFaaliyetRepeaterLock::actorOwnsAylikFaaliyetRecord($record, $u);
    }

    /**
     * Geçmiş ayların raporları düzenlenebilir; dönem sonu otomatik kapanış uygulanmaz.
     */
    public static function isReportPeriodClosed(AylikFaaliyet $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return AylikFaaliyetResource::canCreate();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityReports::route('/'),
            'create' => Pages\CreateActivityReport::route('/create'),
            'view' => Pages\ViewActivityReport::route('/{record}'),
            'edit' => Pages\EditActivityReport::route('/{record}/edit'),
        ];
    }

    /**
     * @return list<int>
     */
    private static function controlTeamDirectorateIds(User $user): array
    {
        $key = (int) $user->id;
        if (! array_key_exists($key, static::$controlTeamDirectorateIdsCache)) {
            static::$controlTeamDirectorateIdsCache[$key] = $user->assignedDirectorates()
                ->pluck('users.id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return static::$controlTeamDirectorateIdsCache[$key];
    }
}
