<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        User::factory()->admin()->create([
            'name' => 'Admin',
            'surname' => 'Test',
            'email' => 'admin@example.com',
        ]);

        $acmeClient = Client::create([
            'name' => 'Acme S.p.A.',
        ]);

        User::factory()->create([
            'name' => 'Cliente',
            'surname' => 'Acme',
            'email' => 'client@example.com',
            'role' => 'client',
            'client_id' => $acmeClient->id,
        ]);
    }
}
