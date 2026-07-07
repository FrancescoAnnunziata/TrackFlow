<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['role'] ?? null) === 'client') {
            // I clienti accedono via magic link: password non necessaria e
            // nessun cambio password forzato. Se non impostata, ne generiamo una
            // casuale solo per soddisfare il vincolo NOT NULL della colonna.
            $data['must_change_password'] = false;

            if (empty($data['password'])) {
                $data['password'] = Str::random(40);
            }

            return $data;
        }

        $data['must_change_password'] = true;

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->syncPrimaryClientMembership();
    }

    /**
     * Il cliente principale deve sempre far parte delle associazioni pivot,
     * cosi' la visibilita' e le liste lato Cliente restano coerenti anche se
     * non e' stato aggiunto esplicitamente tra i "Clienti associati".
     */
    private function syncPrimaryClientMembership(): void
    {
        if ($this->record->client_id) {
            $this->record->clients()->syncWithoutDetaching([$this->record->client_id]);
        }
    }
}
