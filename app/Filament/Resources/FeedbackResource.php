<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FeedbackResource\Pages;
use App\Models\Directorate;
use App\Models\Feedback;
use App\Models\User;
use App\Support\QuerySafety;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class FeedbackResource extends Resource
{
    protected static ?string $model = Feedback::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Geri Bildirim';

    protected static ?string $navigationGroup = 'İletişim';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Geri Bildirim';

    protected static ?string $pluralModelLabel = 'Geri Bildirimler';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Hidden::make('user_id')
                    ->default(fn (): ?int => auth()->id()),
                Forms\Components\Hidden::make('directorate_id')
                    ->default(fn (): ?int => static::resolveDirectorateIdForAuthUser()),
                Forms\Components\TextInput::make('subject')
                    ->label('Konu')
                    ->required()
                    ->maxLength(255)
                    ->disabled(fn (string $operation): bool => $operation === 'edit'),
                Forms\Components\Select::make('category')
                    ->label('Kategori')
                    ->required()
                    ->options([
                        Feedback::CATEGORY_BUG => Feedback::CATEGORY_BUG,
                        Feedback::CATEGORY_SUGGESTION => Feedback::CATEGORY_SUGGESTION,
                        Feedback::CATEGORY_REQUEST => Feedback::CATEGORY_REQUEST,
                        Feedback::CATEGORY_OTHER => Feedback::CATEGORY_OTHER,
                    ])
                    ->disabled(fn (string $operation): bool => $operation === 'edit'),
                Forms\Components\Textarea::make('message')
                    ->label('Mesaj Detayı')
                    ->required()
                    ->rows(8)
                    ->columnSpanFull()
                    ->disabled(fn (string $operation): bool => $operation === 'edit'),
                Forms\Components\Select::make('status')
                    ->label('Durum')
                    ->options([
                        Feedback::STATUS_NEW => Feedback::STATUS_NEW,
                        Feedback::STATUS_REVIEWING => Feedback::STATUS_REVIEWING,
                        Feedback::STATUS_RESOLVED => Feedback::STATUS_RESOLVED,
                        Feedback::STATUS_REJECTED => Feedback::STATUS_REJECTED,
                    ])
                    ->default(Feedback::STATUS_NEW)
                    ->required()
                    ->visible(fn (string $operation): bool => static::isAdmin() || $operation === 'create')
                    ->disabled(fn (string $operation): bool => ! static::isAdmin() && $operation !== 'create'),
                Forms\Components\Textarea::make('admin_note')
                    ->label('IT Ekibi Notu / Yanıtı')
                    ->rows(6)
                    ->columnSpanFull()
                    ->visible(fn (): bool => static::isAdmin()),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('subject')
                    ->label('Konu')
                    ->searchable()
                    ->limit(40),
                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Durum')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Feedback::STATUS_NEW => 'warning',
                        Feedback::STATUS_REVIEWING => 'info',
                        Feedback::STATUS_RESOLVED => 'success',
                        Feedback::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('directorate.name')
                    ->label('Müdürlük')
                    ->placeholder('Belirlenemedi')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Gönderen')
                    ->toggleable(isToggledHiddenByDefault: ! static::isAdmin()),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tarih')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategoriye Göre Filtrele')
                    ->options([
                        Feedback::CATEGORY_BUG => Feedback::CATEGORY_BUG,
                        Feedback::CATEGORY_SUGGESTION => Feedback::CATEGORY_SUGGESTION,
                        Feedback::CATEGORY_REQUEST => Feedback::CATEGORY_REQUEST,
                        Feedback::CATEGORY_OTHER => Feedback::CATEGORY_OTHER,
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Görüntüle'),
                Tables\Actions\EditAction::make()
                    ->label('Durum Güncelle')
                    ->visible(fn (): bool => static::isAdmin()),
            ])
            ->bulkActions([]);
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

        $userId = auth()->id();
        if (! $userId) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where($query->qualifyColumn('user_id'), $userId);
    }

    public static function canViewAny(): bool
    {
        return static::isAdmin() || static::isMudurlukUser();
    }

    public static function canCreate(): bool
    {
        return static::isMudurlukUser();
    }

    public static function canView(Model $record): bool
    {
        if (static::isAdmin()) {
            return true;
        }

        return (int) $record->getAttribute('user_id') === (int) auth()->id();
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
            'index' => Pages\ListFeedbacks::route('/'),
            'create' => Pages\CreateFeedback::route('/create'),
            'view' => Pages\ViewFeedback::route('/{record}'),
            'edit' => Pages\EditFeedback::route('/{record}/edit'),
        ];
    }

    public static function resolveDirectorateIdForAuthUser(): ?int
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }

        // Yeni şemada müdürlük ilişkisi users.directorate_id üzerinden gelir.
        $directDirectorateId = (int) ($user->directorate_id ?? 0);
        if ($directDirectorateId > 0) {
            $exists = Directorate::query()->whereKey($directDirectorateId)->exists();

            if ($exists) {
                return $directDirectorateId;
            }
        }

        // Eski şema desteği: bazı ortamlarda directorates.mudurluk_user_id bulunabilir.
        if (Schema::hasColumn('directorates', 'mudurluk_user_id')) {
            $byMapping = Directorate::query()
                ->where('mudurluk_user_id', $user->id)
                ->value('id');

            if ($byMapping) {
                return (int) $byMapping;
            }
        }

        $name = trim((string) $user->name);
        if ($name === '') {
            return null;
        }

        $exact = Directorate::query()->where('name', $name)->value('id');
        if ($exact) {
            return (int) $exact;
        }

        $normalized = str_replace(' Müdürlüğü', '', $name);
        if ($normalized !== $name) {
            $like = Directorate::query()
                ->where('name', 'like', $normalized.'%')
                ->value('id');

            return $like ? (int) $like : null;
        }

        return null;
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

        return $user instanceof User && trim((string) $user->role) === User::ROLE_MUDURLUK;
    }
}
