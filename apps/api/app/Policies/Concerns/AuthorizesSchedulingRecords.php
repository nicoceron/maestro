<?php

namespace App\Policies\Concerns;

use App\Models\Studio;
use App\Models\User;
use App\Support\Scheduling\SchedulingAccess;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesSchedulingRecords
{
    abstract protected function modelClass(): string;

    public function viewAny(User $user, Studio $studio): bool
    {
        return app(SchedulingAccess::class)->canViewAny($user, $studio, $this->modelClass());
    }

    public function view(User $user, Model $record): bool
    {
        return app(SchedulingAccess::class)->canView($user, $record);
    }

    public function create(User $user, Studio $studio): bool
    {
        return app(SchedulingAccess::class)->canCreate($user, $studio, $this->modelClass());
    }

    public function update(User $user, Model $record): bool
    {
        return app(SchedulingAccess::class)->canUpdate($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }
}
