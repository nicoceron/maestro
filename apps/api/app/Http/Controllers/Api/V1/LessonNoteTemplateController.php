<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLessonNoteTemplateRequest;
use App\Http\Requests\Api\V1\UpdateLessonNoteTemplateRequest;
use App\Http\Resources\LessonNoteTemplateResource;
use App\Models\LessonNoteTemplate;
use App\Models\LessonNoteTemplateRevision;
use App\Models\Studio;
use App\Support\Attendance\AttendanceAccess;
use App\Support\Attendance\AttendanceIdempotency;
use App\Support\Attendance\LessonNoteSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LessonNoteTemplateController extends Controller
{
    public function index(Request $request, Studio $studio, AttendanceAccess $access): AnonymousResourceCollection
    {
        abort_unless($access->canUseNoteTemplates($request->user(), $studio), 403);
        $query = LessonNoteTemplate::query()->where('studio_id', $studio->getKey());
        if (! $access->canManage($request->user(), $studio)) {
            $query->where('active', true);
        }

        return LessonNoteTemplateResource::collection($query->orderBy('normalized_name')->orderBy('id')->paginate(100));
    }

    public function store(StoreLessonNoteTemplateRequest $request, Studio $studio, AttendanceAccess $access, LessonNoteSanitizer $sanitizer, AttendanceIdempotency $idempotency): LessonNoteTemplateResource
    {
        abort_unless($access->canManage($request->user(), $studio), 403);
        $attributes = $request->validated();
        $body = $sanitizer->sanitize($attributes['body_html']);
        $this->assertReadable($body);
        $key = $idempotency->key($request->header('Idempotency-Key'));
        $operation = 'lesson-note-template.create';
        $hash = $idempotency->hash((string) $studio->getKey(), $request->user(), $operation, [...$attributes, 'body_html' => $body]);

        return new LessonNoteTemplateResource(DB::transaction(function () use ($studio, $attributes, $body, $request, $idempotency, $key, $operation, $hash): LessonNoteTemplate {
            $replay = $idempotency->replay((string) $studio->getKey(), $request->user(), $key, $operation, $hash);
            if ($replay !== null) {
                return (new LessonNoteTemplate)->newFromBuilder($replay->result_projection['lesson_note_template']);
            }
            $lockedStudio = Studio::query()->lockForUpdate()->findOrFail($studio->getKey());
            abort_unless(app(AttendanceAccess::class)->canManage($request->user(), $lockedStudio), 403);
            $this->assertUnique($studio, $attributes['name']);
            $template = LessonNoteTemplate::query()->create([
                'studio_id' => $studio->getKey(), 'name' => $attributes['name'],
                'audience' => $attributes['audience'], 'body_html' => $body,
            ]);
            $template->refresh();
            LessonNoteTemplateRevision::query()->create([
                'studio_id' => $studio->getKey(), 'lesson_note_template_id' => $template->getKey(),
                'revision' => 1, 'name' => $template->name, 'audience' => $template->audience->value,
                'body_html' => $template->body_html, 'active' => true, 'reason' => 'Template created.',
                'actor_id' => $request->user()->getAuthIdentifier(), 'created_at' => now(),
            ]);
            $idempotency->store((string) $studio->getKey(), $request->user(), $key, $operation, $hash, [
                'lesson_note_template' => $template->getAttributes(),
            ]);

            return $template;
        }, attempts: 3));
    }

    public function update(UpdateLessonNoteTemplateRequest $request, Studio $studio, string $template, AttendanceAccess $access, LessonNoteSanitizer $sanitizer, AttendanceIdempotency $idempotency): LessonNoteTemplateResource
    {
        $record = LessonNoteTemplate::query()->where('studio_id', $studio->getKey())->findOrFail($template);
        abort_unless($access->canManage($request->user(), $studio), 403);

        $key = $idempotency->key($request->header('Idempotency-Key'));
        $operation = 'lesson-note-template.update';
        $hash = $idempotency->hash((string) $studio->getKey(), $request->user(), $operation, [
            'template_id' => $record->getKey(), 'attributes' => $request->validated(),
        ]);

        return new LessonNoteTemplateResource(DB::transaction(function () use ($request, $record, $studio, $sanitizer, $idempotency, $key, $operation, $hash): LessonNoteTemplate {
            $replay = $idempotency->replay((string) $studio->getKey(), $request->user(), $key, $operation, $hash);
            if ($replay !== null) {
                return (new LessonNoteTemplate)->newFromBuilder($replay->result_projection['lesson_note_template']);
            }
            $lockedStudio = Studio::query()->lockForUpdate()->findOrFail($studio->getKey());
            abort_unless(app(AttendanceAccess::class)->canManage($request->user(), $lockedStudio), 403);
            $locked = LessonNoteTemplate::query()->lockForUpdate()->findOrFail($record->getKey());
            $attributes = $request->validated();
            if ($locked->version !== (int) $attributes['version']) {
                throw ValidationException::withMessages(['version' => 'The template changed after it was opened.']);
            }
            if (isset($attributes['name'])) {
                $this->assertUnique($studio, $attributes['name'], $locked->getKey());
                $locked->name = $attributes['name'];
            }
            if (isset($attributes['body_html'])) {
                $body = $sanitizer->sanitize($attributes['body_html']);
                $this->assertReadable($body);
                $locked->body_html = $body;
            }
            foreach (['audience', 'active'] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $locked->{$field} = $attributes[$field];
                }
            }
            LessonNoteTemplateRevision::query()->create([
                'studio_id' => $studio->getKey(), 'lesson_note_template_id' => $locked->getKey(),
                'revision' => $locked->version + 1, 'name' => $locked->name,
                'audience' => is_string($locked->audience) ? $locked->audience : $locked->audience->value,
                'body_html' => $locked->body_html, 'active' => $locked->active,
                'reason' => Str::squish($attributes['reason']),
                'actor_id' => $request->user()->getAuthIdentifier(), 'created_at' => now(),
            ]);
            $locked->version++;
            $locked->save();
            $idempotency->store((string) $studio->getKey(), $request->user(), $key, $operation, $hash, [
                'lesson_note_template' => $locked->getAttributes(),
            ]);

            return $locked;
        }, attempts: 3));
    }

    private function assertUnique(Studio $studio, string $name, ?string $ignore = null): void
    {
        $query = LessonNoteTemplate::query()->where('studio_id', $studio->getKey())
            ->where('normalized_name', mb_strtolower(Str::squish($name)));
        if ($ignore !== null) {
            $query->whereKeyNot($ignore);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => 'A template with this name already exists.']);
        }
    }

    private function assertReadable(string $body): void
    {
        if (trim(strip_tags($body)) === '') {
            throw ValidationException::withMessages(['body_html' => 'The template body must contain readable text.']);
        }
    }
}
