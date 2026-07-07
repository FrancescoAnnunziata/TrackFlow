<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ReimbursementType: string implements HasColor, HasLabel
{
    case Travel = 'trasferta';
    case PersonalCard = 'carta_personale';
    case Manual = 'manuale';

    public function getLabel(): string
    {
        return match ($this) {
            self::Travel => 'Trasferta',
            self::PersonalCard => 'Carta personale',
            self::Manual => 'Aggiunta manuale',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Travel => 'info',
            self::PersonalCard => 'warning',
            self::Manual => 'gray',
        };
    }
}
