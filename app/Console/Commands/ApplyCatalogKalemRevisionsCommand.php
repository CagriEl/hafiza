<?php

namespace App\Console\Commands;

use App\Support\CatalogKalemRevisions;
use Illuminate\Console\Command;

class ApplyCatalogKalemRevisionsCommand extends Command
{
    protected $signature = 'catalog:apply-kalem-revisions
                            {--no-json : JSON kaynak dosyalarını yazma}';

    protected $description = 'ULM/SKM/MKM katalog kalem revizyonlarını DB ve mevcut raporlara yansıtır; sayısal veriler korunur.';

    public function handle(): int
    {
        $stats = CatalogKalemRevisions::apply(! (bool) $this->option('no-json'));

        $this->info(sprintf(
            'Katalog: %d kayıt. Rapor kalem taşıma: %d. Rapor sync: %d. Ulaşım 3. hafta kapanan kalem: %d.',
            $stats['catalogs'],
            $stats['reports_renamed'],
            $stats['reports_synced'],
            $stats['ulasim_week3_closed']
        ));

        return self::SUCCESS;
    }
}
