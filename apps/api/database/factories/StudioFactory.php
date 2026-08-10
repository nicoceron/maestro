<?php

namespace Database\Factories;

use App\Enums\StudioStatus;
use App\Models\Studio;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Studio>
 */
class StudioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' Music';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'status' => StudioStatus::Active,
            'timezone' => fake()->timezone(),
            'locale' => 'en',
            'currency' => 'USD',
            'week_starts_on' => 1,
            'settings' => [],
        ];
    }
}
