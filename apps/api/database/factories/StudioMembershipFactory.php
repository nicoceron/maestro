<?php

namespace Database\Factories;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudioMembership>
 */
class StudioMembershipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'studio_id' => Studio::factory(),
            'user_id' => User::factory(),
            'role' => MembershipRole::Teacher,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'preferences' => [],
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (): array => ['role' => MembershipRole::Owner]);
    }
}
