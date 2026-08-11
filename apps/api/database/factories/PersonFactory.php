<?php

namespace Database\Factories;

use App\Enums\PersonStatus;
use App\Models\Person;
use App\Models\Studio;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Person> */
class PersonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'studio_id' => Studio::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'preferred_name' => null,
            'email' => fake()->safeEmail(),
            'phone' => fake()->e164PhoneNumber(),
            'birth_date' => null,
            'pronouns' => null,
            'status' => PersonStatus::Active,
        ];
    }
}
