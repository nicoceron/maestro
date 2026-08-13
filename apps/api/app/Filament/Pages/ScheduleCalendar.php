<?php

namespace App\Filament\Pages;

use App\Actions\Scheduling\CommitCreateEventSeries;
use App\Actions\Scheduling\CommitEventEnrollment;
use App\Actions\Scheduling\CommitScheduleChange;
use App\Actions\Scheduling\ManageSchedulingHold;
use App\Actions\Scheduling\PreviewCreateEventSeries;
use App\Actions\Scheduling\PreviewEventEnrollment;
use App\Actions\Scheduling\PreviewScheduleChange;
use App\Actions\Scheduling\QueryCalendarOccurrences;
use App\Actions\Scheduling\SearchAvailableSlots;
use App\Enums\EventEnrollmentStatus;
use App\Enums\EventKind;
use App\Enums\EventParticipantStatus;
use App\Enums\EventVisibility;
use App\Enums\ScheduleEditScope;
use App\Enums\SchedulePreviewStatus;
use App\Exceptions\SchedulingConflict;
use App\Models\Equipment;
use App\Models\EventEnrollment;
use App\Models\EventOccurrence;
use App\Models\EventSeries;
use App\Models\Location;
use App\Models\Person;
use App\Models\Room;
use App\Models\ScheduleChangePreview;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\Studio;
use App\Models\User;
use App\Support\Scheduling\CalendarAccessContext;
use App\Support\Scheduling\RecurrenceSetTransformer;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;

final class ScheduleCalendar extends Page
{
    /** @var list<array{starts_at:string,ends_at:string,score:int,warnings:array}> */
    public array $slotSuggestions = [];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?string $navigationLabel = 'Calendar';

    protected static ?string $title = 'Studio calendar';

    protected static ?string $slug = 'calendar';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.schedule-calendar';

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            && auth()->user() !== null
            && Gate::allows('viewAny', [EventOccurrence::class, $tenant]);
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('createEvent')
                ->label('New event')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => $this->canManageCalendar())
                ->modalHeading('Add to the calendar')
                ->modalDescription('Choose the local start time. Maestro checks availability, travel, rooms, equipment, and capacity before anything is saved.')
                ->modalWidth('4xl')
                ->schema($this->eventCreationSchema())
                ->action(function (Action $action, array $data): void {
                    try {
                        $preview = $this->previewEventCreation($data);
                    } catch (ValidationException $exception) {
                        $this->notifyFailure('The event could not be previewed', $exception);
                        $action->halt();

                        return;
                    }

                    $this->replaceMountedAction('reviewEventCreation', [
                        'previewId' => $preview->getKey(),
                        'idempotencyKey' => (string) Str::uuid(),
                    ]);
                }),
            Action::make('reviewEventCreation')
                ->label('Review event')
                ->visible(fn (array $arguments): bool => filled($arguments['previewId'] ?? null))
                ->modalHeading('Review the calendar change')
                ->modalDescription('This preview is private, expires after 10 minutes, and is rechecked while committing.')
                ->modalWidth('2xl')
                ->schema(fn (array $arguments): array => $this->previewSchema($this->previewFromArguments($arguments)))
                ->modalSubmitAction(fn (Action $action, array $arguments): Action|false => $this->previewFromArguments($arguments)->status === SchedulePreviewStatus::Blocked
                        ? false
                        : $action->label('Create event')->icon(Heroicon::OutlinedCheck))
                ->action(function (Action $action, array $arguments, array $data): void {
                    $preview = $this->previewFromArguments($arguments);

                    try {
                        if (collect($preview->conflicts)->contains('severity', 'soft')) {
                            if (! ($data['acknowledge_soft_warnings'] ?? false)) {
                                throw ValidationException::withMessages([
                                    'acknowledge_soft_warnings' => 'Review and acknowledge the scheduling warnings before continuing.',
                                ]);
                            }

                            $replacement = app(PreviewCreateEventSeries::class)->handle(
                                $this->studio(),
                                $preview->command,
                                true,
                                $this->user(),
                                $preview->command_type,
                            );
                            $preview->delete();
                            $preview = $replacement;
                        }

                        $series = app(CommitCreateEventSeries::class)->handle(
                            $this->studio(),
                            $preview,
                            (string) ($arguments['idempotencyKey'] ?? ''),
                            $this->user(),
                        );
                    } catch (ValidationException|SchedulingConflict $exception) {
                        $this->notifyFailure('The event was not created', $exception);
                        $action->halt();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Event created')
                        ->body($series->title.' is now on the calendar.')
                        ->send();
                    $this->dispatch('maestro-calendar-refresh');
                }),
            Action::make('findAvailableTime')
                ->label('Find a time')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->visible(fn (): bool => $this->canManageCalendar())
                ->modalHeading('Find an available time')
                ->modalDescription('Searches a bounded candidate grid and ranks conflict-free times with the fewest scheduling warnings.')
                ->modalWidth('3xl')
                ->schema($this->slotSearchSchema())
                ->action(function (Action $action, array $data): void {
                    try {
                        $this->slotSuggestions = $this->searchAvailableSlots($data);
                    } catch (ValidationException $exception) {
                        $this->notifyFailure('No search was run', $exception);
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title(count($this->slotSuggestions).' available times found')->send();
                }),
            Action::make('rescheduleEvent')
                ->label('Reschedule')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->visible(fn (): bool => $this->canManageCalendar())
                ->modalHeading('Reschedule an event')
                ->modalDescription('Choose whether to move one occurrence, this and future occurrences, or the entire series. The impact is previewed before commit.')
                ->modalWidth('3xl')
                ->fillForm(fn (array $arguments): array => $this->rescheduleDefaults($arguments['occurrence_id'] ?? null))
                ->schema($this->rescheduleSchema())
                ->action(function (Action $action, array $data): void {
                    try {
                        $preview = $this->previewOccurrenceChange((string) $data['occurrence_id'], 'reschedule', $data);
                    } catch (ValidationException|SchedulingConflict $exception) {
                        $this->notifyFailure('The change could not be previewed', $exception);
                        $action->halt();

                        return;
                    }

                    $this->replaceMountedAction('reviewScheduleChange', [
                        'previewId' => $preview->getKey(),
                        'idempotencyKey' => (string) Str::uuid(),
                    ]);
                }),
            Action::make('changeEventStatus')
                ->label('Cancel or restore')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->visible(fn (): bool => $this->canManageCalendar())
                ->modalHeading('Cancel or restore events')
                ->modalDescription('Nothing changes until the impact preview is committed. Cancellation side effects remain projected, not executed inline.')
                ->modalWidth('2xl')
                ->fillForm(fn (array $arguments): array => [
                    'occurrence_id' => $arguments['occurrence_id'] ?? null,
                    'operation' => $arguments['operation'] ?? 'cancel',
                    'scope' => ScheduleEditScope::One->value,
                ])
                ->schema($this->statusChangeSchema())
                ->action(function (Action $action, array $data): void {
                    try {
                        $preview = $this->previewOccurrenceChange((string) $data['occurrence_id'], (string) $data['operation'], $data);
                    } catch (ValidationException|SchedulingConflict $exception) {
                        $this->notifyFailure('The status change could not be previewed', $exception);
                        $action->halt();

                        return;
                    }

                    $this->replaceMountedAction('reviewScheduleChange', [
                        'previewId' => $preview->getKey(),
                        'idempotencyKey' => (string) Str::uuid(),
                    ]);
                }),
            Action::make('reviewScheduleChange')
                ->label('Review schedule change')
                ->visible(fn (array $arguments): bool => filled($arguments['previewId'] ?? null))
                ->modalHeading('Review the schedule change')
                ->modalDescription('Conflicts, occurrence count, and downstream projections are rechecked at commit time.')
                ->modalWidth('2xl')
                ->schema(fn (array $arguments): array => $this->previewSchema($this->previewFromArguments($arguments)))
                ->modalSubmitAction(fn (Action $action, array $arguments): Action|false => $this->previewFromArguments($arguments)->status === SchedulePreviewStatus::Blocked
                    ? false
                    : $action->label('Commit change')->icon(Heroicon::OutlinedCheck))
                ->action(function (Action $action, array $arguments, array $data): void {
                    try {
                        $series = $this->commitOccurrenceChange(
                            (string) $arguments['previewId'],
                            (string) $arguments['idempotencyKey'],
                            (bool) ($data['acknowledge_soft_warnings'] ?? false),
                        );
                    } catch (ValidationException|SchedulingConflict $exception) {
                        $this->notifyFailure('The schedule was not changed', $exception);
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title('Schedule updated')->body($series->title)->send();
                    $this->dispatch('maestro-calendar-refresh');
                }),
            Action::make('cloneEvent')
                ->label('Clone series')
                ->icon(Heroicon::OutlinedSquare2Stack)
                ->visible(fn (): bool => $this->canManageCalendar())
                ->modalHeading('Clone an event series')
                ->modalDescription('Recurrence inclusions and exclusions are translated to the new local start. Review the generated impact before creating it.')
                ->modalWidth('2xl')
                ->fillForm(fn (array $arguments): array => $this->cloneDefaults($arguments['series_id'] ?? null))
                ->schema([
                    Select::make('series_id')->label('Source series')->required()->searchable()->native(false)
                        ->options(fn (): array => $this->seriesOptions()),
                    TextInput::make('title')->required()->maxLength(160),
                    DateTimePicker::make('starts_at')->label('New local start')->required()->seconds(false),
                    Select::make('dtstart_resolution')->label('DST overlap choice')->options([
                        'reject' => 'Ask me if the time is ambiguous', 'earlier' => 'Earlier occurrence', 'later' => 'Later occurrence',
                    ])->default('reject')->required()->native(false),
                ])
                ->action(function (Action $action, array $data): void {
                    try {
                        $preview = $this->previewClone($data);
                    } catch (ValidationException|SchedulingConflict $exception) {
                        $this->notifyFailure('The clone could not be previewed', $exception);
                        $action->halt();

                        return;
                    }

                    $this->replaceMountedAction('reviewEventCreation', [
                        'previewId' => $preview->getKey(),
                        'idempotencyKey' => (string) Str::uuid(),
                    ]);
                }),
            Action::make('manageHold')
                ->label('Manage hold')
                ->icon(Heroicon::OutlinedClock)
                ->visible(fn (): bool => $this->canManageCalendar())
                ->requiresConfirmation()
                ->modalHeading('Convert or release a temporary hold')
                ->fillForm(fn (array $arguments): array => [
                    'series_id' => $arguments['series_id'] ?? null,
                    'operation' => $arguments['operation'] ?? 'convert',
                    'idempotency_key' => (string) Str::uuid(),
                ])
                ->schema([
                    Select::make('series_id')->label('Temporary hold')->required()->searchable()->native(false)
                        ->options(fn (): array => $this->holdOptions()),
                    Select::make('operation')->required()->native(false)->options([
                        'convert' => 'Convert to confirmed events', 'release' => 'Release the hold',
                    ]),
                    Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                ])
                ->action(function (Action $action, array $data): void {
                    try {
                        $this->mutateHold((string) $data['series_id'], (string) $data['operation'], (string) $data['idempotency_key']);
                    } catch (ValidationException|SchedulingConflict $exception) {
                        $this->notifyFailure('The hold was not changed', $exception);
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title('Temporary hold updated')->send();
                    $this->dispatch('maestro-calendar-refresh');
                }),
            Action::make('manageRoster')
                ->label('Roster')
                ->icon(Heroicon::OutlinedUserGroup)
                ->visible(fn (): bool => $this->canManageCalendar())
                ->modalHeading('Manage the series roster')
                ->modalDescription('Enrollment changes are previewed against every active occurrence before they are committed.')
                ->modalWidth('3xl')
                ->fillForm(fn (array $arguments): array => [
                    'series_id' => $arguments['series_id'] ?? null,
                    'operation' => $arguments['operation'] ?? 'enroll',
                    'status' => EventEnrollmentStatus::Confirmed->value,
                    'idempotency_key' => (string) Str::uuid(),
                ])
                ->schema($this->rosterSchema())
                ->action(function (Action $action, array $data): void {
                    try {
                        $this->changeRoster($data);
                    } catch (ValidationException|SchedulingConflict $exception) {
                        $this->notifyFailure('The roster was not changed', $exception);
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title('Roster updated')->send();
                    $this->dispatch('maestro-calendar-refresh');
                }),
        ];
    }

    /** @param array<string, mixed> $data */
    public function previewEventCreation(array $data): ScheduleChangePreview
    {
        return app(PreviewCreateEventSeries::class)->handle(
            $this->studio(),
            $this->creationCommand($data),
            false,
            $this->user(),
        );
    }

    public function commitEventCreation(string $previewId, string $idempotencyKey): EventSeries
    {
        return app(CommitCreateEventSeries::class)->handle(
            $this->studio(),
            $this->previewFromArguments(['previewId' => $previewId]),
            $idempotencyKey,
            $this->user(),
        );
    }

    /** @param array<string, mixed> $data */
    public function previewOccurrenceChange(string $occurrenceId, string $operation, array $data): ScheduleChangePreview
    {
        abort_unless($this->canManageCalendar(), 403);
        $occurrence = $this->managedOccurrence($occurrenceId);
        $scope = ScheduleEditScope::from((string) ($data['scope'] ?? ScheduleEditScope::One->value));
        $command = ['version' => $occurrence->version];

        if ($operation === 'reschedule') {
            $timezone = (string) ($data['timezone'] ?? $occurrence->timezone);
            $command = [
                ...$command,
                'starts_at_local' => CarbonImmutable::parse((string) $data['starts_at'], $timezone)->format('Y-m-d\TH:i:s'),
                'start_resolution' => (string) ($data['start_resolution'] ?? 'reject'),
                'timezone' => $timezone,
                'duration_minutes' => (int) $data['duration_minutes'],
                'reason' => Str::squish((string) $data['reason']),
            ];
        } elseif ($operation === 'cancel') {
            $command['reason'] = Str::squish((string) $data['reason']);
            $command['makeup_required'] = (bool) ($data['makeup_required'] ?? false);
            if (filled($data['makeup_reference'] ?? null)) {
                $command['makeup_reference'] = Str::squish((string) $data['makeup_reference']);
            }
        } elseif ($operation === 'restore') {
            $command['reason'] = Str::squish((string) $data['reason']);
        } else {
            throw ValidationException::withMessages(['operation' => 'Choose a supported schedule operation.']);
        }

        return app(PreviewScheduleChange::class)->handle(
            $occurrence,
            $scope,
            $command,
            false,
            $this->user(),
            $operation,
        );
    }

    public function commitOccurrenceChange(string $previewId, string $idempotencyKey, bool $acknowledgeSoftWarnings): EventSeries
    {
        abort_unless($this->canManageCalendar(), 403);
        $preview = $this->previewFromArguments(['previewId' => $previewId]);

        if (collect($preview->conflicts)->contains('severity', 'soft')) {
            if (! $acknowledgeSoftWarnings) {
                throw ValidationException::withMessages([
                    'acknowledge_soft_warnings' => 'Review and acknowledge the scheduling warnings before continuing.',
                ]);
            }

            $occurrence = $this->managedOccurrence((string) $preview->event_occurrence_id);
            $replacement = app(PreviewScheduleChange::class)->handle(
                $occurrence,
                $preview->scope,
                [...$preview->command, 'version' => $occurrence->version],
                true,
                $this->user(),
                $preview->command_type,
            );
            $preview->delete();
            $preview = $replacement;
        }

        return app(CommitScheduleChange::class)->handle($preview, $idempotencyKey, $this->user());
    }

    /** @param array<string, mixed> $data */
    public function previewClone(array $data): ScheduleChangePreview
    {
        abort_unless($this->canManageCalendar(), 403);
        $source = $this->managedSeries((string) $data['series_id'])->load(['teachers', 'rooms', 'equipmentRequirements']);
        $localStart = CarbonImmutable::parse((string) $data['starts_at'], $source->timezone)->format('Y-m-d\TH:i:s');
        $overrides = [
            'dtstart_local' => $localStart,
            'dtstart_resolution' => (string) ($data['dtstart_resolution'] ?? 'reject'),
        ];
        $recurrence = app(RecurrenceSetTransformer::class)->forClone($source, $overrides);

        return app(PreviewCreateEventSeries::class)->handle($this->studio(), [
            'source_series_id' => $source->getKey(),
            'service_id' => $source->service_id,
            'program_offering_id' => $source->program_offering_id,
            'location_id' => $source->location_id,
            'pricing_staff_profile_id' => $source->pricing_staff_profile_id,
            'kind' => $source->kind->value,
            'visibility' => $source->visibility->value,
            'title' => Str::squish((string) $data['title']),
            'shared_description' => $source->shared_description,
            'internal_description' => $source->internal_description,
            'timezone' => $source->timezone,
            'dtstart_local' => $localStart,
            'dtstart_resolution' => $overrides['dtstart_resolution'],
            'duration_minutes' => $source->duration_minutes,
            'rrule' => $recurrence['rrule'],
            'rdates' => $recurrence['rdates'],
            'exdates' => $recurrence['exdates'],
            'capacity' => $source->capacity,
            'teachers' => $source->teachers->map(fn ($item): array => [
                'staff_profile_id' => $item->staff_profile_id,
                'role' => $item->role->value,
            ])->all(),
            'room_ids' => $source->rooms->pluck('room_id')->all(),
            'equipment' => $source->equipmentRequirements->map(fn ($item): array => [
                'equipment_id' => $item->equipment_id,
                'quantity' => $item->quantity,
            ])->all(),
        ], false, $this->user(), 'clone_event_series');
    }

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public function searchAvailableSlots(array $data): array
    {
        abort_unless($this->canManageCalendar(), 403);
        $timezone = $this->studio()->timezone;

        return app(SearchAvailableSlots::class)->handle($this->studio()->getKey(), array_filter([
            'from' => CarbonImmutable::parse((string) $data['from'], $timezone)->utc()->toAtomString(),
            'to' => CarbonImmutable::parse((string) $data['to'], $timezone)->utc()->toAtomString(),
            'duration_minutes' => (int) $data['duration_minutes'],
            'capacity' => (int) ($data['capacity'] ?? 1),
            'step_minutes' => (int) ($data['step_minutes'] ?? 15),
            'location_id' => $data['location_id'] ?? null,
            'staff_profile_ids' => array_values($data['teacher_ids'] ?? []),
            'room_ids' => array_values($data['room_ids'] ?? []),
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function mutateHold(string $seriesId, string $operation, string $idempotencyKey): EventSeries
    {
        abort_unless($this->canManageCalendar(), 403);
        $series = $this->managedSeries($seriesId);

        return match ($operation) {
            'convert' => app(ManageSchedulingHold::class)->convert($series, $series->version, $idempotencyKey, $this->user()),
            'release' => app(ManageSchedulingHold::class)->release($series, $series->version, $idempotencyKey, $this->user()),
            default => throw ValidationException::withMessages(['operation' => 'Choose convert or release.']),
        };
    }

    /** @param array<string, mixed> $data */
    public function changeRoster(array $data): EventEnrollment
    {
        abort_unless($this->canManageCalendar(), 403);
        $series = $this->managedSeries((string) $data['series_id']);
        $previews = app(PreviewEventEnrollment::class);

        if (($data['operation'] ?? null) === 'withdraw') {
            $enrollment = EventEnrollment::query()->where('studio_id', $this->studio()->getKey())
                ->where('event_series_id', $series->getKey())->findOrFail((string) ($data['enrollment_id'] ?? ''));
            $preview = $previews->withdraw($enrollment, $enrollment->version, $this->user());
        } else {
            $person = Person::query()->where('studio_id', $this->studio()->getKey())
                ->where('status', 'active')->findOrFail((string) ($data['person_id'] ?? ''));
            $preview = $previews->enroll(
                $series,
                $person,
                EventEnrollmentStatus::from((string) $data['status']),
                $this->user(),
            );
        }

        return app(CommitEventEnrollment::class)->handle(
            $preview,
            (string) ($data['idempotency_key'] ?? ''),
            $this->user(),
        );
    }

    /** @return array{timezone: string, canManage: bool, teachers: array<string, string>, rooms: array<string, string>} */
    public function calendarConfiguration(): array
    {
        $studio = $this->studio();
        $user = auth()->user();
        abort_unless($user !== null, 401);
        Gate::authorize('viewAny', [EventOccurrence::class, $studio]);
        $context = CalendarAccessContext::for($studio, $user);
        $canManage = $context->canManage();

        $teachers = StaffProfile::query()
            ->where('studio_id', $studio->getKey())
            ->where('status', 'active')
            ->with('person')
            ->when(! $canManage, fn (Builder $query) => $query->whereIn('id', $context->staffProfileIds))
            ->orderBy('id')
            ->get()
            ->filter(fn (StaffProfile $profile): bool => in_array('teacher', array_map(
                static fn (mixed $role): string => $role instanceof BackedEnum ? $role->value : (string) $role,
                $profile->roles,
            ), true))
            ->mapWithKeys(fn (StaffProfile $profile): array => [
                (string) $profile->getKey() => $profile->person->displayName(),
            ])->all();

        $rooms = $canManage ? Room::query()
            ->where('studio_id', $studio->getKey())
            ->where('active', true)
            ->with('location')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Room $room): array => [
                (string) $room->getKey() => trim(($room->location?->name ? $room->location->name.' · ' : '').$room->name),
            ])->all() : [];

        return [
            'timezone' => $studio->timezone,
            'canManage' => $canManage,
            'teachers' => $teachers,
            'rooms' => $rooms,
        ];
    }

    /**
     * @param  array{teacher_id?: string|null, room_id?: string|null, kind?: string|null, holds?: string|null, q?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function calendarEvents(string $from, string $to, array $filters = []): array
    {
        $studio = $this->studio();
        $user = auth()->user();
        abort_unless($user !== null, 401);
        $context = CalendarAccessContext::for($studio, $user);
        $canManage = $context->canManage();
        $isBilling = $context->isBilling();
        $teacherId = filled($filters['teacher_id'] ?? null) ? (string) $filters['teacher_id'] : null;
        $roomId = filled($filters['room_id'] ?? null) ? (string) $filters['room_id'] : null;
        $kind = filled($filters['kind'] ?? null) ? (string) $filters['kind'] : null;
        $holds = in_array($filters['holds'] ?? 'include', ['include', 'exclude', 'only'], true)
            ? ($filters['holds'] ?? 'include')
            : 'include';
        $search = trim((string) ($filters['q'] ?? ''));

        if ($teacherId !== null && ! StaffProfile::query()
            ->where('studio_id', $studio->getKey())->whereKey($teacherId)->exists()) {
            throw ValidationException::withMessages(['teacher_id' => 'The selected teacher is unavailable.']);
        }

        if ($roomId !== null && ! Room::query()
            ->where('studio_id', $studio->getKey())->whereKey($roomId)->exists()) {
            throw ValidationException::withMessages(['room_id' => 'The selected room is unavailable.']);
        }

        $records = app(QueryCalendarOccurrences::class)->handle($studio, $user, array_filter([
            'from' => $from,
            'to' => $to,
            'holds' => $holds,
            'teacher_ids' => $teacherId === null ? null : [$teacherId],
            'room_ids' => $roomId === null ? null : [$roomId],
            'kinds' => $kind === null ? null : [$kind],
            'q' => $search === '' ? null : $search,
        ], static fn (mixed $value): bool => $value !== null), $context);

        $teacherNames = $canManage || ! $isBilling
            ? StaffProfile::query()->where('studio_id', $studio->getKey())->with('person')->get()
                ->mapWithKeys(fn (StaffProfile $profile): array => [(string) $profile->getKey() => $profile->person->displayName()])
            : collect();
        $roomNames = ! $isBilling
            ? Room::query()->where('studio_id', $studio->getKey())->with('location')->get()
                ->mapWithKeys(fn (Room $room): array => [(string) $room->getKey() => trim(($room->location?->name ? $room->location->name.' · ' : '').$room->name)])
            : collect();

        return $records->map(function (EventOccurrence $occurrence) use ($canManage, $context, $teacherNames, $roomNames): array {
            $operational = $context->canViewOperationalDetails($occurrence);
            $teachers = ! $operational ? [] : $occurrence->teachers
                ->map(fn ($assignment): ?string => $teacherNames->get((string) $assignment->staff_profile_id))
                ->filter()->values()->all();
            $rooms = $operational ? $occurrence->rooms
                ->map(fn ($assignment): ?string => $roomNames->get((string) $assignment->room_id))
                ->filter()->values()->all() : [];

            return [
                'id' => $occurrence->getKey(),
                'title' => $occurrence->title,
                'start' => $occurrence->starts_at->toAtomString(),
                'end' => $occurrence->ends_at->toAtomString(),
                'classNames' => [
                    'maestro-kind-'.$occurrence->kind->value,
                    'maestro-status-'.$occurrence->status->value,
                    $occurrence->hold_expires_at === null ? 'maestro-confirmed' : 'maestro-hold',
                ],
                'extendedProps' => [
                    'seriesId' => $occurrence->event_series_id,
                    'kind' => $occurrence->kind->value,
                    'status' => $occurrence->status->value,
                    'timezone' => $occurrence->timezone,
                    'teachers' => $teachers,
                    'rooms' => $rooms,
                    'onlineJoinUrl' => $context->canViewOnlineJoinUrl($occurrence)
                        ? $occurrence->location?->online_url
                        : null,
                    'participants' => ! $operational ? null : $occurrence->participants
                        ->filter(fn ($participant): bool => in_array($participant->status, [
                            EventParticipantStatus::Reserved,
                            EventParticipantStatus::Confirmed,
                        ], true))->count(),
                    'capacity' => $occurrence->capacity,
                    'isHold' => $occurrence->hold_expires_at !== null,
                    'holdExpiresAt' => $occurrence->hold_expires_at?->toAtomString(),
                    'canManage' => $canManage,
                    'version' => $canManage ? $occurrence->version : null,
                    'seriesVersion' => $canManage ? $occurrence->series?->version : null,
                ],
            ];
        })->values()->all();
    }

    /** @return list<array{id:string,title:string,status:string,roster:list<array{name:string,status:string}>,confirmed:int,waitlisted:int}> */
    public function managedSeriesSummaries(): array
    {
        if (! $this->canManageCalendar()) {
            return [];
        }

        return EventSeries::query()->where('studio_id', $this->studio()->getKey())
            ->whereIn('status', ['active', 'draft'])->whereHas('occurrences', fn (Builder $query) => $query->where('starts_at', '>=', now()))
            ->with('enrollments')->orderBy('title')->limit(20)->get()->map(function (EventSeries $series): array {
                $people = Person::query()->where('studio_id', $this->studio()->getKey())
                    ->whereIn('id', $series->enrollments->pluck('person_id'))->get()->keyBy('id');
                $roster = $series->enrollments->whereIn('status', [
                    EventEnrollmentStatus::Confirmed,
                    EventEnrollmentStatus::Waitlisted,
                ])->map(fn (EventEnrollment $enrollment): array => [
                    'name' => $people->get($enrollment->person_id)?->displayName() ?? 'Student',
                    'status' => $enrollment->status->value,
                ])->values()->all();

                return [
                    'id' => $series->getKey(),
                    'title' => $series->title,
                    'status' => $series->status->value,
                    'roster' => $roster,
                    'confirmed' => collect($roster)->where('status', EventEnrollmentStatus::Confirmed->value)->count(),
                    'waitlisted' => collect($roster)->where('status', EventEnrollmentStatus::Waitlisted->value)->count(),
                ];
            })->all();
    }

    private function studio(): Studio
    {
        $studio = Filament::getTenant();
        abort_unless($studio instanceof Studio, 404);

        return $studio;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function canManageCalendar(): bool
    {
        return Gate::forUser($this->user())->allows('create', [EventSeries::class, $this->studio()]);
    }

    /** @return array<mixed> */
    private function slotSearchSchema(): array
    {
        return [
            DateTimePicker::make('from')->label('Search from')->required()->seconds(false)
                ->default(fn (): CarbonImmutable => now($this->studio()->timezone)->addDay()->setTime(8, 0)),
            DateTimePicker::make('to')->label('Search through')->required()->seconds(false)
                ->default(fn (): CarbonImmutable => now($this->studio()->timezone)->addDays(3)->setTime(20, 0)),
            TextInput::make('duration_minutes')->label('Duration (minutes)')->numeric()->integer()
                ->minValue(5)->maxValue(1440)->default(60)->required(),
            Select::make('step_minutes')->label('Search interval')->options([
                5 => 'Every 5 minutes', 10 => 'Every 10 minutes', 15 => 'Every 15 minutes',
                30 => 'Every 30 minutes', 60 => 'Every hour',
            ])->default(15)->required()->native(false),
            TextInput::make('capacity')->numeric()->integer()->minValue(1)->maxValue(1000)->default(1)->required(),
            Select::make('location_id')->label('Location')->searchable()->native(false)->live()
                ->options(fn (): array => Location::query()->where('studio_id', $this->studio()->getKey())
                    ->where('active', true)->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('teacher_ids')->label('Teachers')->multiple()->searchable()->native(false)
                ->options(fn (): array => $this->calendarConfiguration()['teachers']),
            Select::make('room_ids')->label('Rooms')->multiple()->searchable()->native(false)
                ->options(fn (Get $get): array => blank($get('location_id')) ? [] : Room::query()
                    ->where('studio_id', $this->studio()->getKey())->where('location_id', $get('location_id'))
                    ->where('active', true)->orderBy('name')->pluck('name', 'id')->all()),
        ];
    }

    /** @return array<mixed> */
    private function rescheduleSchema(): array
    {
        return [
            Select::make('occurrence_id')->label('Event')->required()->searchable()->native(false)->live()
                ->options(fn (): array => $this->occurrenceOptions())
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    foreach ($this->rescheduleDefaults($state) as $field => $value) {
                        if ($field !== 'occurrence_id') {
                            $set($field, $value);
                        }
                    }
                }),
            Select::make('scope')->label('Apply to')->required()->native(false)->options([
                ScheduleEditScope::One->value => 'Only this occurrence',
                ScheduleEditScope::Future->value => 'This and future occurrences',
                ScheduleEditScope::Series->value => 'Entire series (future-only if history exists)',
            ])->default(ScheduleEditScope::One->value),
            DateTimePicker::make('starts_at')->label('New local start')->required()->seconds(false),
            Select::make('timezone')->required()->searchable()->native(false)
                ->options(fn (): array => collect(DateTimeZone::listIdentifiers())
                    ->mapWithKeys(fn (string $timezone): array => [$timezone => str_replace('_', ' ', $timezone)])->all()),
            Select::make('start_resolution')->label('DST overlap choice')->options([
                'reject' => 'Ask me if the time is ambiguous', 'earlier' => 'Earlier occurrence', 'later' => 'Later occurrence',
            ])->default('reject')->required()->native(false),
            TextInput::make('duration_minutes')->label('Duration (minutes)')->numeric()->integer()
                ->minValue(5)->maxValue(1440)->required(),
            Textarea::make('reason')->label('Reason for change')->required()->maxLength(500)->rows(2)->columnSpanFull(),
        ];
    }

    /** @return array<mixed> */
    private function statusChangeSchema(): array
    {
        return [
            Select::make('occurrence_id')->label('Event')->required()->searchable()->native(false)
                ->options(fn (): array => $this->occurrenceOptions(includeCanceled: true)),
            Select::make('operation')->required()->native(false)->live()->options([
                'cancel' => 'Cancel', 'restore' => 'Restore',
            ]),
            Select::make('scope')->label('Apply to')->required()->native(false)->options([
                ScheduleEditScope::One->value => 'Only this occurrence',
                ScheduleEditScope::Future->value => 'This and future occurrences',
                ScheduleEditScope::Series->value => 'Entire future series',
            ]),
            Textarea::make('reason')->required()->maxLength(500)->rows(3)->columnSpanFull(),
            Toggle::make('makeup_required')->label('A makeup is required')->live()
                ->visible(fn (Get $get): bool => $get('operation') === 'cancel'),
            TextInput::make('makeup_reference')->label('Makeup reference')->maxLength(160)
                ->visible(fn (Get $get): bool => $get('operation') === 'cancel' && (bool) $get('makeup_required')),
        ];
    }

    /** @return array<mixed> */
    private function rosterSchema(): array
    {
        return [
            Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
            Select::make('series_id')->label('Event series')->required()->searchable()->native(false)->live()
                ->options(fn (): array => $this->seriesOptions()),
            Select::make('operation')->required()->native(false)->live()->options([
                'enroll' => 'Enroll or waitlist a student', 'withdraw' => 'Withdraw a current enrollment',
            ]),
            Select::make('person_id')->label('Student')->required(fn (Get $get): bool => $get('operation') === 'enroll')
                ->visible(fn (Get $get): bool => $get('operation') === 'enroll')->searchable()->native(false)
                ->options(fn (): array => Person::query()->where('studio_id', $this->studio()->getKey())
                    ->where('status', 'active')->whereHas('studentProfile', fn (Builder $query) => $query->where('status', 'active'))
                    ->orderBy('first_name')->orderBy('last_name')->get()
                    ->mapWithKeys(fn (Person $person): array => [$person->getKey() => $person->displayName()])->all()),
            Select::make('status')->required(fn (Get $get): bool => $get('operation') === 'enroll')
                ->visible(fn (Get $get): bool => $get('operation') === 'enroll')->native(false)->options([
                    EventEnrollmentStatus::Confirmed->value => 'Confirmed',
                    EventEnrollmentStatus::Waitlisted->value => 'Waitlisted',
                ]),
            Select::make('enrollment_id')->label('Enrollment')->required(fn (Get $get): bool => $get('operation') === 'withdraw')
                ->visible(fn (Get $get): bool => $get('operation') === 'withdraw')->searchable()->native(false)
                ->options(fn (Get $get): array => filled($get('series_id'))
                    ? $this->enrollmentOptions((string) $get('series_id')) : []),
        ];
    }

    /** @return array<string, mixed> */
    private function rescheduleDefaults(?string $occurrenceId): array
    {
        if (blank($occurrenceId)) {
            return ['occurrence_id' => null, 'scope' => ScheduleEditScope::One->value, 'start_resolution' => 'reject'];
        }

        $occurrence = $this->managedOccurrence($occurrenceId);

        return [
            'occurrence_id' => $occurrence->getKey(),
            'scope' => ScheduleEditScope::One->value,
            'starts_at' => $occurrence->starts_at->setTimezone($occurrence->timezone)->format('Y-m-d H:i:s'),
            'timezone' => $occurrence->timezone,
            'start_resolution' => 'reject',
            'duration_minutes' => $occurrence->starts_at->diffInMinutes($occurrence->ends_at),
        ];
    }

    /** @return array<string, mixed> */
    private function cloneDefaults(?string $seriesId): array
    {
        if (blank($seriesId)) {
            return ['series_id' => null, 'dtstart_resolution' => 'reject'];
        }

        $series = $this->managedSeries($seriesId);
        $start = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s', $series->dtstart_local, $series->timezone)
            ?: now($series->timezone)->addWeek()->startOfHour();

        return [
            'series_id' => $series->getKey(),
            'title' => 'Copy of '.$series->title,
            'starts_at' => $start->addWeek()->format('Y-m-d H:i:s'),
            'dtstart_resolution' => $series->dtstart_resolution->value,
        ];
    }

    /** @return array<string, string> */
    private function occurrenceOptions(bool $includeCanceled = false): array
    {
        return EventOccurrence::query()->where('studio_id', $this->studio()->getKey())
            ->when(! $includeCanceled, fn (Builder $query) => $query->whereIn('status', ['tentative', 'scheduled']))
            ->where('starts_at', '>=', now()->subDays(90))->where('starts_at', '<=', now()->addYear())
            ->orderBy('starts_at')->limit(500)->get()->mapWithKeys(fn (EventOccurrence $occurrence): array => [
                $occurrence->getKey() => $occurrence->starts_at->setTimezone($occurrence->timezone)->format('M j, Y g:i A')
                    .' · '.$occurrence->title.' · '.Str::headline($occurrence->status->value),
            ])->all();
    }

    /** @return array<string, string> */
    private function seriesOptions(): array
    {
        return EventSeries::query()->where('studio_id', $this->studio()->getKey())
            ->whereIn('status', ['active', 'draft'])->orderBy('title')->limit(500)->get()
            ->mapWithKeys(fn (EventSeries $series): array => [
                $series->getKey() => $series->title.' · '.Str::headline($series->status->value),
            ])->all();
    }

    /** @return array<string, string> */
    private function holdOptions(): array
    {
        return EventSeries::query()->where('studio_id', $this->studio()->getKey())
            ->where('status', 'draft')->whereNotNull('hold_expires_at')->where('hold_expires_at', '>', now())
            ->orderBy('hold_expires_at')->get()->mapWithKeys(fn (EventSeries $series): array => [
                $series->getKey() => $series->title.' · expires '.$series->hold_expires_at->setTimezone($series->timezone)->format('M j, g:i A'),
            ])->all();
    }

    /** @return array<string, string> */
    private function enrollmentOptions(string $seriesId): array
    {
        $series = $this->managedSeries($seriesId);

        return EventEnrollment::query()->where('studio_id', $this->studio()->getKey())
            ->where('event_series_id', $series->getKey())->whereIn('status', ['confirmed', 'waitlisted'])
            ->get()->mapWithKeys(function (EventEnrollment $enrollment): array {
                $person = Person::query()->where('studio_id', $this->studio()->getKey())->find($enrollment->person_id);

                return [$enrollment->getKey() => ($person?->displayName() ?? 'Student').' · '.Str::headline($enrollment->status->value)];
            })->all();
    }

    private function managedOccurrence(string $id): EventOccurrence
    {
        $occurrence = EventOccurrence::query()->where('studio_id', $this->studio()->getKey())->findOrFail($id);
        Gate::forUser($this->user())->authorize('update', $occurrence);

        return $occurrence;
    }

    private function managedSeries(string $id): EventSeries
    {
        $series = EventSeries::query()->where('studio_id', $this->studio()->getKey())->findOrFail($id);
        Gate::forUser($this->user())->authorize('update', $series);

        return $series;
    }

    /** @return array<mixed> */
    private function eventCreationSchema(): array
    {
        return [
            Section::make('Event')->schema([
                Select::make('kind')->options([
                    EventKind::General->value => 'General',
                    EventKind::PrivateLesson->value => 'Private lesson',
                    EventKind::GroupClass->value => 'Group class',
                    EventKind::OpenClass->value => 'Open class',
                    EventKind::Workshop->value => 'Workshop',
                    EventKind::Camp->value => 'Camp',
                    EventKind::Recital->value => 'Recital',
                    EventKind::Closure->value => 'Closure',
                ])->default(EventKind::PrivateLesson->value)->required()->native(false)->live(),
                TextInput::make('title')->required()->maxLength(160)->columnSpan(2),
                Select::make('service_id')->label('Service')
                    ->options(fn (): array => Service::query()->where('studio_id', $this->studio()->getKey())
                        ->where('active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->native(false)
                    ->required(fn (Get $get): bool => ! in_array($get('kind'), [EventKind::General->value, EventKind::Closure->value], true))
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $service = filled($state) ? Service::query()->where('studio_id', $this->studio()->getKey())->find($state) : null;

                        if ($service !== null) {
                            $set('duration_minutes', $service->default_duration_minutes);
                            $set('capacity', $service->default_capacity);
                        }
                    }),
                Select::make('visibility')->options([
                    EventVisibility::Private->value => 'Private',
                    EventVisibility::Studio->value => 'Studio members',
                    EventVisibility::Portal->value => 'Family portal',
                    EventVisibility::Public->value => 'Public calendar',
                ])->default(EventVisibility::Studio->value)->required()->native(false),
                Textarea::make('shared_description')->label('Shared description')->rows(3)->maxLength(10000)
                    ->helperText('Visible only to audiences allowed by the event visibility setting.')->columnSpanFull(),
                Textarea::make('internal_description')->label('Internal notes')->rows(3)->maxLength(10000)
                    ->helperText('Never exposed through public or portal event descriptions.')->columnSpanFull(),
            ])->columns(3),
            Section::make('When')->schema([
                DateTimePicker::make('starts_at')->label('Local start')->required()->seconds(false)
                    ->default(fn (): CarbonImmutable => now($this->studio()->timezone)->addHour()->startOfHour()),
                Select::make('timezone')->required()->searchable()->native(false)
                    ->options(fn (): array => collect(DateTimeZone::listIdentifiers())->mapWithKeys(fn (string $timezone): array => [$timezone => str_replace('_', ' ', $timezone)])->all())
                    ->default(fn (): string => $this->studio()->timezone),
                Select::make('dtstart_resolution')->label('DST overlap choice')->options([
                    'reject' => 'Ask me if the local time is ambiguous',
                    'earlier' => 'Earlier occurrence',
                    'later' => 'Later occurrence',
                ])->default('reject')->required()->native(false),
                TextInput::make('duration_minutes')->label('Duration (minutes)')->numeric()->integer()->minValue(5)->maxValue(1440)->default(60)->required(),
                TextInput::make('capacity')->numeric()->integer()->minValue(1)->maxValue(1000)->default(1)->required(),
                Select::make('repeat')->options([
                    'none' => 'Does not repeat', 'daily' => 'Daily', 'weekly' => 'Weekly',
                    'monthly' => 'Monthly', 'yearly' => 'Yearly',
                ])->default('none')->required()->native(false)->live(),
                TextInput::make('repeat_interval')->label('Repeat every')->numeric()->integer()->minValue(1)->maxValue(366)->default(1)
                    ->visible(fn (Get $get): bool => $get('repeat') !== 'none')
                    ->helperText('For weekly events, 2 means every two weeks.'),
                DatePicker::make('repeat_until')->label('Repeat through')
                    ->visible(fn (Get $get): bool => $get('repeat') !== 'none')
                    ->required(fn (Get $get): bool => $get('repeat') !== 'none')
                    ->minDate(fn (): CarbonImmutable => now($this->studio()->timezone)->startOfDay()),
                Toggle::make('temporary_hold')->label('Temporary hold')->live()
                    ->helperText('Reserves the slot until it is converted or automatically released.'),
                DateTimePicker::make('hold_expires_at')->label('Hold expires')->seconds(false)
                    ->visible(fn (Get $get): bool => (bool) $get('temporary_hold'))
                    ->required(fn (Get $get): bool => (bool) $get('temporary_hold'))
                    ->minDate(now())->maxDate(now()->addDays(7)),
            ])->columns(3),
            Section::make('Resources')->schema([
                Select::make('location_id')->label('Location')->searchable()->native(false)->live()
                    ->options(fn (): array => Location::query()->where('studio_id', $this->studio()->getKey())
                        ->where('active', true)->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('teacher_ids')->label('Teachers')->multiple()->searchable()->native(false)
                    ->options(fn (): array => $this->calendarConfiguration()['teachers']),
                Select::make('room_ids')->label('Rooms')->multiple()->searchable()->native(false)
                    ->options(fn (Get $get): array => blank($get('location_id')) ? [] : Room::query()
                        ->where('studio_id', $this->studio()->getKey())->where('location_id', $get('location_id'))
                        ->where('active', true)->orderBy('name')->pluck('name', 'id')->all()),
                Repeater::make('equipment')->label('Equipment')->defaultItems(0)->columnSpanFull()
                    ->schema([
                        Select::make('equipment_id')->label('Item')->required()->searchable()->native(false)
                            ->options(fn (Get $get): array => blank($get('../../location_id')) ? [] : Equipment::query()
                                ->where('studio_id', $this->studio()->getKey())->where('location_id', $get('../../location_id'))
                                ->where('active', true)->orderBy('name')->get()
                                ->mapWithKeys(fn (Equipment $item): array => [$item->getKey() => $item->name.' · '.$item->quantity.' available'])->all()),
                        TextInput::make('quantity')->numeric()->integer()->minValue(1)->maxValue(1000)->default(1)->required(),
                    ])->columns(2)->addActionLabel('Add equipment'),
            ])->columns(3),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function creationCommand(array $data): array
    {
        $timezone = (string) $data['timezone'];
        $startsAt = CarbonImmutable::parse((string) $data['starts_at'], $timezone);
        $repeat = (string) ($data['repeat'] ?? 'none');
        $rrule = null;

        if ($repeat !== 'none') {
            $until = CarbonImmutable::parse((string) $data['repeat_until'], $timezone)->endOfDay()->utc()->format('Ymd\THis\Z');
            $rrule = 'FREQ='.strtoupper($repeat).';INTERVAL='.(int) ($data['repeat_interval'] ?? 1).';UNTIL='.$until;
        }

        return array_filter([
            'service_id' => $data['service_id'] ?? null,
            'program_offering_id' => null,
            'location_id' => $data['location_id'] ?? null,
            'kind' => (string) $data['kind'],
            'visibility' => (string) $data['visibility'],
            'title' => Str::squish((string) $data['title']),
            'shared_description' => filled($data['shared_description'] ?? null) ? trim((string) $data['shared_description']) : null,
            'internal_description' => filled($data['internal_description'] ?? null) ? trim((string) $data['internal_description']) : null,
            'timezone' => $timezone,
            'dtstart_local' => $startsAt->format('Y-m-d\TH:i:s'),
            'dtstart_resolution' => (string) ($data['dtstart_resolution'] ?? 'reject'),
            'duration_minutes' => (int) $data['duration_minutes'],
            'rrule' => $rrule,
            'rdates' => [],
            'exdates' => [],
            'capacity' => (int) $data['capacity'],
            'hold_expires_at' => ($data['temporary_hold'] ?? false) && filled($data['hold_expires_at'] ?? null)
                ? CarbonImmutable::parse((string) $data['hold_expires_at'], $timezone)->utc()->toAtomString()
                : null,
            'teachers' => collect($data['teacher_ids'] ?? [])->map(fn (string $id): array => [
                'staff_profile_id' => $id, 'role' => 'lead',
            ])->values()->all(),
            'room_ids' => array_values($data['room_ids'] ?? []),
            'equipment' => array_values($data['equipment'] ?? []),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<mixed> */
    private function previewSchema(ScheduleChangePreview $preview): array
    {
        $hard = collect($preview->conflicts)->where('severity', 'hard');
        $soft = collect($preview->conflicts)->where('severity', 'soft');
        $impact = (int) ($preview->impact['affected_occurrences'] ?? 0);
        $effects = collect($preview->impact['effects'] ?? [])->map(fn (string $effect): string => Str::headline($effect));

        return [
            Section::make($preview->status === SchedulePreviewStatus::Blocked ? 'Conflicts need attention' : 'Ready to create')
                ->icon($preview->status === SchedulePreviewStatus::Blocked ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->schema([
                    Text::make($preview->command['title'] ?? 'Untitled event')->weight('bold'),
                    Text::make($impact.' '.Str::plural('calendar occurrence', $impact).' will be affected.'),
                    ...$effects->map(fn (string $effect): Text => Text::make('Projected: '.$effect)->color('gray'))->all(),
                    ...$hard->map(fn (array $conflict): Text => Text::make('Cannot continue: '.($conflict['message'] ?? $conflict['code'] ?? 'Scheduling conflict'))->color('danger'))->all(),
                    ...$soft->map(fn (array $conflict): Text => Text::make('Warning: '.($conflict['message'] ?? $conflict['code'] ?? 'Review required'))->color('warning'))->all(),
                    Toggle::make('acknowledge_soft_warnings')->label('I reviewed these warnings and want to continue')
                        ->required()->visible($soft->isNotEmpty()),
                ]),
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function previewFromArguments(array $arguments): ScheduleChangePreview
    {
        return ScheduleChangePreview::query()
            ->where('studio_id', $this->studio()->getKey())
            ->where('actor_id', $this->user()->getAuthIdentifier())
            ->findOrFail((string) ($arguments['previewId'] ?? ''));
    }

    private function notifyFailure(string $title, \Throwable $exception): void
    {
        $body = $exception instanceof ValidationException
            ? (collect($exception->errors())->flatten()->first() ?? $exception->getMessage())
            : $exception->getMessage();

        Notification::make()->danger()->title($title)->body((string) $body)->send();
    }
}
