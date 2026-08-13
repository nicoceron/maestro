<?php

namespace App\Support\Attendance;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\EventOccurrence;
use App\Models\EventOccurrenceParticipant;
use App\Models\GuardianRelationship;
use App\Models\LessonNote;
use App\Models\Person;
use App\Models\StaffAccountLink;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;

final class AttendanceAccess
{
    public function canManage(User $user, Studio|string $studio): bool
    {
        return $this->membership($user, $studio)
            ->whereIn('role', [MembershipRole::Owner, MembershipRole::Administrator, MembershipRole::Office])
            ->exists();
    }

    public function isAssignedTeacher(User $user, EventOccurrence $occurrence): bool
    {
        return $occurrence->teachers()
            ->where('status', 'assigned')
            ->whereIn('staff_profile_id', $this->staffIds($user, (string) $occurrence->studio_id))
            ->exists();
    }

    public function canRecordAttendance(User $user, EventOccurrence $occurrence): bool
    {
        return $this->canManage($user, (string) $occurrence->studio_id)
            || $this->isAssignedTeacher($user, $occurrence);
    }

    public function canReadAllAttendance(User $user, EventOccurrence $occurrence): bool
    {
        return $this->canRecordAttendance($user, $occurrence)
            || $this->isBilling($user, (string) $occurrence->studio_id);
    }

    public function isBilling(User $user, Studio|string $studio): bool
    {
        return $this->membership($user, $studio)->where('role', MembershipRole::Billing)->exists();
    }

    public function canViewParticipant(User $user, EventOccurrenceParticipant $participant): bool
    {
        if ($this->canRecordAttendance($user, $participant->occurrence)) {
            return true;
        }

        $person = $participant->person;
        if ($person?->user_id === $user->getAuthIdentifier()) {
            return ! $this->isBilling($user, (string) $participant->studio_id);
        }

        return GuardianRelationship::query()
            ->where('studio_id', $participant->studio_id)
            ->where('student_person_id', $participant->person_id)
            ->whereJsonContains('portal_permissions', 'attendance')
            ->whereHas('household', fn ($query) => $query->whereNull('deleted_at'))
            ->whereHas('student', fn ($query) => $query->where('status', 'active'))
            ->whereHas('guardian', fn ($query) => $query
                ->where('user_id', $user->getAuthIdentifier())->where('status', 'active'))
            ->exists();
    }

    /** @return list<string> */
    public function accessibleStudentPersonIds(User $user, string $studioId): array
    {
        if ($this->isBilling($user, $studioId)) {
            return [];
        }

        return Person::query()->where('studio_id', $studioId)
            ->where('user_id', $user->getAuthIdentifier())->where('status', 'active')
            ->pluck('id')->all();
    }

    /** @return list<string> */
    public function accessibleGuardianStudentPersonIds(User $user, string $studioId, string $permission): array
    {
        return GuardianRelationship::query()
            ->where('studio_id', $studioId)
            ->whereJsonContains('portal_permissions', $permission)
            ->whereHas('household', fn ($query) => $query->whereNull('deleted_at'))
            ->whereHas('student', fn ($query) => $query->where('status', 'active'))
            ->whereHas('guardian', fn ($query) => $query
                ->where('user_id', $user->getAuthIdentifier())->where('status', 'active'))
            ->pluck('student_person_id')->all();
    }

    public function canViewPrivateCompliance(User $user, string $studioId): bool
    {
        return $this->isOwnerOrAdministrator($user, $studioId);
    }

    public function canCreateNote(User $user, EventOccurrence $occurrence): bool
    {
        return $this->canManage($user, (string) $occurrence->studio_id)
            || $this->isAssignedTeacher($user, $occurrence);
    }

    public function canUseNoteTemplates(User $user, Studio|string $studio): bool
    {
        return $this->membership($user, $studio)
            ->whereIn('role', [MembershipRole::Owner, MembershipRole::Administrator, MembershipRole::Office, MembershipRole::Teacher])
            ->exists();
    }

    public function canViewNote(User $user, LessonNote $note): bool
    {
        if ($note->audience->value === 'author_private') {
            return (int) $note->author_user_id === (int) $user->getAuthIdentifier()
                || $this->isOwnerOrAdministrator($user, (string) $note->studio_id);
        }

        if ($this->canCreateNote($user, $note->occurrence)) {
            return true;
        }

        if ($note->audience->value === 'student') {
            return Person::query()
                ->where('studio_id', $note->studio_id)
                ->where('user_id', $user->getAuthIdentifier())
                ->where('status', 'active')
                ->whereIn('id', $this->notePersonIds($note))
                ->exists() && ! $this->isBilling($user, (string) $note->studio_id);
        }

        return GuardianRelationship::query()
            ->where('studio_id', $note->studio_id)
            ->whereIn('student_person_id', $this->notePersonIds($note))
            ->whereJsonContains('portal_permissions', 'learning')
            ->whereHas('household', fn ($query) => $query->whereNull('deleted_at'))
            ->whereHas('student', fn ($query) => $query->where('status', 'active'))
            ->whereHas('guardian', fn ($query) => $query
                ->where('user_id', $user->getAuthIdentifier())->where('status', 'active'))
            ->exists();
    }

    public function canReviseNote(User $user, LessonNote $note): bool
    {
        if ((int) $note->author_user_id === (int) $user->getAuthIdentifier()) {
            return true;
        }

        return $note->audience->value === 'author_private'
            ? $this->isOwnerOrAdministrator($user, (string) $note->studio_id)
            : $this->canManage($user, (string) $note->studio_id);
    }

    public function canDeliverNote(User $user, LessonNote $note): bool
    {
        return $note->audience->value !== 'author_private' && $this->canReviseNote($user, $note);
    }

    /** @return list<int> */
    public function eligibleRecipientUserIds(LessonNote $note): array
    {
        $personIds = $this->notePersonIds($note);
        $activeUserIds = StudioMembership::query()
            ->where('studio_id', $note->studio_id)
            ->where('status', MembershipStatus::Active)
            ->where('role', '<>', MembershipRole::Billing)
            ->pluck('user_id');

        $ids = $note->audience->value === 'student'
            ? Person::query()->where('studio_id', $note->studio_id)
                ->whereIn('id', $personIds)->where('status', 'active')
                ->whereIn('user_id', $activeUserIds)->pluck('user_id')
            : GuardianRelationship::query()
                ->where('studio_id', $note->studio_id)
                ->whereIn('student_person_id', $personIds)
                ->whereJsonContains('portal_permissions', 'learning')
                ->whereHas('household', fn ($query) => $query->whereNull('deleted_at'))
                ->whereHas('student', fn ($query) => $query->where('status', 'active'))
                ->whereHas('guardian', fn ($query) => $query
                    ->where('status', 'active')->whereIn('user_id', $activeUserIds))
                ->with('guardian:id,user_id')->get()->pluck('guardian.user_id');

        return $ids->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
    }

    public function staffProfileId(User $user, string $studioId): ?string
    {
        return StaffAccountLink::query()
            ->where('studio_id', $studioId)
            ->where('active', true)
            ->whereIn('studio_membership_id', $this->membership($user, $studioId)->select('id'))
            ->value('staff_profile_id');
    }

    private function staffIds(User $user, string $studioId): array
    {
        return StaffAccountLink::query()
            ->where('studio_id', $studioId)
            ->where('active', true)
            ->whereIn('studio_membership_id', $this->membership($user, $studioId)->select('id'))
            ->pluck('staff_profile_id')->all();
    }

    private function isOwnerOrAdministrator(User $user, string $studioId): bool
    {
        return $this->membership($user, $studioId)
            ->whereIn('role', [MembershipRole::Owner, MembershipRole::Administrator])
            ->exists();
    }

    /** @return list<string> */
    private function notePersonIds(LessonNote $note): array
    {
        if ($note->scope->value === 'participant') {
            return $note->person_id === null ? [] : [$note->person_id];
        }

        return EventOccurrenceParticipant::query()
            ->where('studio_id', $note->studio_id)
            ->where('event_occurrence_id', $note->event_occurrence_id)
            ->whereIn('status', ['reserved', 'confirmed'])
            ->pluck('person_id')->all();
    }

    private function membership(User $user, Studio|string $studio)
    {
        return StudioMembership::query()
            ->where('studio_id', $studio instanceof Studio ? $studio->getKey() : $studio)
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', MembershipStatus::Active);
    }
}
