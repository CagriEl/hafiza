<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn; 
use Illuminate\Support\Facades\Hash;
use Filament\Forms\Components\Placeholder;
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

   public static function form(Form $form): Form
{
    return $form
        ->schema([
            Section::make('Müdürlük Bilgileri')
                ->schema([
                    TextInput::make('name')
                        ->label('Müdürlük Adı')
                        ->required(),
                    
                    TextInput::make('email')
                        ->label('E-Posta Adresi')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true),
                    
                    TextInput::make('password')
                        ->label('Parola')
                        ->password()
                        ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                        ->dehydrated(fn ($state) => filled($state)) // Sadece doluysa güncelle
                        ->required(fn (string $context): bool => $context === 'create'), // Sadece oluştururken zorunlu
                ])
        ]);
}

public static function table(Table $table): Table
{
    return $table
        ->columns([
            TextColumn::make('name')->label('Müdürlük')->searchable(),
            TextColumn::make('email')->label('E-Posta'),
            TextColumn::make('created_at')->label('Kayıt Tarihi')->date(),
        ])
        ->filters([
            //
        ]);
}

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function canViewAny(): bool
{
    // Sadece ID'si 1 olan kullanıcı (Siz) bu menüyü görebilir.
    return auth()->id() === 1;
}
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
