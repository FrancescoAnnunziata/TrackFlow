<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FindingStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case AcceptedRisk = 'accepted_risk';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Aperto',
            self::InProgress => 'In lavorazione',
            self::Resolved => 'Risolto',
            self::AcceptedRisk => 'Rischio accettato',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::InProgress => 'warning',
            self::Resolved => 'success',
            self::AcceptedRisk => 'gray',
        };
    }
}
