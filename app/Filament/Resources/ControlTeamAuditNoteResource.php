<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ControlTeamAuditNoteResource\Pages;
use App\Models\ControlTeamAuditNote;
use App\Models\User;
use App\Models\ViceMayor;
use App\Support\ActivityCatalogFormatter;
use App\Support\QuerySafety;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ControlTeamAuditNoteResource extends Resource
{
    protected static ?string $model = ControlTeamAuditNote::class;

    protected static ?string $navigationLabel = 'Denetim Notları';

    protected static ?string $pluralModelLabel = 'Denetim Notları';

    protected static ?string $modelLabel = 'Denetim Notu';

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('directorate_user_id')
                    ->label('Denetlenecek Birim')
                    ->options(function (): array {
                        $u = auth()->user();
                        if (! $u instanceof User) {
                            return [];
                        }

                        if ($u->isReportingSuperAdmin()) {
                            return User::query()
                                ->where('id', '!=', 1)
                                ->whereNotIn('id', ViceMayor::query()->pluck('user_id'))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all();
                        }

                        if ($u->isControlTeam()) {
                            return $u->assignedDirectorates()
                                ->orderBy('name')
                                ->pluck('name', 'users.id')
                                ->all();
                        }

                        return [];
                    })
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live(),
                Forms\Components\Select::make('activity_catalog_id')
                    ->label('İlgili Faaliyet')
                    ->options(function (Get $get): array {
                        $directorateId = (int) ($get('directorate_user_id') ?? 0);
                        if ($directorateId <= 0) {
                            return [];
                        }

                        $directorate = User::query()->find($directorateId);
                        if (! $directorate) {
                            return [];
                        }

                        return ActivityCatalogFormatter::selectOptionsForMudurluk($directorate->name);
                    })
                    ->searchable()
                    ->preload()
                    ->getOptionLabelUsing(fn ($value) => ActivityCatalogFormatter::labelForCatalogId((int) $value))
                    ->required(),
                Forms\Components\Textarea::make('note')
                    ->label('Denetim Notu ve Bulgular')
                    ->rows(8)
                    ->required(),
                Forms\Components\DatePicker::make('audit_date')
                    ->label('Denetim Tarihi')
                    ->default(now()->toDateString())
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('directorate.name')
                    ->label('Denetlenen Birim')
                    ->searchable(),
                Tables\Columns\TextColumn::make('activity_catalog_id')
                    ->label('İlgili Faaliyet')
                    ->wrap()
                    ->formatStateUsing(fn ($state) => ActivityCatalogFormatter::labelForCatalogId((int) $state) ?? '—'),
                Tables\Columns\TextColumn::make('audit_date')
                    ->label('Tarih')
                    ->date('d.m.Y'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Denetim Ekibi')
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        if (! QuerySafety::shouldApplyFilters($query)) {
            return $query;
        }

        $u = auth()->user();
        if (! $u instanceof User) {
            return $query->whereRaw('0=1');
        }

        if ($u->isReportingSuperAdmin()) {
            return $query;
        }

        if ($u->isControlTeam()) {
            $allowedDirectorateIds = $u->assignedDirectorates()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
            if ($allowedDirectorateIds === []) {
                return $query->whereRaw('0=1');
            }

            return $query
                ->whereIn('directorate_user_id', $allowedDirectorateIds)
                ->where('user_id', $u->id);
        }

        return $query->whereRaw('0=1');
    }

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return $u instanceof User && ($u->isReportingSuperAdmin() || $u->isControlTeam());
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListControlTeamAuditNotes::route('/'),
            'create' => Pages\CreateControlTeamAuditNote::route('/create'),
            'edit' => Pages\EditControlTeamAuditNote::route('/{record}/edit'),
        ];
    }
}
