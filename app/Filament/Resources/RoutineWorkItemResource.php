<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoutineWorkItemResource\Pages;
use App\Models\RoutineWorkItem;
use App\Models\RoutineWorkWindow;
use App\Models\User;
use App\Support\QuerySafety;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RoutineWorkItemResource extends Resource
{
    protected static ?string $model = RoutineWorkItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Rutin İşler';

    protected static ?string $navigationGroup = 'Raporlama';

    protected static ?string $modelLabel = 'Rutin İş';

    protected static ?string $pluralModelLabel = 'Rutin İşler';

    private const CLOSED_MESSAGE = 'Rutin İşler 1 aylık iş ölçümü için kullanılacaktır. Aktif olduğunda duyuru yayınlarak bilgi verilecektir. Şu anda veri girişine kapalıdır.';

    public static function form(Form $form): Form
    {
        $window = RoutineWorkWindow::current();

        return $form
            ->schema([
                Forms\Components\Placeholder::make('routine_work_closed_message')
                    ->label('Bilgilendirme')
                    ->content(self::CLOSED_MESSAGE)
                    ->visible(fn (): bool => static::isEntryClosedForCurrentUser())
                    ->columnSpanFull(),
                Forms\Components\Repeater::make('bulk_items')
                    ->label('Rutin İş Satırları')
                    ->schema([
                        Forms\Components\DatePicker::make('work_date')
                            ->label('Tarih')
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->minDate($window?->start_date)
                            ->maxDate($window?->end_date)
                            ->rule(function () {
                                return function (string $attribute, $value, \Closure $fail): void {
                                    if (blank($value) || static::isAdmin()) {
                                        return;
                                    }

                                    if (! RoutineWorkWindow::isEntryOpenForDate((string) $value)) {
                                        $fail(self::CLOSED_MESSAGE);
                                    }
                                };
                            })
                            ->disabled(fn (): bool => static::isEntryClosedForCurrentUser()),
                        Forms\Components\Select::make('status')
                            ->label('Durum')
                            ->options(static::statusOptions())
                            ->disabled(fn (): bool => static::isEntryClosedForCurrentUser()),
                        Forms\Components\Textarea::make('work_item')
                            ->label('İş')
                            ->rows(2)
                            ->columnSpanFull()
                            ->disabled(fn (): bool => static::isEntryClosedForCurrentUser()),
                    ])
                    ->defaultItems(10)
                    ->addActionLabel('Alta Satır Ekle')
                    ->reorderable(false)
                    ->collapsible()
                    ->grid(2)
                    ->columns(2)
                    ->columnSpanFull()
                    ->helperText('İlk 10 satır otomatik gelir. Alttaki butondan sınırsız yeni satır ekleyebilirsiniz.')
                    ->visible(fn (string $operation): bool => $operation === 'create'),
                Forms\Components\DatePicker::make('work_date')
                    ->label('Tarih')
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->minDate($window?->start_date)
                    ->maxDate($window?->end_date)
                    ->required()
                    ->rule(function () {
                        return function (string $attribute, $value, \Closure $fail): void {
                            if (static::isAdmin()) {
                                return;
                            }

                            if (! RoutineWorkWindow::isEntryOpenForDate((string) $value)) {
                                $fail(self::CLOSED_MESSAGE);
                            }
                        };
                    })
                    ->disabled(fn (): bool => static::isEntryClosedForCurrentUser())
                    ->visible(fn (string $operation): bool => $operation !== 'create'),
                Forms\Components\Textarea::make('work_item')
                    ->label('İş')
                    ->required()
                    ->rows(3)
                    ->columnSpanFull()
                    ->disabled(fn (): bool => static::isEntryClosedForCurrentUser())
                    ->visible(fn (string $operation): bool => $operation !== 'create'),
                Forms\Components\Select::make('status')
                    ->label('Durum')
                    ->required()
                    ->options(static::statusOptions())
                    ->disabled(fn (): bool => static::isEntryClosedForCurrentUser())
                    ->visible(fn (string $operation): bool => $operation !== 'create'),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('work_date', 'desc')
            ->description(fn (): ?string => static::isEntryClosedForCurrentUser() ? self::CLOSED_MESSAGE : null)
            ->columns([
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
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Müdürlük')
                    ->visible(fn (): bool => static::isAdmin())
                    ->searchable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Son Güncelleme')
                    ->dateTime('d.m.Y H:i'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Düzenle'),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        if (! QuerySafety::shouldApplyFilters($query)) {
            return $query;
        }

        if (static::isAdmin()) {
            return $query;
        }

        return $query->where($query->qualifyColumn('user_id'), (int) auth()->id());
    }

    public static function canViewAny(): bool
    {
        return static::isAdmin() || static::isMudurlukUser();
    }

    public static function canCreate(): bool
    {
        return static::isMudurlukUser() && RoutineWorkWindow::isEntryOpenForDate();
    }

    public static function canEdit(Model $record): bool
    {
        return static::isMudurlukUser()
            && (int) $record->getAttribute('user_id') === (int) auth()->id()
            && RoutineWorkWindow::isEntryOpenForDate();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoutineWorkItems::route('/'),
            'create' => Pages\CreateRoutineWorkItem::route('/create'),
            'edit' => Pages\EditRoutineWorkItem::route('/{record}/edit'),
        ];
    }

    public static function closedMessage(): string
    {
        return self::CLOSED_MESSAGE;
    }

    protected static function isEntryClosedForCurrentUser(): bool
    {
        if (static::isAdmin()) {
            return false;
        }

        return ! RoutineWorkWindow::isEntryOpenForDate();
    }

    protected static function isAdmin(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        if ((int) ($user->id ?? 0) === 1) {
            return true;
        }

        if (method_exists($user, 'hasRole') && $user->hasRole('Admin')) {
            return true;
        }

        return mb_strtolower(trim((string) ($user->role ?? ''))) === 'admin';
    }

    protected static function isMudurlukUser(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return in_array(trim((string) $user->role), [
            User::ROLE_MUDURLUK,
            'mudurluk',
            'MUDURLUK',
            'Mudurluk',
            'müdürlük',
            'MÜDÜRLÜK',
        ], true);
    }

    /**
     * @return array<string, string>
     */
    protected static function statusOptions(): array
    {
        return [
            RoutineWorkItem::STATUS_IN_PROGRESS => 'Devam Ediyor',
            RoutineWorkItem::STATUS_DONE => 'Bitti',
            RoutineWorkItem::STATUS_PLANNED => 'Başlanacak',
        ];
    }
}
