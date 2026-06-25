<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SecurityOutcome: string implements HasColor, HasLabel
{
    case Compliant = 'compliant';
    case Warning = 'warning';
    case NonCompliant = 'non_compliant';

    public function getLabel(): string
    {
        return match ($this) {
            self::Compliant => 'Conforme',
            self::Warning => 'Attenzione',
            self::NonCompliant => 'Non conforme',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Compliant => 'success',
            self::Warning => 'warning',
            self::NonCompliant => 'danger',
        };
    }
}
