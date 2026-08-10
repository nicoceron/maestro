<?php

namespace App\Actions\Studios;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\StudioStatus;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateStudio
{
    /**
     * @param  array{name: string, slug?: string|null, timezone?: string, locale?: string, currency?: string, week_starts_on?: int}  $attributes
     */
    public function handle(User $owner, array $attributes): Studio
    {
        return DB::transaction(function () use ($owner, $attributes): Studio {
            $studio = Studio::query()->create([
                'name' => $attributes['name'],
                'slug' => $attributes['slug'] ?? $this->uniqueSlug($attributes['name']),
                'status' => StudioStatus::Trial,
                'timezone' => $attributes['timezone'] ?? 'UTC',
                'locale' => $attributes['locale'] ?? 'en',
                'currency' => strtoupper($attributes['currency'] ?? 'USD'),
                'week_starts_on' => $attributes['week_starts_on'] ?? 1,
                'settings' => [],
                'trial_ends_at' => now()->addDays(30),
            ]);

            $membership = StudioMembership::query()->create([
                'studio_id' => $studio->getKey(),
                'user_id' => $owner->getKey(),
                'role' => MembershipRole::Owner,
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
                'preferences' => [],
            ]);

            $studio->setRelation('pivot', $membership);

            return $studio;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'studio';
        $candidate = $base;
        $suffix = 2;

        while (Studio::query()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
