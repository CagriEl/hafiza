<?php

namespace App\Support;

use Filament\Forms;
use Filament\Forms\Get;

final class MaliHizmetlerRaporForm
{
    /**
     * Mali Hizmetler için ödeme planı alanları (faaliyet / katalog yok).
     *
     * @return list<Forms\Components\Component>
     */
    public static function dataEntrySchema(bool $includePeriodSelector = false): array
    {
        $schema = [];

        if ($includePeriodSelector) {
            $schema[] = Forms\Components\Section::make('Rapor Haftası')
                ->description('Ödeme planı seçili hafta için kaydedilir.')
                ->schema(self::userPeriodSchema())
                ->columns(1);
        }

        $schema[] = Forms\Components\Section::make('Ödeme Planı')
            ->description('Seçili haftaya ait haftalık ödeme tutarını giriniz.')
            ->schema([
                Forms\Components\TextInput::make('haftalik_odeme_toplam')
                    ->label('Toplam Haftalık Ödeme')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->suffix('₺')
                    ->required()
                    ->default(0)
                    ->live(onBlur: true),
            ])
            ->columns(1);
        $schema[] = Forms\Components\Section::make('Ödeme Talepleri')
            ->schema([
                Forms\Components\Repeater::make('odeme_talepleri')
                    ->label('')
                    ->live()
                    ->schema([
                        Forms\Components\TextInput::make('aciklama')
                            ->label('Talep Açıklaması')
                            ->required()
                            ->maxLength(500)
                            ->columnSpan(2),
                        Forms\Components\TextInput::make('tutar')
                            ->label('Tutar')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->suffix('₺')
                            ->required(),
                        Forms\Components\Checkbox::make('firma_arandi')
                            ->label('Firma arandı')
                            ->inline(false),
                        Forms\Components\DatePicker::make('tarih')
                            ->label('Tarih')
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->default(fn (): string => ReportPeriodWeeks::systemRecordDateString()),
                        Forms\Components\TextInput::make('arayan_personel')
                            ->label('Arayan Personel')
                            ->maxLength(255)
                            ->placeholder('Ad soyad'),
                    ])
                    ->columns(3)
                    ->addActionLabel('Ödeme talebi ekle')
                    ->defaultItems(0)
                    ->collapsible()
                    ->columnSpanFull(),
            ]);

        return $schema;
    }

    /**
     * @return list<Forms\Components\Component>
     */
    public static function userPeriodSchema(): array
    {
        return [
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\Select::make('yil')
                    ->label('Yıl')
                    ->options([
                        now()->year - 1 => (string) (now()->year - 1),
                        now()->year => (string) now()->year,
                        now()->year + 1 => (string) (now()->year + 1),
                    ])
                    ->required()
                    ->live(),
                Forms\Components\Select::make('ay')
                    ->label('Ay')
                    ->options(collect(ReportPeriodWeeks::turkishMonthNames())
                        ->mapWithKeys(fn (string $label, int $month): array => [
                            str_pad((string) $month, 2, '0', STR_PAD_LEFT) => $label,
                        ])
                        ->all())
                    ->required()
                    ->live(),
                Forms\Components\Select::make('hafta')
                    ->label('Hafta')
                    ->options(function (Get $get): array {
                        $yil = (int) ($get('yil') ?? now()->year);
                        $ay = (int) preg_replace('/\D/', '', (string) ($get('ay') ?? now()->format('m')));
                        if ($yil <= 0 || $ay < 1 || $ay > 12) {
                            return [];
                        }

                        return ReportPeriodWeeks::periodSelectOptions($yil, $ay, 'Haftalık');
                    })
                    ->required()
                    ->live(),
            ]),
        ];
    }

    /**
     * @return list<Forms\Components\Component>
     */
    public static function adminPeriodSchema(): array
    {
        return [
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\Select::make('yil')
                    ->label('Yıl')
                    ->options([
                        now()->year - 1 => (string) (now()->year - 1),
                        now()->year => (string) now()->year,
                        now()->year + 1 => (string) (now()->year + 1),
                    ])
                    ->required()
                    ->live(),
                Forms\Components\Select::make('ay')
                    ->label('Ay')
                    ->options(collect(ReportPeriodWeeks::turkishMonthNames())
                        ->mapWithKeys(fn (string $label, int $month): array => [
                            str_pad((string) $month, 2, '0', STR_PAD_LEFT) => $label,
                        ])
                        ->all())
                    ->required()
                    ->live(),
                Forms\Components\Select::make('hafta')
                    ->label('Hafta')
                    ->options(function (Get $get): array {
                        $yil = (int) ($get('yil') ?? now()->year);
                        $ay = (int) preg_replace('/\D/', '', (string) ($get('ay') ?? now()->format('m')));
                        if ($yil <= 0 || $ay < 1 || $ay > 12) {
                            return [];
                        }

                        return ReportPeriodWeeks::periodSelectOptions($yil, $ay, 'Haftalık');
                    })
                    ->required(),
            ]),
            Forms\Components\Select::make('user_id')
                ->label('Müdürlük')
                ->relationship('user', 'name')
                ->searchable()
                ->preload()
                ->required(),
        ];
    }
}
