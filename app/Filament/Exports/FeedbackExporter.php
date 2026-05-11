<?php

namespace App\Filament\Exports;

use App\Models\Feedback;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class FeedbackExporter extends Exporter
{
    protected static ?string $model = Feedback::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('user.name')->label('Müdürlük'),
            ExportColumn::make('message')->label('İçerik'),
            ExportColumn::make('created_at')
                ->label('Tarih')
                ->state(fn (Feedback $record): string => optional($record->created_at)?->format('d.m.Y H:i') ?? '—'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Geri bildirim dışa aktarma tamamlandı: '.number_format($export->successful_rows).' kayıt.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' kayıt başarısız oldu.';
        }

        return $body;
    }
}
