<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'giorgio.giotto@g8labs.it'],
            [
                'name' => 'Giorgio',
                'surname' => 'Giotto',
                'role' => 'admin',
                'password' => Hash::make('Prova123!'),
                'must_change_password' => false,
            ],
        );
    }
}
