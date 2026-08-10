<?php

namespace App\Http\Resources;

use App\Enums\MembershipRole;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HouseholdResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $role = $this->activeMembershipRole();
        $canViewPrivateHousehold = $role !== MembershipRole::Billing;

        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'notes' => $canViewPrivateHousehold ? $this->notes : null,
            'version' => $this->version,
            'members' => HouseholdMemberResource::collection($this->whenLoaded('members')),
            'guardian_relationships' => $this->whenLoaded(
                'guardianRelationships',
                fn (): array => $canViewPrivateHousehold
                    ? $this->guardianRelationships->map(fn ($relationship): array => [
                        'id' => $relationship->getKey(),
                        'guardian_person_id' => $relationship->guardian_person_id,
                        'student_person_id' => $relationship->student_person_id,
                        'relationship' => $relationship->relationship->value,
                        'is_legal_guardian' => $relationship->is_legal_guardian,
                        'is_emergency_contact' => $relationship->is_emergency_contact,
                        'is_authorized_pickup' => $relationship->is_authorized_pickup,
                        'portal_permissions' => $relationship->portal_permissions,
                    ])->all()
                    : [],
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'permissions' => [
                'edit' => $request->user()?->can('update', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
            ],
        ];
    }

    private function activeMembershipRole(): ?MembershipRole
    {
        $tenantContext = app(TenantContext::class);

        return $tenantContext->hasStudio() ? $tenantContext->membership()->role : null;
    }
}
