<?php

namespace App\Policies;

use App\Models\LessonNote;
use App\Models\User;
use App\Support\Attendance\AttendanceAccess;

final class LessonNotePolicy
{
    public function __construct(private readonly AttendanceAccess $access) {}

    public function view(User $user, LessonNote $note): bool
    {
        return $this->access->canViewNote($user, $note);
    }

    public function update(User $user, LessonNote $note): bool
    {
        return $this->access->canReviseNote($user, $note);
    }

    public function deliver(User $user, LessonNote $note): bool
    {
        return $this->access->canDeliverNote($user, $note);
    }
}
