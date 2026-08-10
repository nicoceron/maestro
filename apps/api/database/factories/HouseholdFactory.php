<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Studio;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Household> */
class HouseholdFactory extends Factory
{
    public function definition(): array
    {
        return [
            'studio_id' => Studio::factory(),
            'name' => fake()->lastName().' household',
            'notes' => null,
            'version' => 1,
        ];
    }
}
