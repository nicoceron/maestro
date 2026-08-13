<?php

namespace App\Filament\Pages;

use App\Actions\Attendance\CommitLessonNoteDelivery;
use App\Actions\Attendance\CreateLessonNote;
use App\Actions\Attendance\PreviewLessonNoteDelivery;
use App\Actions\Attendance\RecordAttendanceBulk;
use App\Actions\Attendance\RetireLessonNoteAttachment;
use App\Actions\Attendance\UploadLessonNoteAttachment;
use App\Enums\AttendanceOutcome;
use App\Enums\LessonNoteAudience;
use App\Enums\LessonNoteScope;
use App\Enums\MembershipRole;
use App\Exceptions\AttendanceConflict;
use App\Jobs\ScanLessonNoteAttachmentJob;
use App\Models\AttendanceRecord;
use App\Models\EventOccurrence;
use App\Models\EventOccurrenceParticipant;
use App\Models\LessonNote;
use App\Models\LessonNoteAttachment;
use App\Models\LessonNoteDeliveryIntent;
use App\Models\LessonNoteDeliveryPreview;
use App\Models\LessonNoteTemplate;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Attendance\AttendanceAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

final class TeachingWorkspace extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?string $navigationLabel = 'Teaching';

    protected static ?string $title = 'Teaching workspace';

    protected static ?string $slug = 'teaching';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.teaching-workspace';

    public static function canAccess(): bool
    {
        $studio = Filament::getTenant();
        $user = auth()->user();

        if (! $studio instanceof Studio || ! $user instanceof User) {
            return false;
        }

        return StudioMembership::query()
            ->where('studio_id', $studio->getKey())
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', 'active')
            ->whereIn('role', [
                MembershipRole::Owner,
                MembershipRole::Administrator,
                MembershipRole::Office,
                MembershipRole::Teacher,
            ])->exists();
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('takeAttendance')
                ->label('Take attendance')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->modalHeading('Take attendance')
                ->modalDescription('Only ended lessons and active roster members appear. Financial and makeup effects are projected after the attendance transaction commits.')
                ->modalWidth('5xl')
                ->fillForm(function (array $arguments): array {
                    $occurrenceId = $arguments['occurrence_id'] ?? null;

                    return [
                        'idempotency_key' => (string) Str::uuid(),
                        'occurrence_id' => $occurrenceId,
                        'items' => filled($occurrenceId) ? $this->attendanceRows((string) $occurrenceId) : [],
                    ];
                })
                ->schema([
                    Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                    Select::make('occurrence_id')->label('Lesson')->required()->searchable()->native(false)->live()
                        ->options(fn (): array => $this->occurrenceOptions())
                        ->afterStateUpdated(fn (?string $state, Set $set) => $set('items', filled($state) ? $this->attendanceRows($state) : [])),
                    Repeater::make('items')->label('Roster')->minItems(1)->addable(false)->deletable(false)->reorderable(false)
                        ->schema([
                            Hidden::make('participant_id'),
                            Hidden::make('version'),
                            TextInput::make('student')->disabled()->dehydrated(false),
                            Select::make('outcome')->options([
                                AttendanceOutcome::Present->value => 'Present',
                                AttendanceOutcome::Late->value => 'Late',
                                AttendanceOutcome::AbsentExcused->value => 'Absent — excused',
                                AttendanceOutcome::AbsentUnexcused->value => 'Absent — unexcused',
                                AttendanceOutcome::NoShow->value => 'No show',
                                AttendanceOutcome::TeacherCancelled->value => 'Teacher cancelled',
                            ])->required()->native(false)->live(),
                            TextInput::make('minutes_late')->numeric()->integer()->minValue(1)->maxValue(1440)
                                ->visible(fn (Get $get): bool => $get('outcome') === AttendanceOutcome::Late->value)
                                ->required(fn (Get $get): bool => $get('outcome') === AttendanceOutcome::Late->value),
                            Textarea::make('reason')->label('Attendance note')->rows(2)->maxLength(500),
                            Textarea::make('correction_reason')->label('Why is this changing?')->rows(2)->maxLength(500)
                                ->visible(fn (Get $get): bool => filled($get('version')))
                                ->required(fn (Get $get): bool => filled($get('version'))),
                        ])->columns(4)->columnSpanFull(),
                ])
                ->action(function (Action $action, array $data): void {
                    try {
                        $this->recordAttendance(
                            (string) $data['occurrence_id'],
                            $data['items'] ?? [],
                            (string) ($data['idempotency_key'] ?? ''),
                        );
                    } catch (ValidationException|AttendanceConflict $exception) {
                        $this->notifyFailure('Attendance was not saved', $exception);
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title('Attendance saved')->send();
                }),
            Action::make('expressPresent')
                ->label('Everyone present')
                ->icon(Heroicon::OutlinedBolt)
                ->modalHeading('Mark unrecorded students present')
                ->modalDescription('Only roster members without an attendance record are changed. Existing attendance and correction history remain untouched.')
                ->requiresConfirmation()
                ->fillForm(fn (array $arguments): array => [
                    'occurrence_id' => $arguments['occurrence_id'] ?? null,
                    'idempotency_key' => (string) Str::uuid(),
                ])
                ->schema([
                    Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                    Select::make('occurrence_id')->label('Lesson')->required()->searchable()->native(false)
                        ->options(fn (): array => $this->occurrenceOptions()),
                ])
                ->action(function (Action $action, array $data): void {
                    try {
                        $records = $this->expressPresent(
                            (string) $data['occurrence_id'],
                            (string) $data['idempotency_key'],
                        );
                    } catch (ValidationException|AttendanceConflict $exception) {
                        $this->notifyFailure('Attendance was not saved', $exception);
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title($records->count().' attendance records added')->send();
                }),
            Action::make('addLessonNote')
                ->label('Add lesson note')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->modalHeading('Add a lesson note')
                ->modalDescription('Choose the audience explicitly. Private notes remain visible only to their author; saving never sends a message automatically.')
                ->modalWidth('3xl')
                ->fillForm(fn (array $arguments): array => [
                    'idempotency_key' => (string) Str::uuid(),
                    'occurrence_id' => $arguments['occurrence_id'] ?? null,
                    'scope' => LessonNoteScope::Participant->value,
                    'audience' => LessonNoteAudience::Student->value,
                ])
                ->schema([
                    Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                    Select::make('occurrence_id')->label('Lesson')->required()->searchable()->native(false)->live()
                        ->options(fn (): array => $this->occurrenceOptions()),
                    Select::make('template_id')->label('Start from a template')->searchable()->native(false)->live()
                        ->options(fn (): array => $this->templateOptions())
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $template = filled($state) ? $this->visibleTemplate($state) : null;
                            if ($template !== null) {
                                $set('audience', $template->audience->value);
                                $set('body_html', $template->body_html);
                            }
                        }),
                    Select::make('scope')->options([
                        LessonNoteScope::Participant->value => 'One student',
                        LessonNoteScope::Group->value => 'Whole group',
                    ])->default(LessonNoteScope::Participant->value)->required()->native(false)->live(),
                    Select::make('participant_id')->label('Student')->searchable()->native(false)
                        ->required(fn (Get $get): bool => $get('scope') === LessonNoteScope::Participant->value)
                        ->visible(fn (Get $get): bool => $get('scope') === LessonNoteScope::Participant->value)
                        ->options(fn (Get $get): array => filled($get('occurrence_id'))
                            ? $this->participantOptions((string) $get('occurrence_id')) : []),
                    Select::make('audience')->options([
                        LessonNoteAudience::Student->value => 'Student',
                        LessonNoteAudience::Guardian->value => 'Guardians',
                        LessonNoteAudience::AuthorPrivate->value => 'Only me',
                    ])->default(LessonNoteAudience::Student->value)->required()->native(false),
                    TextInput::make('title')->maxLength(160),
                    RichEditor::make('body_html')->label('Note')->required()->maxLength(20000)
                        ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo'])
                        ->columnSpanFull(),
                ])
                ->action(function (Action $action, array $data): void {
                    try {
                        $this->createLessonNote(
                            (string) $data['occurrence_id'],
                            $data,
                            (string) ($data['idempotency_key'] ?? ''),
                        );
                    } catch (ValidationException|AttendanceConflict $exception) {
                        $this->notifyFailure('The note was not saved', $exception);
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title('Lesson note saved')->send();
                }),
            Action::make('deliverLessonNote')
                ->label('Deliver note')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->modalHeading('Preview lesson-note delivery')
                ->modalDescription('Saving a note never sends it. Maestro snapshots eligible recipients and clean attachments before a separate delivery commit.')
                ->fillForm(fn (array $arguments): array => ['note_id' => $arguments['note_id'] ?? null])
                ->schema([
                    Select::make('note_id')->label('Lesson note')->required()->searchable()->native(false)
                        ->options(fn (): array => $this->deliverableNoteOptions()),
                ])
                ->action(function (Action $action, array $data): void {
                    try {
                        $preview = $this->previewNoteDelivery((string) $data['note_id']);
                    } catch (ValidationException|AttendanceConflict $exception) {
                        $this->notifyFailure('Delivery could not be previewed', $exception);
                        $action->halt();

                        return;
                    }

                    $this->replaceMountedAction('reviewNoteDelivery', [
                        'previewId' => $preview->getKey(),
                        'idempotencyKey' => (string) Str::uuid(),
                    ]);
                }),
            Action::make('reviewNoteDelivery')
                ->label('Review note delivery')
                ->visible(fn (array $arguments): bool => filled($arguments['previewId'] ?? null))
                ->modalHeading('Review recipients and attachments')
                ->modalDescription('The recipient and attachment snapshots are rechecked when this delivery intent is committed.')
                ->schema(fn (array $arguments): array => $this->deliveryPreviewSchema($this->deliveryPreview($arguments)))
                ->action(function (Action $action, array $arguments): void {
                    try {
                        $intent = $this->commitNoteDelivery(
                            (string) $arguments['previewId'],
                            (string) $arguments['idempotencyKey'],
                        );
                    } catch (ValidationException|AttendanceConflict $exception) {
                        $this->notifyFailure('Delivery was not queued', $exception);
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title('Delivery queued')->body(
                        ($intent->recipient_projection['recipient_count'] ?? 0).' eligible recipients',
                    )->send();
                }),
            Action::make('uploadNoteAttachment')
                ->label('Attach file')
                ->icon(Heroicon::OutlinedPaperClip)
                ->modalHeading('Securely attach a file')
                ->modalDescription('Files stay quarantined until malware scanning succeeds. Pending or failed files are never delivered or downloadable.')
                ->fillForm(fn (array $arguments): array => $this->attachmentUploadDefaults($arguments['note_id'] ?? null))
                ->schema([
                    Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                    Hidden::make('note_version'),
                    Select::make('note_id')->label('Lesson note')->required()->searchable()->native(false)->live()
                        ->options(fn (): array => $this->revisableNoteOptions())
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $set('note_version', filled($state) ? $this->revisableNote($state)->version : null);
                        }),
                    FileUpload::make('file')->required()->storeFiles(false)->maxFiles(1)
                        ->maxSize((int) ceil(((int) config('lesson-notes.attachments.maximum_bytes')) / 1024))
                        ->helperText('Allowed file types are verified by content, not only by the filename.'),
                ])
                ->action(function (Action $action, array $data): void {
                    try {
                        $attachment = $this->uploadAttachment(
                            (string) $data['note_id'],
                            $data['file'],
                            (int) $data['note_version'],
                            (string) $data['idempotency_key'],
                        );
                    } catch (ValidationException|AttendanceConflict $exception) {
                        $this->notifyFailure('The file was not attached', $exception);
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title('File quarantined for scanning')->body($attachment->original_name)->send();
                }),
            Action::make('rescanNoteAttachment')
                ->label('Retry scan')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (array $arguments): bool => filled($arguments['attachment_id'] ?? null)
                    && Gate::forUser($this->user())->allows('rescan', $this->visibleAttachment((string) $arguments['attachment_id'])))
                ->action(function (array $arguments): void {
                    $this->retryAttachmentScan((string) $arguments['attachment_id']);
                    Notification::make()->success()->title('A new scan was queued')->send();
                }),
            Action::make('retireNoteAttachment')
                ->label('Retire file')
                ->icon(Heroicon::OutlinedTrash)
                ->visible(fn (array $arguments): bool => filled($arguments['attachment_id'] ?? null)
                    && Gate::forUser($this->user())->allows('retire', $this->visibleAttachment((string) $arguments['attachment_id'])))
                ->requiresConfirmation()
                ->fillForm(fn (array $arguments): array => ['attachment_id' => $arguments['attachment_id'] ?? null])
                ->schema([
                    Hidden::make('attachment_id'),
                    Textarea::make('reason')->required()->maxLength(500)->rows(3),
                ])
                ->action(function (Action $action, array $data): void {
                    try {
                        $this->retireAttachment((string) $data['attachment_id'], (string) $data['reason']);
                    } catch (ValidationException|AttendanceConflict $exception) {
                        $this->notifyFailure('The attachment was not retired', $exception);
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title('Attachment retired')->send();
                }),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return Collection<int, AttendanceRecord>
     */
    public function recordAttendance(string $occurrenceId, array $items, string $idempotencyKey): Collection
    {
        return app(RecordAttendanceBulk::class)->handle(
            $this->visibleOccurrence($occurrenceId),
            collect($items)->map(fn (array $item): array => array_filter([
                'participant_id' => $item['participant_id'] ?? null,
                'version' => filled($item['version'] ?? null) ? (int) $item['version'] : null,
                'outcome' => $item['outcome'] ?? null,
                'minutes_late' => ($item['outcome'] ?? null) === AttendanceOutcome::Late->value
                    ? (int) ($item['minutes_late'] ?? 0) : 0,
                'reason' => filled($item['reason'] ?? null) ? trim((string) $item['reason']) : null,
                'correction_reason' => filled($item['correction_reason'] ?? null)
                    ? trim((string) $item['correction_reason']) : null,
            ], static fn (mixed $value): bool => $value !== null))->all(),
            $this->user(),
            $idempotencyKey,
        );
    }

    /** @return Collection<int, AttendanceRecord> */
    public function expressPresent(string $occurrenceId, string $idempotencyKey): Collection
    {
        return app(RecordAttendanceBulk::class)->expressPresent(
            $this->visibleOccurrence($occurrenceId),
            $this->user(),
            $idempotencyKey,
        );
    }

    /** @param array<string, mixed> $data */
    public function createLessonNote(string $occurrenceId, array $data, string $idempotencyKey): LessonNote
    {
        return app(CreateLessonNote::class)->handle(
            $this->visibleOccurrence($occurrenceId),
            array_filter([
                'scope' => (string) $data['scope'],
                'participant_id' => $data['scope'] === LessonNoteScope::Participant->value
                    ? ($data['participant_id'] ?? null) : null,
                'audience' => (string) $data['audience'],
                'title' => filled($data['title'] ?? null) ? Str::squish((string) $data['title']) : null,
                'body_html' => (string) $data['body_html'],
            ], static fn (mixed $value): bool => $value !== null),
            $this->user(),
            $idempotencyKey,
        );
    }

    public function previewNoteDelivery(string $noteId): LessonNoteDeliveryPreview
    {
        return app(PreviewLessonNoteDelivery::class)->handle($this->deliverableNote($noteId), $this->user());
    }

    public function commitNoteDelivery(string $previewId, string $idempotencyKey): LessonNoteDeliveryIntent
    {
        $preview = LessonNoteDeliveryPreview::query()
            ->where('studio_id', $this->studio()->getKey())
            ->where('actor_id', $this->user()->getAuthIdentifier())
            ->findOrFail($previewId);
        $this->deliverableNote((string) $preview->lesson_note_id);

        return app(CommitLessonNoteDelivery::class)->handle($preview, $idempotencyKey, $this->user());
    }

    public function uploadAttachment(
        string $noteId,
        TemporaryUploadedFile $file,
        int $noteVersion,
        string $idempotencyKey,
    ): LessonNoteAttachment {
        return app(UploadLessonNoteAttachment::class)->handle(
            $this->revisableNote($noteId),
            $file,
            $noteVersion,
            $this->user(),
            $idempotencyKey,
        );
    }

    public function retryAttachmentScan(string $attachmentId): void
    {
        $attachment = $this->visibleAttachment($attachmentId);
        Gate::forUser($this->user())->authorize('rescan', $attachment);
        ScanLessonNoteAttachmentJob::dispatch((string) $this->studio()->getKey(), (string) $attachment->getKey());
    }

    public function retireAttachment(string $attachmentId, string $reason): void
    {
        $attachment = $this->visibleAttachment($attachmentId);
        Gate::forUser($this->user())->authorize('retire', $attachment);
        app(RetireLessonNoteAttachment::class)->handle($attachment, Str::squish($reason), $this->user());
    }

    public function attachmentDownloadUrl(string $attachmentId): string
    {
        $attachment = $this->visibleAttachment($attachmentId);
        Gate::forUser($this->user())->authorize('download', $attachment);

        return URL::temporarySignedRoute('api.v1.lesson-note-attachments.download', now()->addMinutes(
            min(15, max(1, (int) config('lesson-notes.attachments.download_url_minutes'))),
        ), [
            'studio' => $this->studio()->getRouteKey(),
            'note' => $attachment->lesson_note_id,
            'attachment' => $attachment->getKey(),
        ]);
    }

    /** @return list<array{id:string,title:string,when:string,roster:int,recorded:int,overdue:bool}> */
    public function recentLessons(): array
    {
        return $this->visibleOccurrences()
            ->withCount([
                'participants as roster_count' => fn (Builder $query) => $query->whereIn('status', ['reserved', 'confirmed']),
                'attendanceRecords as attendance_count',
            ])->limit(30)->get()->map(fn (EventOccurrence $occurrence): array => [
                'id' => $occurrence->getKey(),
                'title' => $occurrence->title,
                'when' => $occurrence->starts_at->setTimezone($occurrence->timezone)->isoFormat('ddd, MMM D · h:mm A'),
                'roster' => (int) $occurrence->roster_count,
                'recorded' => (int) $occurrence->attendance_count,
                'overdue' => $occurrence->ends_at->lt(now()->subHours(24))
                    && (int) $occurrence->attendance_count < (int) $occurrence->roster_count,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    public function recentNoteCards(): array
    {
        $occurrences = $this->visibleOccurrences()->pluck('id');

        return LessonNote::query()->where('studio_id', $this->studio()->getKey())
            ->whereIn('event_occurrence_id', $occurrences)->with([
                'occurrence',
                'participantPerson',
                'attachments.latestScan',
                'attachments.retirement',
                'attachments.revision',
            ])->orderByDesc('created_at')->limit(50)->get()
            ->filter(fn (LessonNote $note): bool => Gate::forUser($this->user())->allows('view', $note))
            ->map(function (LessonNote $note): array {
                $canRevise = Gate::forUser($this->user())->allows('update', $note);
                $attachments = $note->attachments->map(function (LessonNoteAttachment $attachment): array {
                    $status = $attachment->retirement !== null
                        ? 'retired'
                        : ($attachment->latestScan?->status->value ?? 'pending');
                    $canDownload = Gate::forUser($this->user())->allows('download', $attachment);

                    return [
                        'id' => $attachment->getKey(),
                        'name' => $attachment->original_name,
                        'status' => $status,
                        'revision' => $attachment->revision?->revision,
                        'download_url' => $canDownload ? $this->attachmentDownloadUrl($attachment->getKey()) : null,
                        'can_rescan' => Gate::forUser($this->user())->allows('rescan', $attachment),
                        'can_retire' => Gate::forUser($this->user())->allows('retire', $attachment),
                    ];
                })->all();

                return [
                    'id' => $note->getKey(),
                    'title' => $note->title ?: ($note->scope === LessonNoteScope::Group ? 'Group note' : 'Lesson note'),
                    'lesson' => $note->occurrence?->title ?? 'Lesson',
                    'student' => $note->participantPerson?->displayName(),
                    'audience' => $note->audience->value,
                    'summary' => Str::limit(trim(strip_tags($note->body_html)), 180),
                    'attachments' => $attachments,
                    'can_deliver' => Gate::forUser($this->user())->allows('deliver', $note),
                    'can_attach' => $canRevise,
                ];
            })->values()->all();
    }

    /** @return array<mixed> */
    private function deliveryPreviewSchema(LessonNoteDeliveryPreview $preview): array
    {
        $projection = $preview->recipient_projection;
        $attachments = collect($projection['attachments'] ?? [])->pluck('name')->all();

        return [
            Section::make('Delivery snapshot')->schema([
                Text::make(($projection['recipient_count'] ?? 0).' eligible '.Str::plural('recipient', (int) ($projection['recipient_count'] ?? 0)))->weight('bold'),
                Text::make($attachments === []
                    ? 'No clean attachments will be included.'
                    : 'Clean attachments: '.implode(', ', $attachments)),
                Text::make('Pending, failed, infected, retired, and historical-revision files are excluded.')->color('gray'),
            ]),
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function deliveryPreview(array $arguments): LessonNoteDeliveryPreview
    {
        return LessonNoteDeliveryPreview::query()->where('studio_id', $this->studio()->getKey())
            ->where('actor_id', $this->user()->getAuthIdentifier())
            ->findOrFail((string) ($arguments['previewId'] ?? ''));
    }

    /** @return array<string, mixed> */
    private function attachmentUploadDefaults(?string $noteId): array
    {
        $note = filled($noteId) ? $this->revisableNote($noteId) : null;

        return [
            'note_id' => $note?->getKey(),
            'note_version' => $note?->version,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    /** @return array<string, string> */
    private function templateOptions(): array
    {
        abort_unless(app(AttendanceAccess::class)->canUseNoteTemplates($this->user(), $this->studio()), 403);

        return LessonNoteTemplate::query()->where('studio_id', $this->studio()->getKey())
            ->where('active', true)->orderBy('normalized_name')->pluck('name', 'id')->all();
    }

    private function visibleTemplate(string $id): ?LessonNoteTemplate
    {
        $template = LessonNoteTemplate::query()->where('studio_id', $this->studio()->getKey())
            ->where('active', true)->find($id);
        if ($template !== null) {
            Gate::forUser($this->user())->authorize('view', $template);
        }

        return $template;
    }

    /** @return array<string, string> */
    private function deliverableNoteOptions(): array
    {
        return $this->noteOptions('deliver');
    }

    /** @return array<string, string> */
    private function revisableNoteOptions(): array
    {
        return $this->noteOptions('update');
    }

    /** @return array<string, string> */
    private function noteOptions(string $ability): array
    {
        return LessonNote::query()->where('studio_id', $this->studio()->getKey())
            ->whereIn('event_occurrence_id', $this->visibleOccurrences()->pluck('id'))
            ->with('occurrence')->orderByDesc('created_at')->limit(200)->get()
            ->filter(fn (LessonNote $note): bool => Gate::forUser($this->user())->allows($ability, $note))
            ->mapWithKeys(fn (LessonNote $note): array => [
                $note->getKey() => ($note->occurrence?->title ?? 'Lesson').' · '.($note->title ?: Str::headline($note->audience->value)),
            ])->all();
    }

    private function deliverableNote(string $id): LessonNote
    {
        $note = $this->visibleNote($id);
        Gate::forUser($this->user())->authorize('deliver', $note);

        return $note;
    }

    private function revisableNote(string $id): LessonNote
    {
        $note = $this->visibleNote($id);
        Gate::forUser($this->user())->authorize('update', $note);

        return $note;
    }

    private function visibleNote(string $id): LessonNote
    {
        return LessonNote::query()->where('studio_id', $this->studio()->getKey())
            ->whereIn('event_occurrence_id', $this->visibleOccurrences()->pluck('id'))
            ->with('occurrence')->findOrFail($id);
    }

    private function visibleAttachment(string $id): LessonNoteAttachment
    {
        $attachment = LessonNoteAttachment::query()->where('studio_id', $this->studio()->getKey())
            ->with(['note.occurrence', 'revision', 'latestScan', 'retirement'])->findOrFail($id);
        $this->visibleNote((string) $attachment->lesson_note_id);
        Gate::forUser($this->user())->authorize('view', $attachment);

        return $attachment;
    }

    /** @return array<string, string> */
    private function occurrenceOptions(): array
    {
        return $this->visibleOccurrences()->limit(100)->get()->mapWithKeys(fn (EventOccurrence $occurrence): array => [
            $occurrence->getKey() => $occurrence->starts_at->setTimezone($occurrence->timezone)->format('M j, g:i A').' · '.$occurrence->title,
        ])->all();
    }

    private function visibleOccurrences(): Builder
    {
        $query = EventOccurrence::query()
            ->where('studio_id', $this->studio()->getKey())
            ->where('ends_at', '<=', now())
            ->where('ends_at', '>=', now()->subDays(90))
            ->whereIn('status', ['scheduled', 'completed'])
            ->whereHas('participants', fn (Builder $query) => $query->whereIn('status', ['reserved', 'confirmed']))
            ->orderByDesc('starts_at')->orderByDesc('id');

        if (! $this->canManage()) {
            $staffId = app(AttendanceAccess::class)->staffProfileId($this->user(), $this->studio()->getKey());

            $query->whereHas('teachers', fn (Builder $query) => $query
                ->where('status', 'assigned')->where('staff_profile_id', $staffId ?? ''));
        }

        return $query;
    }

    private function visibleOccurrence(string $id): EventOccurrence
    {
        $occurrence = $this->visibleOccurrences()->findOrFail($id);
        abort_unless(app(AttendanceAccess::class)->canRecordAttendance($this->user(), $occurrence), 403);

        return $occurrence;
    }

    /** @return list<array<string, mixed>> */
    private function attendanceRows(string $occurrenceId): array
    {
        return $this->visibleOccurrence($occurrenceId)->participants()
            ->whereIn('status', ['reserved', 'confirmed'])
            ->with(['person', 'attendance'])->orderBy('person_id')->get()
            ->map(fn (EventOccurrenceParticipant $participant): array => [
                'participant_id' => $participant->getKey(),
                'student' => $participant->person?->displayName() ?? 'Student',
                'version' => $participant->attendance?->version,
                'outcome' => $participant->attendance?->outcome?->value,
                'minutes_late' => $participant->attendance?->minutes_late,
                'reason' => $participant->attendance?->reason,
            ])->all();
    }

    /** @return array<string, string> */
    private function participantOptions(string $occurrenceId): array
    {
        return $this->visibleOccurrence($occurrenceId)->participants()
            ->whereIn('status', ['reserved', 'confirmed'])->with('person')->orderBy('person_id')->get()
            ->mapWithKeys(fn (EventOccurrenceParticipant $participant): array => [
                $participant->getKey() => $participant->person?->displayName() ?? 'Student',
            ])->all();
    }

    private function canManage(): bool
    {
        return app(AttendanceAccess::class)->canManage($this->user(), $this->studio());
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

    private function notifyFailure(string $title, ValidationException|AttendanceConflict $exception): void
    {
        $body = $exception instanceof ValidationException
            ? (collect($exception->errors())->flatten()->first() ?? $exception->getMessage())
            : $exception->getMessage();

        Notification::make()->danger()->title($title)->body((string) $body)->send();
    }
}
