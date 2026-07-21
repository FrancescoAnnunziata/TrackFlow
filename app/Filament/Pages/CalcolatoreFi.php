<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Calcolatore personale di libertà finanziaria (FI): patrimonio, milestone Coast
 * FIRE e FI vera al variare di reddito, spese, casa e rendimento. Pagina privata:
 * accessibile solo al titolare. Il contenuto è statico (HTML + JS) ed è isolato
 * in un iframe, così il suo CSS non interferisce col pannello Filament.
 */
class CalcolatoreFi extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|\UnitEnum|null $navigationGroup = 'Controllo Finanziario';

    protected static ?string $title = 'Calcolatore FI';

    protected static ?string $navigationLabel = 'Calcolatore FI';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.calcolatore-fi';

    /** Unico utente autorizzato a vedere questa pagina personale. */
    private const OWNER_EMAIL = 'giorgio.giotto@g8labs.it';

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->email === self::OWNER_EMAIL;
    }

    /**
     * HTML autoconsistente del calcolatore, iniettato nell'iframe via srcdoc.
     */
    public function appHtml(): string
    {
        return (string) file_get_contents(resource_path('calcolatore-fi-app.html'));
    }
}
