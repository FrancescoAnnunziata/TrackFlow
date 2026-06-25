<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MaintenanceType: string implements HasLabel
{
    case Ordinary = 'ordinary';
    case Repair = 'repair';
    case Replacement = 'replacement';
    case Configuration = 'configuration';
    case Warranty = 'warranty';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ordinary => 'Ordinaria',
            self::Repair => 'Riparazione',
            self::Replacement => 'Sostituzione',
            self::Configuration => 'Configurazione',
            self::Warranty => 'Garanzia',
            self::Other => 'Altro',
        };
    }
}
