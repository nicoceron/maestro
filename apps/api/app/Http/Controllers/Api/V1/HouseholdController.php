<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\People\CreateHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreHouseholdRequest;
use App\Http\Resources\HouseholdResource;
use App\Models\Household;
use App\Models\Studio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class HouseholdController extends Controller
{
    public function index(Request $request, Studio $studio): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Household::class, $studio]);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($validated['q'] ?? ''));

        $households = $studio->households()
            ->with(['members.person.studentProfile', 'guardianRelationships'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $needle = '%'.mb_strtolower($search).'%';

                $query->where(function (Builder $query) use ($needle): void {
                    $query
                        ->whereRaw('lower(name) like ?', [$needle])
                        ->orWhereHas('members.person', function (Builder $query) use ($needle): void {
                            $query->whereRaw(
                                "lower(first_name || ' ' || coalesce(last_name, '')) like ?",
                                [$needle],
                            );
                        });
                });
            })
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();

        return HouseholdResource::collection($households);
    }

    public function store(
        StoreHouseholdRequest $request,
        Studio $studio,
        CreateHousehold $createHousehold,
    ): JsonResponse {
        $household = $createHousehold->handle($request->validated());

        return (new HouseholdResource($household))
            ->additional(['message' => 'Household created.'])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Studio $studio, string $household): HouseholdResource
    {
        $record = $studio->households()
            ->with(['members.person.studentProfile', 'guardianRelationships'])
            ->findOrFail($household);

        Gate::authorize('view', $record);

        return new HouseholdResource($record);
    }
}
