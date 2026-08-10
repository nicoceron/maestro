<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Onboarding\CompleteOnboarding;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOnboardingRequest;
use App\Http\Resources\StudioResource;
use Illuminate\Http\JsonResponse;

final class OnboardingController extends Controller
{
    public function __invoke(
        StoreOnboardingRequest $request,
        CompleteOnboarding $completeOnboarding,
    ): JsonResponse {
        $studio = $completeOnboarding->handle($request->user(), $request->validated());

        return (new StudioResource($studio))->additional([
            'message' => 'Onboarding completed.',
        ])->response()->setStatusCode(200);
    }
}
