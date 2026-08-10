<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Studios\CreateStudio;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreStudioRequest;
use App\Http\Resources\StudioResource;
use App\Models\Studio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class StudioController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $studios = $request->user()
            ->studios()
            ->wherePivot('status', 'active')
            ->orderBy('studios.name')
            ->get();

        return StudioResource::collection($studios);
    }

    public function store(StoreStudioRequest $request, CreateStudio $createStudio): JsonResponse
    {
        $studio = $createStudio->handle($request->user(), $request->validated());

        return (new StudioResource($studio))
            ->additional(['message' => 'Studio created.'])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Studio $studio): StudioResource
    {
        Gate::authorize('view', $studio);

        $membershipStudio = $request->user()
            ->studios()
            ->whereKey($studio->getKey())
            ->firstOrFail();
        $studio->setRelation('pivot', $membershipStudio->pivot);

        return new StudioResource($studio);
    }
}
