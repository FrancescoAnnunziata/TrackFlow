<?php

namespace Database\Factories;

use App\Models\TravelRate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TravelRate>
 */
class TravelRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tipo' => strtoupper($this->faker->unique()->word()),
            'from_location' => $this->faker->address(),
            'to_location' => $this->faker->address(),
            'purpose' => 'Trasferta '.$this->faker->company(),
            'km' => $this->faker->numberBetween(50, 400),
        ];
    }
}
