<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'koha_instance_id' => config('dam.koha.instance_id'),
            'koha_patron_id' => fake()->unique()->numberBetween(1, 999999),
            'koha_userid' => fake()->unique()->userName(),
            'display_name' => fake()->name(),
            'branch_code' => 'MAIN',
            'role' => 'cataloguer',
            'is_active' => true,
            'last_authenticated_at' => now(),
        ];
    }

    public function role(string $role): static
    {
        return $this->state(['role' => $role]);
    }
}
