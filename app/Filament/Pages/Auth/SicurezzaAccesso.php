<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;

/**
 * La pagina profilo di Filament ridotta alla sola parte dei due fattori: qui
 * ciascuno gestisce l'app di autenticazione e aggiunge o toglie le passkey.
 *
 * Nome, email e password non si cambiano da qui: gli utenti li crea un admin,
 * e la password ha già la sua pagina (ChangePassword), con le sue regole.
 */
class SicurezzaAccesso extends EditProfile
{
    public static function getLabel(): string
    {
        return 'Sicurezza dell\'accesso';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Sicurezza dell\'accesso';
    }

    public static function isSimple(): bool
    {
        return false;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components(Arr::wrap($this->getMultiFactorAuthenticationContentComponent()));
    }
}
