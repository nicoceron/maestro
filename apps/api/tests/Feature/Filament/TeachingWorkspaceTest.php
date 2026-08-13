<?php

namespace Tests\Feature\Filament;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\StaffRole;
use App\Enums\StaffStatus;
use App\Filament\Pages\TeachingWorkspace;
use App\Models\AttendanceRecord;
use App\Models\EventOccurrence;
use App\Models\EventOccurrenceParticipant;
use App\Models\EventOccurrenceTeacher;
use App\Models\EventSeries;
use App\Models\LessonNote;
use App\Models\LessonNoteAttachment;
use App\Models\LessonNoteAttachmentScan;
use App\Models\LessonNoteDeliveryIntent;
use App\Models\LessonNoteDeliveryPreview;
use App\Models\LessonNoteTemplate;
use App\Models\Person;
use App\Models\StaffAccountLink;
use App\Models\StaffProfile;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class TeachingWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_records_attendance_and_creates_a_note_with_exact_idempotent_replay(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        [, $occurrence, $participant] = $this->lesson($studio, 'Monday piano');
        $this->filamentAs($owner, $studio);

        $component = Livewire::test(TeachingWorkspace::class)
            ->assertSuccessful()
            ->assertActionVisible('takeAttendance')
            ->assertActionVisible('addLessonNote')
            ->assertSee('Monday piano')
            ->mountAction('takeAttendance', ['occurrence_id' => $occurrence->getKey()])
            ->assertActionDataSet(fn (array $data): bool => $data['occurrence_id'] === $occurrence->getKey()
                && collect($data['items'])->first()['participant_id'] === $participant->getKey()
                && filled($data['idempotency_key']))
            ->unmountAction();
        $page = $component->instance();

        $attendanceKey = (string) Str::uuid();
        $component->callAction('takeAttendance', data: [
            'idempotency_key' => $attendanceKey,
            'occurrence_id' => $occurrence->getKey(),
            'items' => [[
                'participant_id' => $participant->getKey(),
                'outcome' => 'absent_excused',
                'reason' => 'Family notified the studio.',
            ]],
        ])->assertNotified('Attendance saved');
        $attendance = AttendanceRecord::query()->sole();
        $replayedAttendance = $page->recordAttendance($occurrence->getKey(), [[
            'participant_id' => $participant->getKey(),
            'outcome' => 'absent_excused',
            'reason' => 'Family notified the studio.',
        ]], $attendanceKey)->sole();

        $this->assertSame($attendance->getKey(), $replayedAttendance->getKey());
        $this->assertSame('pending_review', $attendance->billing_disposition->value);
        $this->assertSame('waived', $attendance->makeup_disposition->value);
        $this->assertDatabaseCount('attendance_records', 1);

        $noteKey = (string) Str::uuid();
        $component->callAction('addLessonNote', data: [
            'idempotency_key' => $noteKey,
            'occurrence_id' => $occurrence->getKey(),
            'scope' => 'participant',
            'participant_id' => $participant->getKey(),
            'audience' => 'guardian',
            'title' => '  Practice   recap ',
            'body_html' => '<p>Review the first eight measures.</p><script>alert(1)</script>',
        ])->assertNotified('Lesson note saved');
        $note = LessonNote::query()->sole();
        $replayedNote = $page->createLessonNote($occurrence->getKey(), [
            'scope' => 'participant',
            'participant_id' => $participant->getKey(),
            'audience' => 'guardian',
            'title' => 'Practice recap',
            'body_html' => '<p>Review the first eight measures.</p><script>alert(1)</script>',
        ], $noteKey);

        $this->assertSame($note->getKey(), $replayedNote->getKey());
        $this->assertSame('Practice recap', $note->title);
        $this->assertStringNotContainsString('<script', $note->body_html);
        $this->assertDatabaseCount('lesson_notes', 1);
        $this->assertDatabaseCount('lesson_note_revisions', 1);
        $this->assertDatabaseCount('attendance_domain_commands', 2);
    }

    public function test_teacher_sees_and_changes_only_assigned_lessons(): void
    {
        [$teacher, $studio, $membership] = $this->member(MembershipRole::Teacher);
        [, $assigned, $assignedParticipant] = $this->lesson($studio, 'Assigned lesson', $membership);
        [, $unassigned] = $this->lesson($studio, 'Another teacher lesson');
        $this->filamentAs($teacher, $studio, $membership);

        $component = Livewire::test(TeachingWorkspace::class)
            ->assertSuccessful()
            ->assertSee('Assigned lesson')
            ->assertDontSee('Another teacher lesson');
        $page = $component->instance();
        $this->assertSame(['Assigned lesson'], array_column($page->recentLessons(), 'title'));

        $record = $page->recordAttendance($assigned->getKey(), [[
            'participant_id' => $assignedParticipant->getKey(),
            'outcome' => 'present',
        ]], (string) Str::uuid())->sole();
        $this->assertInstanceOf(AttendanceRecord::class, $record);

        try {
            $page->recordAttendance($unassigned->getKey(), [], (string) Str::uuid());
            $this->fail('An unassigned teacher accessed another teacher\'s lesson.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('attendance_records', 1);
        }
    }

    public function test_billing_role_cannot_access_the_teaching_workspace(): void
    {
        config(['session.driver' => 'array']);
        app('session')->forgetDrivers();
        [$billing, $studio, $membership] = $this->member(MembershipRole::Billing);
        $this->lesson($studio, 'Private lesson');
        $this->filamentAs($billing, $studio, $membership);

        $this->assertFalse(TeachingWorkspace::canAccess());
        $this->get(TeachingWorkspace::getUrl(tenant: $studio))->assertForbidden();
    }

    public function test_manager_cannot_submit_a_cross_tenant_occurrence(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $otherStudio = Studio::factory()->create();
        [, $otherOccurrence, $otherParticipant] = $this->lesson($otherStudio, 'Other tenant lesson');
        $this->filamentAs($owner, $studio);
        $page = Livewire::test(TeachingWorkspace::class)->instance();

        try {
            $page->recordAttendance($otherOccurrence->getKey(), [[
                'participant_id' => $otherParticipant->getKey(),
                'outcome' => 'present',
            ]], (string) Str::uuid());
            $this->fail('A cross-tenant lesson was accepted.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('attendance_records', 0);
        }
    }

    public function test_manager_uses_real_filament_express_note_delivery_and_secure_attachment_actions(): void
    {
        Queue::fake();
        Storage::fake('lesson_attachments');
        config()->set('lesson-notes.attachments.disk', 'lesson_attachments');
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        [, $occurrence, $participant] = $this->lesson($studio, 'Complete workflow lesson');
        $participant->person()->update(['user_id' => $owner->getKey()]);
        $template = LessonNoteTemplate::query()->create([
            'studio_id' => $studio->getKey(),
            'name' => 'Weekly recap',
            'audience' => 'student',
            'body_html' => '<p>Review this week’s practice plan.</p>',
            'active' => true,
        ]);
        $this->filamentAs($owner, $studio);

        $component = Livewire::test(TeachingWorkspace::class)
            ->assertActionVisible('expressPresent')
            ->assertActionVisible('deliverLessonNote')
            ->assertActionVisible('uploadNoteAttachment')
            ->callAction('expressPresent', data: [
                'occurrence_id' => $occurrence->getKey(),
                'idempotency_key' => (string) Str::uuid(),
            ])->assertNotified('1 attendance records added');
        $this->assertSame('present', AttendanceRecord::query()->sole()->outcome->value);

        $component->callAction('addLessonNote', data: [
            'idempotency_key' => (string) Str::uuid(),
            'occurrence_id' => $occurrence->getKey(),
            'template_id' => $template->getKey(),
            'scope' => 'participant',
            'participant_id' => $participant->getKey(),
            'audience' => 'student',
            'title' => 'Weekly practice',
            'body_html' => $template->body_html,
        ])->assertNotified('Lesson note saved');
        $note = LessonNote::query()->sole();
        $this->assertSame($template->body_html, $note->body_html);

        $component->mountAction('deliverLessonNote', ['note_id' => $note->getKey()])
            ->assertActionDataSet(['note_id' => $note->getKey()])
            ->callMountedAction();
        $preview = LessonNoteDeliveryPreview::query()->sole();
        $this->assertSame(1, $preview->recipient_projection['recipient_count']);
        $component->mountAction('reviewNoteDelivery', [
            'previewId' => $preview->getKey(),
            'idempotencyKey' => (string) Str::uuid(),
        ])->callMountedAction()->assertNotified('Delivery queued');
        $this->assertDatabaseCount('lesson_note_delivery_intents', 1);
        $this->assertInstanceOf(LessonNoteDeliveryIntent::class, LessonNoteDeliveryIntent::query()->sole());

        $file = UploadedFile::fake()->createWithContent(
            'practice.pdf',
            "%PDF-1.7\nMaestro practice attachment\n%%EOF",
        );
        $component->callAction('uploadNoteAttachment', data: [
            'note_id' => $note->getKey(),
            'note_version' => $note->version,
            'idempotency_key' => (string) Str::uuid(),
            'file' => $file,
        ])->assertNotified('File quarantined for scanning');
        $attachment = LessonNoteAttachment::query()->sole();
        Storage::disk('lesson_attachments')->assertExists($attachment->quarantine_key);

        $component->callAction('rescanNoteAttachment', arguments: [
            'attachment_id' => $attachment->getKey(),
        ])->assertNotified('A new scan was queued');

        $cleanKey = "clean/{$studio->getKey()}/{$attachment->getKey()}/".str_repeat('a', 40).'.pdf';
        Storage::disk('lesson_attachments')->put($cleanKey, 'clean');
        LessonNoteAttachmentScan::query()->create([
            'studio_id' => $studio->getKey(),
            'lesson_note_attachment_id' => $attachment->getKey(),
            'attempt' => 1,
            'status' => 'clean',
            'engine' => 'test',
            'detail_code' => 'clean',
            'clean_disk' => 'lesson_attachments',
            'clean_key' => $cleanKey,
            'scanned_at' => now(),
        ]);
        $cards = $component->instance()->recentNoteCards();
        $url = $cards[0]['attachments'][0]['download_url'];
        $this->assertNotNull($url);
        $this->assertStringContainsString('signature=', $url);

        $component->callAction('retireNoteAttachment', data: [
            'attachment_id' => $attachment->getKey(),
            'reason' => 'Superseded by the next lesson recording.',
        ], arguments: [
            'attachment_id' => $attachment->getKey(),
        ])->assertNotified('Attachment retired');
        $this->assertDatabaseHas('lesson_note_attachment_retirements', [
            'studio_id' => $studio->getKey(),
            'lesson_note_attachment_id' => $attachment->getKey(),
        ]);
    }

    /** @return array{StaffProfile, EventOccurrence, EventOccurrenceParticipant} */
    private function lesson(Studio $studio, string $title, ?StudioMembership $teacherMembership = null): array
    {
        $teacherPerson = Person::factory()->for($studio)->create([
            'first_name' => 'Teacher',
            'last_name' => Str::random(8),
        ]);
        $teacher = StaffProfile::query()->create([
            'studio_id' => $studio->getKey(),
            'person_id' => $teacherPerson->getKey(),
            'roles' => [StaffRole::Teacher],
            'status' => StaffStatus::Active,
        ]);
        if ($teacherMembership !== null) {
            StaffAccountLink::query()->create([
                'studio_id' => $studio->getKey(),
                'staff_profile_id' => $teacher->getKey(),
                'studio_membership_id' => $teacherMembership->getKey(),
                'active' => true,
            ]);
        }

        $startsAt = now()->subDay()->startOfHour();
        $series = EventSeries::query()->create([
            'studio_id' => $studio->getKey(),
            'kind' => 'general',
            'status' => 'active',
            'visibility' => 'studio',
            'title' => $title,
            'timezone' => 'UTC',
            'dtstart_local' => $startsAt->format('Y-m-d\TH:i:s'),
            'dtstart_resolution' => 'reject',
            'duration_minutes' => 60,
            'capacity' => 4,
        ]);
        $occurrence = EventOccurrence::query()->create([
            'studio_id' => $studio->getKey(),
            'event_series_id' => $series->getKey(),
            'public_uid' => Str::uuid(),
            'recurrence_id_local' => $startsAt->format('Y-m-d\TH:i:s'),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'utc_offset_minutes' => 0,
            'timezone' => 'UTC',
            'source' => 'one_off',
            'status' => 'completed',
            'title' => $title,
            'kind' => 'general',
            'capacity' => 4,
            'price_minor' => 0,
            'currency' => 'USD',
            'policy_snapshot' => ['makeup_policy' => 'none'],
        ]);
        EventOccurrenceTeacher::query()->create([
            'studio_id' => $studio->getKey(),
            'event_occurrence_id' => $occurrence->getKey(),
            'staff_profile_id' => $teacher->getKey(),
            'role' => 'lead',
            'status' => 'assigned',
            'busy_starts_at' => $occurrence->starts_at,
            'busy_ends_at' => $occurrence->ends_at,
        ]);
        $student = Person::factory()->for($studio)->create();
        $participant = EventOccurrenceParticipant::query()->create([
            'studio_id' => $studio->getKey(),
            'event_occurrence_id' => $occurrence->getKey(),
            'event_series_id' => $series->getKey(),
            'person_id' => $student->getKey(),
            'role' => 'student',
            'status' => 'confirmed',
            'blocks_conflicts' => true,
            'busy_starts_at' => $occurrence->starts_at,
            'busy_ends_at' => $occurrence->ends_at,
        ]);

        return [$teacher, $occurrence, $participant];
    }

    private function filamentAs(User $user, Studio $studio, ?StudioMembership $membership = null): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($studio);
        $membership ??= StudioMembership::query()
            ->where('studio_id', $studio->getKey())
            ->where('user_id', $user->getKey())
            ->sole();
        app(TenantContext::class)->activate($studio, $membership);
    }

    /** @return array{User, Studio, StudioMembership} */
    private function member(MembershipRole $role, ?Studio $studio = null): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $studio ??= Studio::factory()->create(['timezone' => 'UTC']);
        $membership = StudioMembership::query()->create([
            'studio_id' => $studio->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'preferences' => [],
        ]);

        return [$user, $studio, $membership];
    }
}
