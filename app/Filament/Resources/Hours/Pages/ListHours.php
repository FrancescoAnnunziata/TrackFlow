<?php

namespace App\Filament\Resources\Hours\Pages;

use App\Filament\Resources\Hours\HourResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHours extends ListRecords
{
    protected static string $resource = HourResource::class;

    /**
     * Espone lo stato dei filtri della tabella nella query string, così i
     * filtri sono condivisibili via link (es. il link "ore della fattura").
     */
    public function queryString(): array
    {
        return [
            'tableFilters' => [],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
