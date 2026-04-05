<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

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
                        Forms\Components\Section::make('Sorumlu Personel Detayları')
                            ->description('Bu müdürlükten sorumlu olan personelin iletişim bilgileri.')
                            ->schema([

                                Forms\Components\Select::make('vice_mayor_id')
                                    ->relationship('viceMayor', 'ad_soyad')
                                    ->label('Bağlı Olduğu Başkan Yardımcısı')
                                    ->placeholder('Başkan Yardımcısı Seçiniz...')
                                    ->required(),
                                Forms\Components\TextInput::make('sorumlu_ad_soyad')
                                    ->label('Sorumlu Adı Soyadı')
                                    ->placeholder('Örn: Ahmet Yılmaz'),

                                Forms\Components\TextInput::make('sorumlu_unvan')
                                    ->label('Ünvanı')
                                    ->placeholder('Örn: Bilgisayar İşletmeni'),
                                Forms\Components\TextInput::make('sorumlu_dahili')
                                    ->label('Dahili Telefon No')
                                    ->tel()
                                    ->placeholder('Örn: 1234'),
                            ])->columns(3),

                    ]),

                Section::make('Vekalet (rapor erişimi)')
                    ->description('Tam yetki: yalnızca en az 7 gün süren vekaletlerde işaretlenebilir. Alanlar isteğe bağlıdır; boş bırakılan kayıtlar etkilenmez.')
                    ->schema([
                        Forms\Components\DatePicker::make('vekalet_baslangic')
                            ->label('Vekalet başlangıcı')
                            ->native(false)
                            ->displayFormat('d.m.Y'),
                        Forms\Components\DatePicker::make('vekalet_bitis')
                            ->label('Vekalet bitişi')
                            ->native(false)
                            ->displayFormat('d.m.Y'),
                        Forms\Components\Select::make('vekalet_mudurluk_user_id')
                            ->label('Temsil edilen müdürlük hesabı')
                            ->relationship(
                                name: 'vekaletMudurlukUser',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $q) => $q->where('id', '!=', 1)->orderBy('name')
                            )
                            ->searchable()
                            ->preload(),
                        Forms\Components\Toggle::make('vekalet_tam_yetki')
                            ->label('Tam yetki (7+ gün)')
                            ->rules([
                                fn (Forms\Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get): void {
                                    if (! $value) {
                                        return;
                                    }
                                    if (! $get('vekalet_baslangic') || ! $get('vekalet_bitis') || ! $get('vekalet_mudurluk_user_id')) {
                                        $fail('Tam yetki için başlangıç, bitiş ve temsil edilen müdürlük seçilmelidir.');

                                        return;
                                    }
                                    $a = \Carbon\Carbon::parse($get('vekalet_baslangic'))->startOfDay();
                                    $b = \Carbon\Carbon::parse($get('vekalet_bitis'))->startOfDay();
                                    if ($b->lt($a)) {
                                        $fail('Vekalet bitişi başlangıçtan önce olamaz.');

                                        return;
                                    }
                                    $days = (int) $a->diffInDays($b) + 1;
                                    if ($days < 7) {
                                        $fail('Tam yetki yalnızca takvim üzerinden en az 7 günlük vekalet sürelerinde tanımlanabilir (mevcut: '.$days.' gün).');
                                    }
                                },
                            ]),
                    ])->columns(2),

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
