<?php

namespace App\Models;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Notifications\QueuedResetPasswordNotification;
use App\Notifications\QueuedVerifyEmailNotification;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Normalizer;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasName, HasTenants, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    public static function normalizeEmail(string $email): string
    {
        $trimmed = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $email) ?? $email;
        $normalized = Normalizer::normalize($trimmed, Normalizer::FORM_KC);

        return Str::lower($normalized === false ? $trimmed : $normalized);
    }

    public function setEmailAttribute(string $email): void
    {
        $this->attributes['email'] = self::normalizeEmail($email);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new QueuedResetPasswordNotification($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmailNotification);
    }

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
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_setup_started_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
