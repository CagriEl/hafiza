<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityCatalogResource\Pages;
use App\Models\ActivityCatalog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ActivityCatalogResource extends Resource
{
    protected static ?string $model = ActivityCatalog::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'Faaliyet Kataloğu';
    protected static ?string $navigationGroup = 'Yönetim';
    protected static ?string $pluralLabel = 'Faaliyet Katalog Verileri';

    // Sadece Admin görebilsin
    public static function canViewAny(): bool
    {
        return auth()->id() === 1;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Faaliyet Detayları')
                    ->schema([
                        Forms\Components\TextInput::make('mudurluk')->label('Müdürlük')->required(),
                        Forms\Components\TextInput::make('faaliyet_kodu')->label('Kod')->required(),
                        Forms\Components\TextInput::make('faaliyet_ailesi')->label('Faaliyet Ailesi')->required(),
                        Forms\Components\TextInput::make('kategori')->label('Kategori'),
                        Forms\Components\Textarea::make('kapsam')->label('Kapsam')->columnSpanFull(),
                        Forms\Components\TextInput::make('olcu_birimi')->label('Ölçü Birimi'),
                        Forms\Components\TextInput::make('kpi_sla')->label('KPI / SLA Hedefi'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // TABLODA GÖRÜNECEK SÜTUNLAR BURADADIR
                Tables\Columns\TextColumn::make('faaliyet_kodu')
                    ->label('Kod')
                    ->searchable()
                    ->sortable()
                    ->badge(),

                Tables\Columns\TextColumn::make('mudurluk')
                    ->label('Müdürlük')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('faaliyet_ailesi')
                    ->label('Faaliyet Tanımı')
                    ->searchable()
                    ->wrap(), // Uzun metinleri alt satıra indirir

                Tables\Columns\TextColumn::make('olcu_birimi')
                    ->label('Birim')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('kpi_sla')
                    ->label('KPI / SLA')
                    ->limit(30), // Çok uzunsa keser
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('mudurluk')
                    ->label('Müdürlüğe Göre Filtrele')
                    ->options(ActivityCatalog::pluck('mudurluk', 'mudurluk')->toArray()),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityCatalogs::route('/'),
            'create' => Pages\CreateActivityCatalog::route('/create'),
            'edit' => Pages\EditActivityCatalog::route('/{record}/edit'),
        ];
    }
}