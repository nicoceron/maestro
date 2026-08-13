<?php

namespace Database\Seeders;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\StudioStatus;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Illuminate\Database\Seeder;

final class BrowserJourneySeeder extends Seeder
{
    public const EMAIL = 'browser.owner@example.test';

    public const PASSWORD = 'Browser quality passphrase 2026!';

    public const STUDIO_SLUG = 'browser-studio';

    public function run(): void
    {
        $owner = new User;
        $owner->forceFill([
            'name' => 'Browser Owner',
            'email' => self::EMAIL,
            'email_verified_at' => now(),
            'password' => self::PASSWORD,
        ])->save();

        $studio = Studio::query()->create([
            'name' => 'Browser Studio',
            'slug' => self::STUDIO_SLUG,
            'status' => StudioStatus::Active,
            'timezone' => 'America/Bogota',
            'locale' => 'en',
            'currency' => 'USD',
            'week_starts_on' => 1,
            'settings' => [],
        ]);

        StudioMembership::query()->create([
            'studio_id' => $studio->getKey(),
            'user_id' => $owner->getKey(),
            'role' => MembershipRole::Owner,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'preferences' => [],
        ]);
    }
}
