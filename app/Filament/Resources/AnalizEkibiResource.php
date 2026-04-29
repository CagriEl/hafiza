<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AnalizEkibiResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

class AnalizEkibiResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Analiz Ekibi';

    protected static ?string $navigationGroup = 'Yönetim';

    protected static ?string $pluralLabel = 'Analiz Ekibi';

    protected static ?string $modelLabel = 'Analiz Ekibi Üyesi';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return auth()->id() === 1;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Analiz Ekibi Hesabı')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Ad Soyad')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('email')
                            ->label('E-Posta')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        Forms\Components\TextInput::make('password')
                            ->label('Parola')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create')
                            ->helperText('Değiştirmek istemiyorsanız boş bırakın.'),

                        Forms\Components\Select::make('assignedDirectorates')
                            ->label('Bağlı Müdürlükler')
                            ->relationship(
                                name: 'assignedDirectorates',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->onlyMudurlukReportingAccounts()
                                    ->orderBy($query->qualifyColumn('name'))
                            )
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->helperText('Bu ekip üyesinin raporlarda görebileceği müdürlükleri seçin.'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Ad Soyad')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('E-Posta')
                    ->searchable(),
                Tables\Columns\TextColumn::make('assignedDirectorates.name')
                    ->label('Bağlı Müdürlükler')
                    ->badge()
                    ->separator(', ')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Kayıt Tarihi')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAnalizEkibis::route('/'),
            'create' => Pages\CreateAnalizEkibi::route('/create'),
            'edit' => Pages\EditAnalizEkibi::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('role', User::ROLE_ANALIZ_EKIBI);
    }
}
