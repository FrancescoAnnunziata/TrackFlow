<?php

namespace Database\Seeders;

use App\Enums\DeviceCategory;
use App\Enums\DeviceStatus;
use App\Models\Client;
use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Seeder;

class DeviceSeeder extends Seeder
{
    /**
     * Popola la tabella dispositivi con alcuni asset d'esempio per il primo
     * cliente disponibile. Idempotente sul serial_number così da poter essere
     * rieseguito senza creare duplicati.
     */
    public function run(): void
    {
        // Dati di solo sviluppo: non devono MAI popolare l'ambiente di
        // produzione (es. push su master). Gira solo in locale.
        if (! app()->environment('local')) {
            $this->command?->warn('DeviceSeeder ignorato: eseguibile solo in ambiente local.');

            return;
        }

        $client = Client::query()->first();

        if (! $client) {
            $this->command?->warn('Nessun cliente presente: salto il seed dei dispositivi.');

            return;
        }

        $assignee = User::query()->whereNull('client_id')->value('id');

        $devices = [
            ['category' => DeviceCategory::IT, 'type' => 'Laptop', 'manufacturer' => 'Apple', 'model' => 'MacBook Pro 14" M3', 'serial_number' => 'C02ZX1MPLVDL', 'status' => DeviceStatus::Assigned, 'assigned' => true],
            ['category' => DeviceCategory::IT, 'type' => 'Laptop', 'manufacturer' => 'Dell', 'model' => 'Latitude 5540', 'serial_number' => 'DL5540-8842J', 'status' => DeviceStatus::Assigned, 'assigned' => true],
            ['category' => DeviceCategory::IT, 'type' => 'Desktop', 'manufacturer' => 'HP', 'model' => 'EliteDesk 800 G9', 'serial_number' => 'HP800G9-01277', 'status' => DeviceStatus::InStock, 'assigned' => false],
            ['category' => DeviceCategory::IT, 'type' => 'Monitor', 'manufacturer' => 'LG', 'model' => 'UltraFine 27UP850', 'serial_number' => 'LG27UP-556210', 'status' => DeviceStatus::InStock, 'assigned' => false],
            ['category' => DeviceCategory::Phone, 'type' => 'Smartphone', 'manufacturer' => 'Apple', 'model' => 'iPhone 15', 'serial_number' => 'F17GQ2ABX9K1', 'status' => DeviceStatus::Assigned, 'assigned' => true],
            ['category' => DeviceCategory::Phone, 'type' => 'Smartphone', 'manufacturer' => 'Samsung', 'model' => 'Galaxy S24', 'serial_number' => 'RZ8W70FH4PA', 'status' => DeviceStatus::Reserved, 'assigned' => false],
            ['category' => DeviceCategory::Network, 'type' => 'Switch', 'manufacturer' => 'Ubiquiti', 'model' => 'UniFi Switch 24 PoE', 'serial_number' => 'UBNT-24POE-0093', 'status' => DeviceStatus::InStock, 'assigned' => false],
            ['category' => DeviceCategory::Office, 'type' => 'Stampante', 'manufacturer' => 'Brother', 'model' => 'HL-L2375DW', 'serial_number' => 'BRHL2375-44120', 'status' => DeviceStatus::Maintenance, 'assigned' => false],
        ];

        foreach ($devices as $data) {
            Device::firstOrCreate(
                ['serial_number' => $data['serial_number']],
                [
                    'client_id' => $client->id,
                    'name' => $data['manufacturer'].' '.$data['model'],
                    'category' => $data['category'],
                    'type' => $data['type'],
                    'manufacturer' => $data['manufacturer'],
                    'model' => $data['model'],
                    'status' => $data['status'],
                    'assigned_user_id' => $data['assigned'] ? $assignee : null,
                    'location' => 'Sede Milano',
                ],
            );
        }

        $this->command?->info('Dispositivi d\'esempio creati: '.count($devices).'.');
    }
}
