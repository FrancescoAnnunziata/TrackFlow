<?php

namespace App\Filament\Resources\Devices\Pages;

use App\Filament\Resources\Devices\DeviceResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListDevices extends ListRecords
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Esporta Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                // Nessun shouldOpenInNewTab: il download (Content-Disposition:
                // attachment) parte nella stessa scheda senza cambiare pagina.
                // Con target=_blank resterebbe una tab vuota "in caricamento".
                ->url(fn (): string => route('assets.export')),
            CreateAction::make(),
        ];
    }
}
