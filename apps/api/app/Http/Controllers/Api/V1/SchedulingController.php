<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\CreateSchedulingRecord;
use App\Actions\Scheduling\UpdateSchedulingRecord;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSchedulingRecordRequest;
use App\Http\Requests\Api\V1\UpdateAvailabilityOverrideApprovalRequest;
use App\Http\Requests\Api\V1\UpdateSchedulingRecordRequest;
use App\Http\Resources\SchedulingResource;
use App\Models\ProgramOffering;
use App\Models\Studio;
use App\Support\Scheduling\ResolveOfferingConfiguration;
use App\Support\Scheduling\SchedulingAccess;
use App\Support\Scheduling\SchedulingRecordRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class SchedulingController extends Controller
{
    public function __construct(
        private readonly SchedulingRecordRegistry $registry,
        private readonly SchedulingAccess $access,
        private readonly ResolveOfferingConfiguration $resolver,
    ) {}

    public function index(Request $request, Studio $studio, string $resource): AnonymousResourceCollection
    {
        $class = $this->registry->modelClass($resource);
        Gate::authorize('viewAny', [$class, $studio]);
        $validated = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = $class::query()->where('studio_id', $studio->getKey())
            ->with($this->registry->relations($resource));
        $this->access->scopeVisible($query, $request->user(), $studio, $class);
        $query->when(array_key_exists('active', $validated), fn ($query) => $query->where('active', $validated['active']))
            ->orderBy('id');

        return SchedulingResource::collection($query->paginate($validated['per_page'] ?? 25)->withQueryString());
    }

    public function store(
        StoreSchedulingRecordRequest $request,
        Studio $studio,
        string $resource,
        CreateSchedulingRecord $create,
    ): JsonResponse {
        $record = $create->handle($resource, $studio, $request->validated(), $request->user());

        return (new SchedulingResource($record))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Studio $studio, string $resource, string $record): SchedulingResource
    {
        $model = $this->find($studio, $resource, $record);
        Gate::authorize('view', $model);
        $this->resolveOffering($request, $model);

        return new SchedulingResource($model);
    }

    public function update(
        UpdateSchedulingRecordRequest $request,
        Studio $studio,
        string $resource,
        string $record,
        UpdateSchedulingRecord $update,
    ): SchedulingResource {
        $model = $this->find($studio, $resource, $record);
        $attributes = $request->validated();
        $version = (int) $attributes['version'];
        unset($attributes['version']);

        return new SchedulingResource($update->handle(
            $resource,
            $studio,
            $model,
            $attributes,
            $version,
            $request->user(),
        ));
    }

    public function updateOverrideApproval(
        UpdateAvailabilityOverrideApprovalRequest $request,
        Studio $studio,
        string $record,
        UpdateSchedulingRecord $update,
    ): SchedulingResource {
        abort_unless($this->access->canManage($request->user(), $studio), 403);
        $model = $this->find($studio, 'availability-overrides', $record);
        $attributes = $request->validated();
        $version = (int) $attributes['version'];
        unset($attributes['version']);

        if ($model->kind->value === 'time_off' && $attributes['approval_status'] === 'approved') {
            $attributes['enforcement'] = 'hard';
        }

        return new SchedulingResource($update->handle(
            'availability-overrides', $studio, $model, $attributes, $version, $request->user(), true,
        ));
    }

    private function find(Studio $studio, string $resource, string $id): Model
    {
        $class = $this->registry->modelClass($resource);

        return $class::query()->where('studio_id', $studio->getKey())
            ->with($this->registry->relations($resource))->findOrFail($id);
    }

    private function resolveOffering(Request $request, Model $record): void
    {
        if (! $record instanceof ProgramOffering) {
            return;
        }

        $validated = $request->validate([
            'effective_on' => ['sometimes', 'date_format:Y-m-d'],
            'staff_profile_id' => ['sometimes', 'nullable', 'ulid'],
            'location_id' => ['sometimes', 'nullable', 'ulid'],
        ]);
        $record->loadMissing('service');
        $record->setAttribute('resolved', $this->resolver->resolve(
            $record,
            CarbonImmutable::parse($validated['effective_on'] ?? now()->toDateString()),
            $validated['staff_profile_id'] ?? null,
            $validated['location_id'] ?? null,
        ));
    }
}
