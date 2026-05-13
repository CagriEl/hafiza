<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoutineWorkAnalysisResource\Pages;
use App\Models\RoutineWorkItem;
use App\Models\User;
use App\Support\QuerySafety;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RoutineWorkAnalysisResource extends Resource
{
    protected static ?string $model = RoutineWorkItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Rutin İşler Analizi';

    protected static ?string $navigationGroup = 'Raporlama';

    protected static ?int $navigationSort = 8;

    protected static ?string $modelLabel = 'Rutin İş Analizi';

    protected static ?string $pluralModelLabel = 'Rutin İş Analizi';

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Rutin İş Kaydı')
                ->schema([
                    TextEntry::make('user.name')->label('Müdürlük'),
                    TextEntry::make('work_date')->label('Tarih')->date('d.m.Y'),
                    TextEntry::make('status')
                        ->label('Durum')
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            RoutineWorkItem::STATUS_IN_PROGRESS => 'Devam Ediyor',
                            RoutineWorkItem::STATUS_DONE => 'Bitti',
                            RoutineWorkItem::STATUS_PLANNED => 'Başlanacak',
                            default => 'Belirsiz',
                        }),
                    TextEntry::make('work_item')
                        ->label('İş')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('work_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Müdürlük')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('work_date')
                    ->label('Tarih')
                    ->date('d.m.Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('work_item')
                    ->label('İş')
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Durum')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        RoutineWorkItem::STATUS_IN_PROGRESS => 'Devam Ediyor',
                        RoutineWorkItem::STATUS_DONE => 'Bitti',
                        RoutineWorkItem::STATUS_PLANNED => 'Başlanacak',
                        default => 'Belirsiz',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        RoutineWorkItem::STATUS_IN_PROGRESS => 'warning',
                        RoutineWorkItem::STATUS_DONE => 'success',
                        RoutineWorkItem::STATUS_PLANNED => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Son Güncelleme')
                    ->dateTime('d.m.Y H:i')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Müdürlük')
                    ->options(function (): array {
                        $user = auth()->user();
                        if (! $user instanceof User) {
                            return [];
                        }

                        if ($user->isReportingSuperAdmin()) {
                            return User::queryMudurlukReportingAccounts()->pluck('name', 'id')->all();
                        }

                        if ($user->isControlTeam()) {
                            return $user->assignedDirectorates()
                                ->orderBy('name')
                                ->pluck('name', 'users.id')
                                ->all();
                        }

                        return [];
                    }),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Durum')
                    ->options([
                        RoutineWorkItem::STATUS_IN_PROGRESS => 'Devam Ediyor',
                        RoutineWorkItem::STATUS_DONE => 'Bitti',
                        RoutineWorkItem::STATUS_PLANNED => 'Başlanacak',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Görüntüle'),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
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

        if ($user->isControlTeam()) {
            $allowedDirectorateIds = $user->assignedDirectorates()
                ->pluck('users.id')
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->values()
                ->all();

            if ($allowedDirectorateIds === []) {
                return $query->whereRaw('0 = 1');
            }

            return $query->whereIn($query->qualifyColumn('user_id'), $allowedDirectorateIds);
        }

        return $query->whereRaw('0 = 1');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ($user->isReportingSuperAdmin() || $user->isControlTeam());
    }

    public static function canView(Model $record): bool
    {
        return static::getEloquentQuery()->whereKey($record->getKey())->exists();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoutineWorkAnalyses::route('/'),
            'view' => Pages\ViewRoutineWorkAnalysis::route('/{record}'),
        ];
    }
}
