<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PersonelDurumResource\Pages;
use App\Models\PersonelDurum;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PersonelDurumResource extends Resource
{
    protected static ?string $model = PersonelDurum::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'Personel Sayıları';
    protected static ?string $navigationGroup = 'Tanımlamalar';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Kadro Cetveli (Sayılar)')
                    ->description('Lütfen güncel personel sayılarını giriniz.')
                    ->schema([
                        Forms\Components\TextInput::make('memur')
                            ->label('Memur Sayısı')
                            ->numeric()->default(0)->required(),
                        
                        Forms\Components\TextInput::make('sozlesmeli_memur')
                            ->label('Sözleşmeli Memur')
                            ->numeric()->default(0)->required(),
                            
                        Forms\Components\TextInput::make('kadrolu_isci')
                            ->label('Kadrolu İşçi')
                            ->numeric()->default(0)->required(),
                            
                        Forms\Components\TextInput::make('sirket_personeli')
                            ->label('Şirket Personeli')
                            ->numeric()->default(0)->required(),
                            
                        Forms\Components\TextInput::make('gecici_isci')
                            ->label('Geçici İşçi')
                            ->numeric()->default(0)->required(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Müdürlük')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('memur')
                    ->label('Memur')
                    ->badge(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Son Güncelleme')
                    ->dateTime('d.m.Y H:i'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Güncelle'),
            ]);
    }

    // Admin (ID:1) herkesi görür, diğerleri sadece kendi kaydını
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (auth()->id() !== 1) { 
            $query->where('user_id', auth()->id());
        }
        
        return $query;
    }
    public static function canCreate(): bool
    {
        return auth()->id() !== 1;
    }

    public static function canEdit($record): bool
    {
        return auth()->id() !== 1;
    }

    // Admin silebilsin mi? Hayır, sadece görüntülesin diyorsanız bunu da ekleyin:
    public static function canDelete($record): bool
    {
        return auth()->id() !== 1;
    }

    // --- İŞTE EKSİK OLAN KISIM BURASIYDI ---
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPersonelDurums::route('/'),
            'create' => Pages\CreatePersonelDurum::route('/create'),
            'edit' => Pages\EditPersonelDurum::route('/{record}/edit'),
        ];
    }
}