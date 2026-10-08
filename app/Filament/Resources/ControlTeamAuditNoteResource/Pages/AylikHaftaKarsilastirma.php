<?php

namespace App\Filament\Resources\ControlTeamAuditNoteResource\Pages;

use App\Filament\Resources\ControlTeamAuditNoteResource;
use App\Models\User;
use App\Support\AnalizEkibiHaftalikFaaliyetEkrani;
use App\Support\AnalizEkibiYoneticiRapor;
use App\Support\AylikFaaliyetPeriodMerge;
use Filament\Actions;
use Filament\Resources\Pages\Page;

class AylikHaftaKarsilastirma extends Page
{
    protected static string $resource = ControlTeamAuditNoteResource::class;

    protected static string $view = 'filament.resources.control-team-audit-note-resource.pages.aylik-hafta-karsilastirma';

    public int $directorate = 0;

    public int $yil = 0;

    public string $ay = '';

    public int $note = 0;

    public string $kod = 'all';

    /** @var array<string, mixed>|null */
    protected ?array $screenCache = null;

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return false;
    }

    public function mount(): void
    {
        abort_unless(ControlTeamAuditNoteResource::canViewAny(), 403);

        $this->directorate = (int) request()->integer('directorate');
        $this->yil = (int) request()->integer('yil');
        $this->ay = AylikFaaliyetPeriodMerge::normalizeAy((string) request()->query('ay', ''));
        $this->note = (int) request()->integer('note');

        $user = auth()->user();
        if (
            $this->directorate > 0
            && $user instanceof User
            && ! AnalizEkibiYoneticiRapor::userCanAccessMudurluk($user, $this->directorate)
        ) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getScreen(): array
    {
        if ($this->screenCache !== null) {
            return $this->screenCache;
        }

        $viewer = auth()->user() instanceof User ? auth()->user() : null;
        $this->screenCache = AnalizEkibiHaftalikFaaliyetEkrani::buildMonth(
            $viewer,
            $this->directorate,
            $this->yil,
            $this->ay
        );

        return $this->screenCache;
    }

    public function getTitle(): string
    {
        return $this->pageBaslik();
    }

    public function getHeading(): string
    {
        return $this->pageBaslik();
    }

    public function getSubheading(): ?string
    {
        return 'Aylık karşılaştırma';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('geri')
                ->label($this->note > 0 ? 'Analiz notuna dön' : 'Analiz notları')
                ->url(
                    $this->note > 0
                        ? ControlTeamAuditNoteResource::getUrl('view', ['record' => $this->note])
                        : ControlTeamAuditNoteResource::getUrl('index')
                )
                ->color('gray'),
        ];
    }

    private function pageBaslik(): string
    {
        $baslik = trim((string) ($this->getScreen()['baslik'] ?? ''));

        return $baslik !== '' ? $baslik : 'Aylık karşılaştırma';
    }
}
