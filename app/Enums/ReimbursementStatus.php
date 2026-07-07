<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ReimbursementStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'In attesa',
            self::Approved => 'Approvato',
            self::Paid => 'Rimborsato',
            self::Rejected => 'Rifiutato',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'info',
            self::Paid => 'success',
            self::Rejected => 'danger',
        };
    }
}
