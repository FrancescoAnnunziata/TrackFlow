<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DeviceStatus: string implements HasColor, HasLabel
{
    case InStock = 'in_stock';
    case Assigned = 'assigned';
    case Maintenance = 'maintenance';
    case Repair = 'repair';
    case Lost = 'lost';
    case Disposed = 'disposed';
    case Reserved = 'reserved';

    public function getLabel(): string
    {
        return match ($this) {
            self::InStock => 'In magazzino',
            self::Assigned => 'Assegnato',
            self::Maintenance => 'In manutenzione',
            self::Repair => 'In riparazione',
            self::Lost => 'Smarrito',
            self::Disposed => 'Dismesso',
            self::Reserved => 'Riservato',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::InStock => 'gray',
            self::Assigned => 'success',
            self::Maintenance => 'warning',
            self::Repair => 'warning',
            self::Lost => 'danger',
            self::Disposed => 'danger',
            self::Reserved => 'info',
        };
    }
}
