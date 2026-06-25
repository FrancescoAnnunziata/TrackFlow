<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DeviceCategory: string implements HasLabel
{
    case IT = 'IT';
    case Phone = 'Phone';
    case Network = 'Network';
    case Office = 'Office';
    case Furniture = 'Furniture';
    case Vehicle = 'Vehicle';
    case Tool = 'Tool';
    case Other = 'Other';

    public function getLabel(): string
    {
        return match ($this) {
            self::IT => 'IT',
            self::Phone => 'Telefonia',
            self::Network => 'Rete',
            self::Office => 'Ufficio',
            self::Furniture => 'Arredo',
            self::Vehicle => 'Veicolo',
            self::Tool => 'Strumento',
            self::Other => 'Altro',
        };
    }
}
