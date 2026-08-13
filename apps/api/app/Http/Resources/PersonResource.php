<?php

namespace App\Http\Resources;

use App\Enums\MembershipRole;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PersonResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $role = $this->membershipRole();
        $canManage = in_array($role, [
            MembershipRole::Owner,
            MembershipRole::Administrator,
            MembershipRole::Office,
        ], true);
        $isPayer = $this->relationLoaded('householdMemberships')
            && $this->householdMemberships->contains('receives_billing', true);
        $canViewContact = $canManage || ($role === MembershipRole::Billing && $isPayer);
        $canViewStudent = $canManage || ($role === MembershipRole::Billing && $isPayer);
        $student = $this->relationLoaded('studentProfile') ? $this->studentProfile : null;
        $staff = $this->relationLoaded('staffProfile') ? $this->staffProfile : null;

        return [
            'id' => $this->getKey(),
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'preferred_name' => $this->preferred_name,
            'display_name' => $this->displayName(),
            'email' => $canViewContact ? $this->email : null,
            'phone' => $canViewContact ? $this->phone : null,
            'birth_date' => $canManage ? $this->birth_date?->toDateString() : null,
            'pronouns' => $canManage ? $this->pronouns : null,
            'status' => $this->status->value,
            'version' => $this->version,
            'source' => $canManage ? $this->source : null,
            'external_reference' => $canManage ? $this->external_reference : null,
            'preferred_locale' => $canManage ? $this->preferred_locale : null,
            'student' => ! $canViewStudent || $student === null ? null : [
                'id' => $student->getKey(),
                'status' => $student->status->value,
                'joined_on' => $student->joined_on?->toDateString(),
                'left_on' => $student->left_on?->toDateString(),
                'school_grade' => $canManage ? $student->school_grade : null,
                'learning_preferences' => $canManage ? $student->learning_preferences : [],
                'lead_source' => $canManage ? $student->lead_source : null,
                'trial_started_on' => $canManage ? $student->trial_started_on?->toDateString() : null,
                'waitlisted_on' => $canManage ? $student->waitlisted_on?->toDateString() : null,
                'status_changed_at' => $student->status_changed_at?->toIso8601String(),
            ],
            'staff' => ! $canManage || $staff === null ? null : [
                'id' => $staff->getKey(),
                'roles' => $staff->roles,
                'status' => $staff->status->value,
                'employment_type' => $staff->employment_type?->value,
                'bio' => $staff->bio,
                'hire_on' => $staff->hire_on?->toDateString(),
                'left_on' => $staff->left_on?->toDateString(),
                'can_substitute' => $staff->can_substitute,
            ],
            'instruments' => $canManage ? $this->instrumentData() : [],
            'tags' => $canManage ? $this->tagData() : [],
            'custom_fields' => $canManage ? $this->customFieldData() : [],
            'households' => $this->householdData($canManage, $isPayer),
            'student_status_history' => $canManage ? $this->statusHistory() : [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'permissions' => [
                'edit' => $request->user()?->can('update', $this->resource) ?? false,
                'archive' => $request->user()?->can('delete', $this->resource) ?? false,
                'transition_student' => $student !== null
                    && ($request->user()?->can('update', $this->resource) ?? false),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function instrumentData(): array
    {
        if (! $this->relationLoaded('instrumentAssignments')) {
            return [];
        }

        return $this->instrumentAssignments->map(fn ($assignment): array => [
            'id' => $assignment->instrument->getKey(),
            'name' => $assignment->instrument->name,
            'relationship' => $assignment->relationship->value,
            'proficiency' => $assignment->proficiency?->value,
            'is_primary' => $assignment->is_primary,
            'years_experience' => $assignment->years_experience,
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function tagData(): array
    {
        if (! $this->relationLoaded('tagAssignments')) {
            return [];
        }

        return $this->tagAssignments->map(fn ($assignment): array => [
            'id' => $assignment->tag->getKey(),
            'name' => $assignment->tag->name,
            'color' => $assignment->tag->color,
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function customFieldData(): array
    {
        if (! $this->relationLoaded('customFieldValues')) {
            return [];
        }

        return $this->customFieldValues->map(fn ($field): array => [
            'definition_id' => $field->definition->getKey(),
            'key' => $field->definition->key,
            'name' => $field->definition->name,
            'type' => $field->definition->type->value,
            'value' => $field->value['value'] ?? null,
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function householdData(bool $canManage, bool $isPayer): array
    {
        if (! $this->relationLoaded('householdMemberships') || (! $canManage && ! $isPayer)) {
            return [];
        }

        return $this->householdMemberships
            ->filter(fn ($membership): bool => $canManage || $membership->receives_billing)
            ->map(fn ($membership): array => [
                'id' => $membership->household->getKey(),
                'name' => $membership->household->name,
                'role' => $membership->role->value,
                'is_primary_contact' => $membership->is_primary_contact,
                'receives_billing' => $membership->receives_billing,
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function statusHistory(): array
    {
        if (! $this->relationLoaded('studentStatusTransitions')) {
            return [];
        }

        return $this->studentStatusTransitions->map(fn ($transition): array => [
            'id' => $transition->getKey(),
            'previous_status' => $transition->previous_status?->value,
            'new_status' => $transition->new_status->value,
            'reason' => $transition->reason,
            'occurred_at' => $transition->occurred_at->toIso8601String(),
        ])->values()->all();
    }

    private function membershipRole(): ?MembershipRole
    {
        $context = app(TenantContext::class);

        return $context->hasStudio() ? $context->membership()->role : null;
    }
}
