<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TicketStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Waiting = 'waiting';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Aperto',
            self::InProgress => 'In lavorazione',
            self::Waiting => 'In attesa',
            self::Resolved => 'Risolto',
            self::Closed => 'Chiuso',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::InProgress => 'warning',
            self::Waiting => 'info',
            self::Resolved => 'success',
            self::Closed => 'gray',
        };
    }
}
