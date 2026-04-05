<?php

namespace App\Filament\Concerns;

use App\Models\User;
use App\Services\ActivityService;

trait WarnsIfActivityCatalogEmpty
{
    /**
     * Dropdown boşsa tarayıcı konsoluna aranan müdürlük ve debug bilgisini yazar (Filament Livewire js()).
     */
    protected function warnIfActivityCatalogEmpty(string $mudurlukAdi): void
    {
        if (trim($mudurlukAdi) === '') {
            return;
        }

        $user = auth()->user();
        if ($user instanceof User && $user->isReportingSuperAdmin()) {
            return;
        }

        $bundle = app(ActivityService::class)->resolveCatalogOptionsForMudurluk($mudurlukAdi);
        if ($bundle['options'] !== []) {
            return;
        }

        $payload = array_merge(
            [
                'message' => 'Faaliyet katalog dropdown boş',
                'mudurluk_aranan' => $mudurlukAdi,
            ],
            $bundle['debug']
        );

        if (method_exists($this, 'js')) {
            $this->js('console.warn('.json_encode($payload, JSON_UNESCAPED_UNICODE).')');
        }
    }
}
