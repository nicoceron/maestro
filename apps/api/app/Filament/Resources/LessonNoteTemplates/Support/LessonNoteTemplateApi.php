<?php

namespace App\Filament\Resources\LessonNoteTemplates\Support;

use App\Http\Controllers\Api\V1\LessonNoteTemplateController;
use App\Http\Requests\Api\V1\StoreLessonNoteTemplateRequest;
use App\Http\Requests\Api\V1\UpdateLessonNoteTemplateRequest;
use App\Models\LessonNoteTemplate;
use App\Models\Studio;
use App\Models\User;
use App\Support\Attendance\AttendanceAccess;
use App\Support\Attendance\AttendanceIdempotency;
use App\Support\Attendance\LessonNoteSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Gate;

final class LessonNoteTemplateApi
{
    /** @param array<string, mixed> $data */
    public function create(Studio $studio, User $actor, array $data, string $idempotencyKey): LessonNoteTemplate
    {
        Gate::forUser($actor)->authorize('create', LessonNoteTemplate::class);
        $request = $this->request(StoreLessonNoteTemplateRequest::class, 'POST', $data, $actor, $idempotencyKey);
        $resource = app(LessonNoteTemplateController::class)->store(
            $request,
            $studio,
            app(AttendanceAccess::class),
            app(LessonNoteSanitizer::class),
            app(AttendanceIdempotency::class),
        );

        return $resource->resource;
    }

    /** @param array<string, mixed> $data */
    public function update(
        Studio $studio,
        LessonNoteTemplate $template,
        User $actor,
        array $data,
        string $idempotencyKey,
    ): LessonNoteTemplate {
        abort_unless($template->studio_id === $studio->getKey(), 404);
        Gate::forUser($actor)->authorize('update', $template);
        $request = $this->request(UpdateLessonNoteTemplateRequest::class, 'PATCH', $data, $actor, $idempotencyKey);
        $resource = app(LessonNoteTemplateController::class)->update(
            $request,
            $studio,
            (string) $template->getKey(),
            app(AttendanceAccess::class),
            app(LessonNoteSanitizer::class),
            app(AttendanceIdempotency::class),
        );

        return $resource->resource;
    }

    /**
     * @template TRequest of FormRequest
     *
     * @param  class-string<TRequest>  $type
     * @param  array<string, mixed>  $data
     * @return TRequest
     */
    private function request(
        string $type,
        string $method,
        array $data,
        User $actor,
        string $idempotencyKey,
    ): FormRequest {
        $base = Request::create('/api/v1/note-templates', $method, $data);
        $base->headers->set('Idempotency-Key', $idempotencyKey);
        $base->setUserResolver(fn (): User => $actor);
        /** @var TRequest $request */
        $request = $type::createFromBase($base);
        $request->setContainer(app());
        $request->setRedirector(app(Redirector::class));
        $request->setUserResolver(fn (): User => $actor);
        $request->validateResolved();

        return $request;
    }
}
