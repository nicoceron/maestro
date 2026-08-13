<?php

namespace App\Policies;

use App\Models\LessonNoteTemplate;
use App\Models\Studio;
use App\Models\User;
use App\Support\Attendance\AttendanceAccess;

final class LessonNoteTemplatePolicy
{
    public function __construct(private readonly AttendanceAccess $access) {}

    public function viewAny(User $user, Studio $studio): bool
    {
        return $this->access->canUseNoteTemplates($user, $studio);
    }

    public function view(User $user, LessonNoteTemplate $template): bool
    {
        return $this->access->canUseNoteTemplates($user, (string) $template->studio_id);
    }

    public function create(User $user, Studio $studio): bool
    {
        return $this->access->canManage($user, $studio);
    }

    public function update(User $user, LessonNoteTemplate $template): bool
    {
        return $this->access->canManage($user, (string) $template->studio_id);
    }
}
