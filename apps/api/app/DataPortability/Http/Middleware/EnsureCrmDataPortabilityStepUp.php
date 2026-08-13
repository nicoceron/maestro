<?php

namespace App\DataPortability\Http\Middleware;

use App\DataPortability\Support\CrmDataPortabilityStepUp;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class EnsureCrmDataPortabilityStepUp
{
    public function __construct(private CrmDataPortabilityStepUp $stepUp) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->stepUp->assert($request->user(), $request->session());
        } catch (HttpException $exception) {
            return new JsonResponse([
                'message' => $exception->getMessage() === 'MFA_REQUIRED'
                    ? 'Current-session multi-factor confirmation is required.'
                    : 'Recent identity confirmation is required.',
                'code' => $exception->getMessage(),
            ], $exception->getStatusCode());
        }

        return $next($request);
    }
}
