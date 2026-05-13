<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoutineWorkWindowResource\Pages;
use App\Models\RoutineWorkWindow;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RoutineWorkWindowResource extends Resource
{
    protected static ?string $model = RoutineWorkWindow::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Rutin İşler Ayarı';

    protected static ?string $navigationGroup = 'Yönetim';

    protected static ?string $modelLabel = 'Rutin İşler Ayarı';

    protected static ?string $pluralModelLabel = 'Rutin İşler Ayarı';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DatePicker::make('start_date')
                    ->label('Başlangıç Tarihi')
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->required(),
                Forms\Components\DatePicker::make('end_date')
                    ->label('Bitiş Tarihi')
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->afterOrEqual('start_date')
                    ->required(),
                Forms\Components\Toggle::make('is_active')
                    ->label('Rutin İşler Modülü Aktif')
                    ->helperText('Aktif edildiğinde sistem otomatik duyuru yayınlar.')
                    ->default(false)
                    ->required(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('start_date')
                    ->label('Başlangıç')
                    ->date('d.m.Y'),
                Tables\Columns\TextColumn::make('end_date')
                    ->label('Bitiş')
                    ->date('d.m.Y'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Güncellenme')
                    ->dateTime('d.m.Y H:i'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Düzenle'),
            ])
            ->bulkActions([]);
    }

    public static function canViewAny(): bool
    {
        return static::isAdmin();
    }

    public static function canCreate(): bool
    {
        return static::isAdmin() && ! RoutineWorkWindow::query()->exists();
    }

    public static function canEdit(Model $record): bool
    {
        return static::isAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoutineWorkWindows::route('/'),
            'create' => Pages\CreateRoutineWorkWindow::route('/create'),
            'edit' => Pages\EditRoutineWorkWindow::route('/{record}/edit'),
        ];
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
}
