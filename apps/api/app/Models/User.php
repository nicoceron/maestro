<?php

namespace App\Models;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasName, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @return BelongsToMany<Studio, $this> */
    public function studios(): BelongsToMany
    {
        return $this->belongsToMany(Studio::class, 'studio_memberships')
            ->using(StudioMembership::class)
            ->withPivot(['id', 'role', 'status', 'job_title', 'joined_at', 'last_active_at', 'preferences'])
            ->withTimestamps();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->managedStudiosQuery()->exists();
    }

    /** @return Collection<int, Studio> */
    public function getTenants(Panel $panel): Collection
    {
        return $this->managedStudiosQuery()->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Studio
            && $this->managedStudiosQuery()->whereKey($tenant->getKey())->exists();
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    /** @return BelongsToMany<Studio, $this> */
    private function managedStudiosQuery(): BelongsToMany
    {
        return $this->studios()
            ->wherePivot('status', MembershipStatus::Active->value)
            ->wherePivotIn('role', MembershipRole::managementValues())
            ->orderBy('studios.name');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
