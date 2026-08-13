<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\People\CreatePerson;
use App\Actions\People\TransitionStudentStatus;
use App\Actions\People\UpdatePerson;
use App\Enums\MembershipRole;
use App\Enums\StudentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListPeopleRequest;
use App\Http\Requests\Api\V1\StorePersonRequest;
use App\Http\Requests\Api\V1\TransitionStudentStatusRequest;
use App\Http\Requests\Api\V1\UpdatePersonRequest;
use App\Http\Resources\PersonResource;
use App\Models\Person;
use App\Models\Studio;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class PersonController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(ListPeopleRequest $request, Studio $studio): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Person::class, $studio]);
        $filters = $request->validated();
        $search = trim((string) ($filters['q'] ?? ''));
        $isBilling = $this->tenantContext->membership()->role === MembershipRole::Billing;

        abort_if($isBilling && collect([
            'student_status',
            'staff_role',
            'instrument_id',
            'tag_id',
            'source',
        ])->contains(fn (string $filter): bool => array_key_exists($filter, $filters)), 403);

        $people = $studio->people()
            ->with($this->listRelations($isBilling))
            ->when($search !== '', function (Builder $query) use ($search, $isBilling): void {
                $needle = '%'.mb_strtolower($search).'%';
                $query->where(function (Builder $query) use ($needle, $isBilling): void {
                    $query->whereRaw('lower(first_name) like ?', [$needle])
                        ->orWhereRaw("lower(coalesce(last_name, '')) like ?", [$needle])
                        ->orWhereRaw("lower(coalesce(preferred_name, '')) like ?", [$needle]);

                    if ($isBilling) {
                        $query->orWhere(function (Builder $query) use ($needle): void {
                            $query->whereHas('householdMemberships', fn (Builder $membership): Builder => $membership
                                ->where('receives_billing', true))
                                ->where(function (Builder $query) use ($needle): void {
                                    $query->whereRaw("lower(coalesce(email, '')) like ?", [$needle])
                                        ->orWhereRaw("lower(coalesce(phone, '')) like ?", [$needle]);
                                });
                        });
                    } else {
                        $query->orWhereRaw("lower(coalesce(email, '')) like ?", [$needle])
                            ->orWhereRaw("lower(coalesce(phone, '')) like ?", [$needle]);
                    }
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['student_status'] ?? null, fn (Builder $query, string $status): Builder => $query
                ->whereHas('studentProfile', fn (Builder $profile): Builder => $profile->where('status', $status)))
            ->when($filters['staff_role'] ?? null, fn (Builder $query, string $role): Builder => $query
                ->whereHas('staffProfile', fn (Builder $profile): Builder => $profile
                    ->whereJsonContains('roles', $role)))
            ->when($filters['instrument_id'] ?? null, fn (Builder $query, string $instrument): Builder => $query
                ->whereHas('instrumentAssignments', fn (Builder $assignment): Builder => $assignment
                    ->where('instrument_id', $instrument)))
            ->when($filters['tag_id'] ?? null, fn (Builder $query, string $tag): Builder => $query
                ->whereHas('tagAssignments', fn (Builder $assignment): Builder => $assignment->where('tag_id', $tag)))
            ->when(array_key_exists('source', $filters), fn (Builder $query): Builder => $query->where('source', $filters['source']))
            ->orderByRaw("coalesce(nullif(last_name, ''), first_name)")
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return PersonResource::collection($people)->additional([
            'capabilities' => [
                'can_create' => $request->user()?->can('create', [Person::class, $studio]) ?? false,
            ],
        ]);
    }

    public function store(
        StorePersonRequest $request,
        Studio $studio,
        CreatePerson $createPerson,
    ): JsonResponse {
        $person = $createPerson->handle($request->validated(), $request->user());

        return (new PersonResource($person))
            ->additional(['message' => 'Person created.'])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Studio $studio, string $person): PersonResource
    {
        $record = $this->find($studio, $person);
        Gate::authorize('view', $record);

        return new PersonResource($record);
    }

    public function update(
        UpdatePersonRequest $request,
        Studio $studio,
        string $person,
        UpdatePerson $updatePerson,
    ): PersonResource {
        $record = $this->find($studio, $person);
        $attributes = $request->validated();
        $version = (int) $attributes['version'];
        unset($attributes['version']);

        return new PersonResource($updatePerson->handle(
            $record,
            $attributes,
            $version,
            $request->user(),
        ));
    }

    public function transitionStudentStatus(
        TransitionStudentStatusRequest $request,
        Studio $studio,
        string $person,
        TransitionStudentStatus $transition,
    ): PersonResource {
        $record = $this->find($studio, $person);
        abort_if($record->studentProfile === null, 404);
        $validated = $request->validated();
        $transition->handle(
            $record->studentProfile,
            StudentStatus::from($validated['status']),
            $validated['reason'] ?? null,
            (int) $validated['version'],
            $request->user(),
        );

        return new PersonResource($this->find($studio, $person));
    }

    private function find(Studio $studio, string $person): Person
    {
        return $studio->people()
            ->with($this->detailRelations())
            ->findOrFail($person);
    }

    /** @return list<string> */
    private function listRelations(bool $billing = false): array
    {
        if ($billing) {
            return [
                'studentProfile',
                'householdMemberships.household',
            ];
        }

        return [
            'studentProfile',
            'staffProfile',
            'instrumentAssignments.instrument',
            'tagAssignments.tag',
            'customFieldValues.definition',
            'householdMemberships.household',
        ];
    }

    /** @return list<string> */
    private function detailRelations(): array
    {
        if ($this->tenantContext->membership()->role === MembershipRole::Billing) {
            return $this->listRelations(billing: true);
        }

        return [
            ...$this->listRelations(),
            'studentStatusTransitions' => fn ($query) => $query
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(100),
        ];
    }
}
