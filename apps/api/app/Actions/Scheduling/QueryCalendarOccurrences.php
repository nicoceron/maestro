<?php

namespace App\Actions\Scheduling;

use App\Enums\EventKind;
use App\Models\EventOccurrence;
use App\Models\Studio;
use App\Models\User;
use App\Support\Scheduling\CalendarAccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class QueryCalendarOccurrences
{
    private const EMBEDDED_RESULT_LIMIT = 1000;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, EventOccurrence>
     */
    public function handle(Studio $studio, User $actor, array $filters, ?CalendarAccessContext $context = null): Collection
    {
        $results = $this->query($studio, $actor, $filters, $context)
            ->limit(self::EMBEDDED_RESULT_LIMIT + 1)
            ->get();

        if ($results->count() > self::EMBEDDED_RESULT_LIMIT) {
            throw ValidationException::withMessages([
                'to' => 'This calendar view contains more than 1,000 events. Narrow the visible range or add filters; no events were omitted.',
            ]);
        }

        return $results;
    }

    /** @param array<string, mixed> $filters */
    public function paginate(Studio $studio, User $actor, array $filters, ?CalendarAccessContext $context = null): CursorPaginator
    {
        $pageSize = min(500, max(1, (int) ($filters['page_size'] ?? 200)));

        return $this->query($studio, $actor, $filters, $context)->cursorPaginate($pageSize)->withQueryString();
    }

    /** @param array<string, mixed> $filters @return Builder<EventOccurrence> */
    private function query(Studio $studio, User $actor, array $filters, ?CalendarAccessContext $context): Builder
    {
        Gate::forUser($actor)->authorize('viewAny', [EventOccurrence::class, $studio]);
        $filters['q'] = array_key_exists('q', $filters) ? Str::squish((string) $filters['q']) : null;
        $tenantExists = fn (string $table) => Rule::exists($table, 'id')->where('studio_id', $studio->getKey());
        $validated = Validator::make($filters, [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'q' => ['nullable', 'string', 'max:160'],
            'holds' => ['sometimes', Rule::in(['include', 'exclude', 'only'])],
            'teacher_ids' => ['sometimes', 'array', 'max:20'],
            'teacher_ids.*' => ['ulid', 'distinct', $tenantExists('staff_profiles')],
            'room_ids' => ['sometimes', 'array', 'max:20'],
            'room_ids.*' => ['ulid', 'distinct', $tenantExists('rooms')],
            'kinds' => ['sometimes', 'array', 'max:20'],
            'kinds.*' => ['distinct', Rule::enum(EventKind::class)],
            'page_size' => ['sometimes', 'integer', 'between:1,500'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ])->validate();
        $from = CarbonImmutable::parse($validated['from']);
        $to = CarbonImmutable::parse($validated['to']);

        if ($from->diffInDays($to) > 93) {
            throw ValidationException::withMessages(['to' => 'Calendar queries are limited to 93 days.']);
        }

        $context ??= CalendarAccessContext::for($studio, $actor);

        if (! $context->canManage() && ($validated['room_ids'] ?? []) !== []) {
            throw ValidationException::withMessages(['room_ids' => 'Room filters are available only to scheduling managers.']);
        }

        if (! $context->canManage() && array_diff($validated['teacher_ids'] ?? [], $context->staffProfileIds->all()) !== []) {
            throw ValidationException::withMessages(['teacher_ids' => 'Teacher filters may contain only your own assigned staff profiles.']);
        }

        $escapedSearch = addcslashes(mb_strtolower((string) ($validated['q'] ?? '')), '\\%_');
        $query = EventOccurrence::query()
            ->with([
                'series',
                'location',
                'teachers' => fn ($query) => $query->where('status', 'assigned'),
                'rooms' => fn ($query) => $query->where('status', 'assigned'),
                'equipmentReservations' => fn ($query) => $query->where('status', 'assigned'),
                'participants' => fn ($query) => $query->whereIn('status', ['reserved', 'confirmed']),
            ])
            ->where('studio_id', $studio->getKey())
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->when($escapedSearch !== '', fn ($query) => $query->whereRaw("LOWER(title) LIKE ? ESCAPE '\\'", ['%'.$escapedSearch.'%']))
            ->when(($validated['holds'] ?? 'include') === 'exclude', fn ($query) => $query->whereNull('hold_expires_at'))
            ->when(($validated['holds'] ?? null) === 'only', fn ($query) => $query->whereNotNull('hold_expires_at'))
            ->when($validated['teacher_ids'] ?? null, fn ($query, $ids) => $query->whereHas(
                'teachers', fn ($query) => $query->where('status', 'assigned')->whereIn('staff_profile_id', $ids),
            ))
            ->when($validated['room_ids'] ?? null, fn ($query, $ids) => $query->whereHas(
                'rooms', fn ($query) => $query->where('status', 'assigned')->whereIn('room_id', $ids),
            ))
            ->when($validated['kinds'] ?? null, fn ($query, $kinds) => $query->whereIn('kind', $kinds));

        if (! $context->canManage() && ! $context->isBilling()) {
            $query->whereHas('teachers', fn ($query) => $query
                ->where('status', 'assigned')
                ->whereIn('staff_profile_id', $context->staffProfileIds));
        }

        return $query->orderBy('starts_at')->orderBy('id');
    }
}
