<?php

namespace App\Filament\Pages\Auth;

use App\Http\Middleware\ReauthenticateWeekly;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class Login extends BaseLogin
{
    public function form(Schema $schema): Schema
    {
        return parent::form($schema);
    }

    /**
     * «Ricordami» qui non è per sempre: dura quanto la finestra di riaccesso,
     * e l'etichetta lo dice invece di lasciarlo scoprire. Su questo dispositivo
     * niente password né codice per una settimana; poi si rifà tutto.
     */
    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()
            ->label('Ricorda questo dispositivo per '.ReauthenticateWeekly::DAYS.' giorni')
            ->helperText('Da usare solo su un dispositivo tuo: per una settimana non ti verranno chiesti password e codice.');
    }
}
