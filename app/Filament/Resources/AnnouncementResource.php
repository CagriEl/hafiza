<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AnnouncementResource\Pages;
use App\Models\Announcement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'Duyurular';

    protected static ?string $navigationGroup = 'Yönetim';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Duyuru';

    protected static ?string $pluralModelLabel = 'Duyurular';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('Başlık')
                ->required()
                ->maxLength(255),
            Forms\Components\RichEditor::make('content')
                ->label('İçerik')
                ->required()
                ->columnSpanFull(),
            Forms\Components\Select::make('type')
                ->label('Duyuru Tipi')
                ->options([
                    Announcement::TYPE_INFO => 'Bilgi',
                    Announcement::TYPE_WARNING => 'Uyarı',
                    Announcement::TYPE_CRITICAL => 'Kritik',
                ])
                ->required()
                ->default(Announcement::TYPE_INFO),
            Forms\Components\DateTimePicker::make('published_at')
                ->label('Yayın Tarihi')
                ->seconds(false)
                ->native(false)
                ->default(now()),
            Forms\Components\DateTimePicker::make('expires_at')
                ->label('Yayından Kalkış Tarihi')
                ->seconds(false)
                ->native(false)
                ->helperText('Boş bırakılırsa duyuru süresiz yayında kalır.')
                ->after('published_at')
                ->rule('nullable|after:published_at'),
            Forms\Components\Toggle::make('is_active')
                ->label('Aktif mi?')
                ->default(true),
            Forms\Components\Toggle::make('is_popup')
                ->label('Pop-up olarak gösterilsin mi?')
                ->helperText('Açık olduğunda bu duyuru girişte pop-up olarak gösterilir.')
                ->default(false),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('published_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Başlık')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tip')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Announcement::TYPE_INFO => 'Bilgi',
                        Announcement::TYPE_WARNING => 'Uyarı',
                        Announcement::TYPE_CRITICAL => 'Kritik',
                        default => 'Belirsiz',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        Announcement::TYPE_INFO => 'info',
                        Announcement::TYPE_WARNING => 'warning',
                        Announcement::TYPE_CRITICAL => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_popup')
                    ->label('Pop-up')
                    ->boolean(),
                Tables\Columns\TextColumn::make('published_at')
                    ->label('Yayın Tarihi')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Yayından Kalkış')
                    ->placeholder('Süresiz')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Düzenle'),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()->label('Seçilenleri Sil'),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        try {
            if (! $query->getModel() || $query->getModel()->getTable() === '') {
                return $query;
            }
        } catch (\Throwable) {
            return $query;
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        return static::userHasAdminRole();
    }

    public static function canCreate(): bool
    {
        return static::userHasAdminRole();
    }

    public static function canEdit($record): bool
    {
        return static::userHasAdminRole();
    }

    public static function canDelete($record): bool
    {
        return static::userHasAdminRole();
    }

    public static function canDeleteAny(): bool
    {
        return static::userHasAdminRole();
    }

    public static function getNavigationIcon(): string
    {
        return static::$navigationIcon ?? 'heroicon-o-megaphone';
    }

    public static function getNavigationLabel(): string
    {
        return static::$navigationLabel ?? 'Duyurular';
    }

    public static function getNavigationGroup(): ?string
    {
        return static::$navigationGroup ?? 'Yönetim';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAnnouncements::route('/'),
            'create' => Pages\CreateAnnouncement::route('/create'),
            'edit' => Pages\EditAnnouncement::route('/{record}/edit'),
        ];
    }

    protected static function userHasAdminRole(): bool
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
