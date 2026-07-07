<?php

namespace Database\Factories;

use App\Enums\ReimbursementStatus;
use App\Enums\ReimbursementType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Reimbursement>
 */
class ReimbursementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_id' => null,
            'type' => ReimbursementType::Manual,
            'status' => ReimbursementStatus::Pending,
            'date' => now()->toDateString(),
            'amount' => $this->faker->randomFloat(2, 10, 500),
            'notes' => $this->faker->sentence(),
            'attachments' => null,
        ];
    }
}
