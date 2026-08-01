<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Guida operativa all'uso dell'app, focalizzata sul ciclo di fatturazione
 * attiva (creazione fattura → invio a Fatture in Cloud → incasso → nota di
 * credito). Manuale passo-passo per chi gestisce la fatturazione. Solo admin.
 */
class Guida extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $title = 'Guida';

    protected static ?string $navigationLabel = 'Guida';

    /** In cima al menu: è il punto di partenza per chi impara a usare l'app. */
    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.pages.guida';

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }
}
