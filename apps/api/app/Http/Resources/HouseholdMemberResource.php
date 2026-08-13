<?php

namespace App\Http\Resources;

use App\Enums\MembershipRole;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HouseholdMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $person = $this->person;
        $student = $person->studentProfile;
        $role = $this->activeMembershipRole();
        $canViewPrivateProfile = in_array($role, [
            MembershipRole::Owner,
            MembershipRole::Administrator,
            MembershipRole::Office,
        ], true);
        $canViewContact = $canViewPrivateProfile || $this->receives_billing;
        $canViewStudent = $canViewPrivateProfile || $this->receives_billing;

        return [
            'id' => $this->getKey(),
            'role' => $this->role->value,
            'is_primary_contact' => $this->is_primary_contact,
            'receives_billing' => $this->receives_billing,
            'person' => [
                'id' => $person->getKey(),
                'first_name' => $person->first_name,
                'last_name' => $person->last_name,
                'preferred_name' => $person->preferred_name,
                'display_name' => $person->displayName(),
                'version' => $person->version,
                'email' => $canViewContact ? $person->email : null,
                'phone' => $canViewContact ? $person->phone : null,
                'birth_date' => $canViewPrivateProfile ? $person->birth_date?->toDateString() : null,
                'pronouns' => $canViewPrivateProfile ? $person->pronouns : null,
                'status' => $person->status->value,
                'student' => ! $canViewStudent || $student === null ? null : [
                    'id' => $student->getKey(),
                    'status' => $student->status->value,
                    'joined_on' => $student->joined_on?->toDateString(),
                    'left_on' => $student->left_on?->toDateString(),
                    'school_grade' => $canViewPrivateProfile ? $student->school_grade : null,
                ],
            ],
        ];
    }

    private function activeMembershipRole(): ?MembershipRole
    {
        $tenantContext = app(TenantContext::class);

        return $tenantContext->hasStudio() ? $tenantContext->membership()->role : null;
    }
}
