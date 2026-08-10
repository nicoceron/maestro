<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterPublicUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PublicRegistrationRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class PublicRegistrationController extends Controller
{
    public function __invoke(
        PublicRegistrationRequest $request,
        RegisterPublicUser $register,
    ): JsonResponse {
        $register->handle($request->validated());

        return response()->json([
            'message' => 'If registration can be completed, check your email for next steps.',
        ], Response::HTTP_ACCEPTED);
    }
}
