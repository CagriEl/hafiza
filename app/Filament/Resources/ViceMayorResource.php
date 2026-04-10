<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ViceMayorResource\Pages;
use App\Models\ViceMayor;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ViceMayorResource extends Resource
{
    protected static ?string $model = ViceMayor::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Başkan Yardımcıları';

    protected static ?string $navigationGroup = 'Yönetim';

    protected static ?string $pluralLabel = 'Başkan Yardımcıları';

    protected static ?string $modelLabel = 'Başkan Yardımcısı';

    /**
     * SADECE ADMİN GÖREBİLİR
     * Sol menüde bu alanı sadece Admin (ID: 1) kullanıcı görür.
     */
    public static function canViewAny(): bool
    {
        return auth()->id() === 1;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Başkan Yardımcısı ve Giriş Bilgileri')
                    ->schema([
                        Forms\Components\TextInput::make('ad_soyad')
                            ->label('Adı Soyadı')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, callable $set) => $set('name', $state)),

                        Forms\Components\TextInput::make('unvan')
                            ->label('Ünvanı')
                            ->default('Belediye Başkan Yardımcısı'),

                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('email')
                                ->label('Giriş E-postası')
                                ->email()
                                ->required()
                                // Mevcut kullanıcının e-postasını getir (Düzenleme modunda)
                                ->formatStateUsing(fn ($record) => $record?->user?->email),

                            Forms\Components\TextInput::make('password')
                                ->label('Giriş Parolası')
                                ->password()
                                ->dehydrated(fn ($state) => filled($state))
                                ->required(fn (string $context): bool => $context === 'create')
                                ->helperText('Değiştirmek istemiyorsanız boş bırakın.'),
                        ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ad_soyad')
                    ->label('Başkan Yardımcısı')
                    ->searchable()
                    ->sortable()
                    ->description(fn (ViceMayor $record): string => $record->unvan),

                // Bu makama atanmış kullanıcı hesabı (Login E-postası ile)
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Giriş Hesabı')
                    ->icon('heroicon-m-at-symbol')
                    ->color('gray'),

                // BAĞLI MÜDÜRLÜKLERİ TEK SATIRDA GÖSTEREN SÜTUN
                Tables\Columns\TextColumn::make('users.name')
                    ->label('Bağlı Müdürlükler')
                    ->badge()
                    ->color('info')
                    ->separator(', ') // Birden fazlaysa araya virgül koyar
                    ->searchable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Kayıt Tarihi')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->modal(), // Daha hızlı düzenleme için modal
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
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListViceMayors::route('/'),
            'create' => Pages\CreateViceMayor::route('/create'),
            'edit' => Pages\EditViceMayor::route('/{record}/edit'),
        ];
    }
}
