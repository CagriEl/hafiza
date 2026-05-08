<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExtraordinarySituationResource\Pages;
use App\Models\ExtraordinarySituation;
use App\Models\User;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use App\Support\QuerySafety;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ExtraordinarySituationResource extends Resource
{
    protected static ?string $model = ExtraordinarySituation::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Olağanüstü Durumlar';

    protected static ?string $navigationGroup = 'Raporlama';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Olağanüstü Durum';

    protected static ?string $pluralModelLabel = 'Olağanüstü Durumlar';

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfolistSection::make('Olağanüstü Durum Detayı')
                    ->schema([
                        TextEntry::make('target.name')
                            ->label('Müdürlük')
                            ->placeholder('—'),
                        TextEntry::make('reporter.name')
                            ->label('Bildirimi Giren')
                            ->placeholder('—'),
                        TextEntry::make('period')
                            ->label('Dönem')
                            ->getStateUsing(fn (ExtraordinarySituation $record): string => (string) $record->yil.' / '.str_pad((string) $record->ay, 2, '0', STR_PAD_LEFT))
                            ->badge(),
                        TextEntry::make('created_at')
                            ->label('Bildirildiği Zaman')
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('message')
                            ->label('Açıklama')
                            ->columnSpanFull()
                            ->placeholder('—'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('target.name')
                    ->label('Müdürlük')
                    ->searchable(),
                Tables\Columns\TextColumn::make('reporter.name')
                    ->label('Bildirimi Giren')
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('period')
                    ->label('Dönem')
                    ->getStateUsing(fn (ExtraordinarySituation $record): string => (string) $record->yil.' / '.str_pad((string) $record->ay, 2, '0', STR_PAD_LEFT))
                    ->badge(),
                Tables\Columns\TextColumn::make('message')
                    ->label('Açıklama')
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Bildirildiği Zaman')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Görüntüle'),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['target', 'reporter']);
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

        if ($user->isViceMayorAccount()) {
            $audience = $user->reportAudienceUserIds() ?? [];
            if ($audience === []) {
                return $query->whereRaw('0 = 1');
            }

            return $query->whereIn('target_user_id', $audience);
        }

        return $query->whereRaw('0 = 1');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ($user->isReportingSuperAdmin() || $user->isViceMayorAccount());
    }

    public static function canView(Model $record): bool
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $record instanceof ExtraordinarySituation) {
            return false;
        }

        if ($user->isReportingSuperAdmin()) {
            return true;
        }

        if (! $user->isViceMayorAccount()) {
            return false;
        }

        $audience = $user->reportAudienceUserIds() ?? [];

        return in_array((int) $record->target_user_id, $audience, true);
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
            'index' => Pages\ListExtraordinarySituations::route('/'),
            'view' => Pages\ViewExtraordinarySituation::route('/{record}'),
        ];
    }
}
