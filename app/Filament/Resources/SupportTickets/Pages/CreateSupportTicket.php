<?php

namespace App\Filament\Resources\SupportTickets\Pages;

use App\Filament\Resources\SupportTickets\SupportTicketResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupportTicket extends CreateRecord
{
    protected static string $resource = SupportTicketResource::class;

    public function mount(): void
    {
        parent::mount();

        if ($deviceId = request()->integer('device_id')) {
            $this->form->fill(['device_id' => $deviceId]);
        }
    }
}
