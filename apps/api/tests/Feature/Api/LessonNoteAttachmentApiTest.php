<?php

namespace Tests\Feature\Api;

use App\Actions\Attendance\ScanLessonNoteAttachment;
use App\Contracts\Attachments\MalwareScanner;
use App\Enums\MembershipRole;
use App\Enums\StaffRole;
use App\Enums\StaffStatus;
use App\Jobs\ScanLessonNoteAttachmentJob;
use App\Models\EventOccurrence;
use App\Models\EventOccurrenceParticipant;
use App\Models\EventOccurrenceTeacher;
use App\Models\EventSeries;
use App\Models\GuardianRelationship;
use App\Models\Household;
use App\Models\LessonNoteAttachment;
use App\Models\Location;
use App\Models\Person;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\StaffAccountLink;
use App\Models\StaffProfile;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Attachments\MalwareScanResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Attachments\DeterministicMalwareScanner;
use Tests\TestCase;

final class LessonNoteAttachmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_is_private_pending_after_commit_and_idempotent(): void
    {
        Queue::fake();
        Storage::fake('lesson_attachments');
        [$teacher, $studio, $note] = $this->context();
        Sanctum::actingAs($teacher);
        $url = "/api/v1/studios/{$studio->slug}/notes/{$note['id']}/attachments";
        $pdf = $this->pdf('Etude.pdf');

        $first = $this->post($url, [
            'note_version' => 1,
            'file' => $pdf,
        ], ['Idempotency-Key' => 'attachment-upload-1', 'Accept' => 'application/json'])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.name', 'Etude.pdf')
            ->assertJsonMissing(['quarantine_key'])
            ->json('data');

        $attachment = LessonNoteAttachment::query()->findOrFail($first['id']);
        $this->assertMatchesRegularExpression(
            '~^quarantine/[0-9a-hjkmnp-tv-z]{26}/[0-9a-hjkmnp-tv-z]{26}/[a-f0-9]{40}\.pdf$~',
            $attachment->quarantine_key,
        );
        Storage::disk('lesson_attachments')->assertExists($attachment->quarantine_key);
        Queue::assertPushed(ScanLessonNoteAttachmentJob::class, fn ($job): bool => $job->afterCommit
            && $job->attachmentId === $attachment->getKey());

        $this->post($url, [
            'note_version' => 1,
            'file' => $this->pdf('Renamed.pdf'),
        ], ['Idempotency-Key' => 'attachment-upload-1', 'Accept' => 'application/json'])
            ->assertAccepted()->assertJsonPath('data.id', $first['id']);
        $this->assertDatabaseCount('lesson_note_attachments', 1);
        $this->assertDatabaseCount('lesson_note_attachment_commands', 1);

        $this->post($url, [
            'note_version' => 1,
            'file' => $this->pdf('Different.pdf', 'different'),
        ], ['Idempotency-Key' => 'attachment-upload-1', 'Accept' => 'application/json'])
            ->assertConflict()->assertJsonPath('code', 'idempotency_key_reused');
    }

    public function test_allowlist_magic_size_and_note_quotas_fail_before_storage(): void
    {
        Queue::fake();
        Storage::fake('lesson_attachments');
        [$teacher, $studio, $note] = $this->context();
        Sanctum::actingAs($teacher);
        $url = "/api/v1/studios/{$studio->slug}/notes/{$note['id']}/attachments";

        $this->post($url, ['note_version' => 1, 'file' => UploadedFile::fake()->createWithContent('evil.pdf', '<?php echo 1;')], [
            'Idempotency-Key' => 'bad-magic', 'Accept' => 'application/json',
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post($url, ['note_version' => 1, 'file' => UploadedFile::fake()->createWithContent('evil.exe', 'MZ')], [
            'Idempotency-Key' => 'bad-extension', 'Accept' => 'application/json',
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        config([
            'lesson-notes.attachments.maximum_active_per_note' => 1,
            'lesson-notes.attachments.maximum_active_bytes_per_note' => 1024,
        ]);
        $this->post($url, ['note_version' => 1, 'file' => $this->pdf('first.pdf')], [
            'Idempotency-Key' => 'quota-first', 'Accept' => 'application/json',
        ])->assertAccepted();
        $this->post($url, ['note_version' => 1, 'file' => $this->pdf('second.pdf')], [
            'Idempotency-Key' => 'quota-second', 'Accept' => 'application/json',
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertCount(1, Storage::disk('lesson_attachments')->allFiles());
    }

    public function test_only_clean_active_current_attachments_get_reauthorized_signed_downloads(): void
    {
        Queue::fake();
        Storage::fake('lesson_attachments');
        [$teacher, $studio, $note] = $this->context();
        Sanctum::actingAs($teacher);
        $attachment = $this->upload($studio, $note['id'], 'download-clean');
        $base = "/api/v1/studios/{$studio->slug}/notes/{$note['id']}/attachments/{$attachment->getKey()}";

        $this->postJson("{$base}/download-url")->assertNotFound();
        $this->scan($attachment, MalwareScanResult::clean('deterministic', '1'));
        $signed = $this->postJson("{$base}/download-url")
            ->assertOk()->assertJsonStructure(['data' => ['url', 'expires_at']])->json('data.url');
        $path = parse_url($signed, PHP_URL_PATH).'?'.parse_url($signed, PHP_URL_QUERY);
        Sanctum::actingAs($teacher);
        $this->withHeader('Origin', (string) config('services.frontend.url'))
            ->get($path)->assertStatus(423);
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->withHeader('Origin', (string) config('services.frontend.url'))
            ->get($path)->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('cache-control', 'max-age=0, no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');

        $other = User::factory()->create(['email_verified_at' => now()]);
        StudioMembership::factory()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $other->getKey(), 'role' => MembershipRole::Teacher,
        ]);
        Sanctum::actingAs($other);
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->withHeader('Origin', (string) config('services.frontend.url'))
            ->get($path)->assertNotFound();

        Sanctum::actingAs($teacher);
        $this->deleteJson($base, ['reason' => 'Superseded by a corrected score.'])->assertNoContent();
        $this->deleteJson($base, ['reason' => 'Retry after response loss.'])->assertNoContent();
        $this->assertDatabaseCount('lesson_note_attachment_retirements', 1);
        $this->postJson("{$base}/download-url")->assertNotFound();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->withHeader('Origin', (string) config('services.frontend.url'))
            ->get($path)->assertNotFound();
    }

    public function test_infected_failed_and_retired_files_never_get_urls_and_failed_scan_can_retry(): void
    {
        Queue::fake();
        Storage::fake('lesson_attachments');
        [$teacher, $studio, $note] = $this->context();
        Sanctum::actingAs($teacher);

        $infected = $this->upload($studio, $note['id'], 'infected');
        $this->scan($infected, MalwareScanResult::infected('deterministic'));
        $this->postJson($this->downloadUrl($studio, $note['id'], $infected))->assertNotFound();

        $failed = $this->upload($studio, $note['id'], 'failed');
        $this->scan($failed, MalwareScanResult::failed('deterministic'));
        $this->postJson($this->downloadUrl($studio, $note['id'], $failed))->assertNotFound();
        $this->postJson($this->base($studio, $note['id'], $failed).'/scan')
            ->assertAccepted()->assertJsonPath('data.status', 'failed');
        Queue::assertPushed(ScanLessonNoteAttachmentJob::class, fn ($job): bool => $job->attachmentId === $failed->getKey());
    }

    public function test_note_projection_and_delivery_snapshot_include_only_current_clean_authorized_attachments(): void
    {
        Queue::fake();
        Storage::fake('lesson_attachments');
        [$teacher, $studio, $note, $participant] = $this->context();
        Sanctum::actingAs($teacher);
        $student = Person::query()->findOrFail($participant->person_id);
        $student->user_id = $teacher->getKey();
        $student->save();
        $clean = $this->upload($studio, $note['id'], 'projection-clean');
        $this->scan($clean, MalwareScanResult::clean('deterministic'));
        $this->upload($studio, $note['id'], 'projection-pending');

        $notes = $this->getJson("/api/v1/studios/{$studio->slug}/occurrences/{$participant->event_occurrence_id}/notes")
            ->assertOk()->json('data.0.attachments');
        $this->assertCount(2, $notes);

        $preview = $this->postJson("/api/v1/studios/{$studio->slug}/notes/{$note['id']}/delivery-previews")
            ->assertCreated()->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.attachments.0.id', $clean->getKey())->json('data');
        $drift = $this->upload($studio, $note['id'], 'projection-drift');
        $this->scan($drift, MalwareScanResult::clean('deterministic'));
        $this->postJson(
            "/api/v1/studios/{$studio->slug}/note-delivery-previews/{$preview['id']}/commit",
            [],
            ['Idempotency-Key' => 'attachment-delivery'],
        )->assertConflict()->assertJsonPath('code', 'attachments_changed_after_preview');

        $freshPreview = $this->postJson("/api/v1/studios/{$studio->slug}/notes/{$note['id']}/delivery-previews")
            ->assertCreated()->assertJsonCount(2, 'data.attachments')->json('data');
        $this->postJson(
            "/api/v1/studios/{$studio->slug}/note-delivery-previews/{$freshPreview['id']}/commit",
            [],
            ['Idempotency-Key' => 'attachment-delivery-fresh'],
        )->assertCreated()->assertJsonCount(2, 'data.attachments')
            ->assertJsonPath('data.attachments.0.id', $clean->getKey());
    }

    public function test_cross_tenant_attachment_routes_are_not_found(): void
    {
        Queue::fake();
        Storage::fake('lesson_attachments');
        [$teacher, $studio, $note] = $this->context();
        $attachment = $this->uploadAs($teacher, $studio, $note['id'], 'tenant-one');
        [$otherTeacher, $otherStudio, $otherNote] = $this->context();
        Sanctum::actingAs($otherTeacher);

        $this->postJson($this->downloadUrl($otherStudio, $otherNote['id'], $attachment))->assertNotFound();
        $this->deleteJson($this->base($otherStudio, $otherNote['id'], $attachment), ['reason' => 'Nope'])->assertNotFound();
    }

    public function test_attachment_access_inherits_student_guardian_teacher_and_manager_note_audiences(): void
    {
        Queue::fake();
        Storage::fake('lesson_attachments');
        [$teacher, $studio, $studentNote, $participant] = $this->context();
        $studentAttachment = $this->uploadAs($teacher, $studio, $studentNote['id'], 'student-audience');
        $this->scan($studentAttachment, MalwareScanResult::clean('deterministic'));
        $this->upload($studio, $studentNote['id'], 'student-audience-pending');

        $manager = User::factory()->create(['email_verified_at' => now()]);
        StudioMembership::factory()->owner()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $manager->getKey(),
        ]);
        $studentUser = User::factory()->create(['email_verified_at' => now()]);
        StudioMembership::factory()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $studentUser->getKey(), 'role' => MembershipRole::Teacher,
        ]);
        Person::query()->findOrFail($participant->person_id)->update(['user_id' => $studentUser->getKey()]);
        $guardianUser = User::factory()->create(['email_verified_at' => now()]);
        StudioMembership::factory()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $guardianUser->getKey(), 'role' => MembershipRole::Teacher,
        ]);
        $guardianPerson = Person::factory()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $guardianUser->getKey(),
        ]);
        $household = Household::factory()->create(['studio_id' => $studio->getKey()]);
        GuardianRelationship::query()->create([
            'studio_id' => $studio->getKey(), 'household_id' => $household->getKey(),
            'guardian_person_id' => $guardianPerson->getKey(), 'student_person_id' => $participant->person_id,
            'relationship' => 'parent', 'is_legal_guardian' => true, 'portal_permissions' => ['learning'],
        ]);

        foreach ([$teacher, $manager, $studentUser] as $allowed) {
            Sanctum::actingAs($allowed);
            $this->postJson($this->downloadUrl($studio, $studentNote['id'], $studentAttachment))->assertOk();
        }
        Sanctum::actingAs($studentUser);
        $this->getJson("/api/v1/studios/{$studio->slug}/occurrences/{$participant->event_occurrence_id}/notes")
            ->assertOk()->assertJsonCount(1, 'data.0.attachments')
            ->assertJsonPath('data.0.attachments.0.id', $studentAttachment->getKey());
        Sanctum::actingAs($guardianUser);
        $this->postJson($this->downloadUrl($studio, $studentNote['id'], $studentAttachment))->assertNotFound();

        Sanctum::actingAs($teacher);
        $guardianNote = $this->postJson(
            "/api/v1/studios/{$studio->slug}/occurrences/{$participant->event_occurrence_id}/notes",
            [
                'scope' => 'participant', 'participant_id' => $participant->getKey(),
                'audience' => 'guardian', 'body_html' => '<p>Guardian-only guidance.</p>',
            ],
            ['Idempotency-Key' => 'guardian-audience-note'],
        )->assertCreated()->json('data');
        $guardianAttachment = $this->upload($studio, $guardianNote['id'], 'guardian-audience');
        $this->scan($guardianAttachment, MalwareScanResult::clean('deterministic'));
        Sanctum::actingAs($guardianUser);
        $this->postJson($this->downloadUrl($studio, $guardianNote['id'], $guardianAttachment))->assertOk();
        Sanctum::actingAs($studentUser);
        $this->postJson($this->downloadUrl($studio, $guardianNote['id'], $guardianAttachment))->assertNotFound();
    }

    public function test_retention_purges_old_quarantine_and_retired_clean_objects_but_keeps_audit_history(): void
    {
        Queue::fake();
        Storage::fake('lesson_attachments');
        config([
            'lesson-notes.attachments.quarantine_retention_hours' => 2,
            'lesson-notes.attachments.pending_retention_hours' => 2,
            'lesson-notes.attachments.retired_retention_hours' => 1,
            'lesson-notes.attachments.purge_batch_size' => 1,
        ]);
        [$teacher, $studio, $note] = $this->context();
        Sanctum::actingAs($teacher);
        $clean = $this->upload($studio, $note['id'], 'retention-clean');
        $this->scan($clean, MalwareScanResult::clean('deterministic'));
        $clean->refresh()->load('latestScan');
        $cleanKey = $clean->latestScan->clean_key;
        $infected = $this->upload($studio, $note['id'], 'retention-infected');
        $this->scan($infected, MalwareScanResult::infected('deterministic'));
        $pending = $this->upload($studio, $note['id'], 'retention-pending');
        $this->deleteJson($this->base($studio, $note['id'], $clean), [
            'reason' => 'Superseded.',
        ])->assertNoContent();

        $this->travel(3)->hours();
        $this->assertSame(0, Artisan::call('attachments:purge', ['--studio' => $studio->getKey()]));
        Storage::disk('lesson_attachments')->assertMissing($clean->quarantine_key);
        Storage::disk('lesson_attachments')->assertMissing($cleanKey);
        Storage::disk('lesson_attachments')->assertMissing($infected->quarantine_key);
        Storage::disk('lesson_attachments')->assertMissing($pending->quarantine_key);
        $this->assertDatabaseCount('lesson_note_attachments', 3);
        $this->assertDatabaseCount('lesson_note_attachment_scans', 2);
        $this->assertDatabaseCount('lesson_note_attachment_retirements', 1);
        $this->assertDatabaseCount('lesson_note_attachment_purges', 4);

        $sourceMissing = app(ScanLessonNoteAttachment::class)->handle($pending->fresh());
        $this->assertSame('failed', $sourceMissing->status->value);
        $this->assertSame('source_missing', $sourceMissing->detail_code);

        $this->assertSame(0, Artisan::call('attachments:purge', ['--studio' => $studio->getKey()]));
        $this->assertDatabaseCount('lesson_note_attachment_purges', 4);
    }

    public function test_retention_failure_isolated_without_false_purge_audit(): void
    {
        Queue::fake();
        Storage::fake('lesson_attachments');
        config([
            'lesson-notes.attachments.pending_retention_hours' => 1,
            'lesson-notes.attachments.purge_batch_size' => 1,
        ]);
        [, $studio, $note] = $this->context();
        $good = $this->upload($studio, $note['id'], 'retention-good');
        $bad = LessonNoteAttachment::query()->create([
            'studio_id' => $good->studio_id,
            'lesson_note_id' => $good->lesson_note_id,
            'lesson_note_revision_id' => $good->lesson_note_revision_id,
            'uploaded_by_user_id' => $good->uploaded_by_user_id,
            'quarantine_disk' => 'unconfigured_attachment_disk',
            'quarantine_key' => "quarantine/{$good->studio_id}/{$good->lesson_note_id}/".str_repeat('f', 40).'.pdf',
            'original_name' => 'unreachable.pdf',
            'extension' => 'pdf',
            'declared_mime' => 'application/pdf',
            'detected_mime' => 'application/pdf',
            'size_bytes' => 10,
            'sha256' => str_repeat('e', 64),
            'created_at' => now(),
        ]);

        $this->travel(2)->hours();
        $this->assertSame(1, Artisan::call('attachments:purge', ['--studio' => $studio->getKey()]));
        Storage::disk('lesson_attachments')->assertMissing($good->quarantine_key);
        $this->assertDatabaseHas('lesson_note_attachment_purges', [
            'lesson_note_attachment_id' => $good->getKey(),
        ]);
        $this->assertDatabaseMissing('lesson_note_attachment_purges', [
            'lesson_note_attachment_id' => $bad->getKey(),
        ]);
    }

    private function scan(LessonNoteAttachment $attachment, MalwareScanResult $result): void
    {
        $this->app->instance(MalwareScanner::class, new DeterministicMalwareScanner($result));
        app(ScanLessonNoteAttachment::class)->handle($attachment->fresh());
    }

    private function upload(Studio $studio, string $noteId, string $key): LessonNoteAttachment
    {
        $response = $this->post("/api/v1/studios/{$studio->slug}/notes/{$noteId}/attachments", [
            'note_version' => 1, 'file' => $this->pdf("{$key}.pdf"),
        ], ['Idempotency-Key' => $key, 'Accept' => 'application/json'])
            ->assertAccepted();

        return LessonNoteAttachment::query()->findOrFail($response->json('data.id'));
    }

    private function uploadAs(User $user, Studio $studio, string $noteId, string $key): LessonNoteAttachment
    {
        Sanctum::actingAs($user);

        return $this->upload($studio, $noteId, $key);
    }

    private function pdf(string $name, string $body = 'maestro attachment'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.7\n{$body}\n%%EOF");
    }

    private function base(Studio $studio, string $noteId, LessonNoteAttachment $attachment): string
    {
        return "/api/v1/studios/{$studio->slug}/notes/{$noteId}/attachments/{$attachment->getKey()}";
    }

    private function downloadUrl(Studio $studio, string $noteId, LessonNoteAttachment $attachment): string
    {
        return $this->base($studio, $noteId, $attachment).'/download-url';
    }

    /** @return array{User, Studio, array<string, mixed>, EventOccurrenceParticipant} */
    private function context(): array
    {
        $teacher = User::factory()->create(['email_verified_at' => now()]);
        $studio = Studio::factory()->create();
        $membership = StudioMembership::factory()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $teacher->getKey(), 'role' => MembershipRole::Teacher,
        ]);
        $person = Person::factory()->create(['studio_id' => $studio->getKey()]);
        $staff = StaffProfile::query()->create([
            'studio_id' => $studio->getKey(), 'person_id' => $person->getKey(),
            'roles' => [StaffRole::Teacher], 'status' => StaffStatus::Active,
        ]);
        StaffAccountLink::query()->create([
            'studio_id' => $studio->getKey(), 'staff_profile_id' => $staff->getKey(),
            'studio_membership_id' => $membership->getKey(),
        ]);
        $category = ServiceCategory::query()->create([
            'studio_id' => $studio->getKey(), 'name' => 'Lessons', 'normalized_name' => 'lessons',
        ]);
        $service = Service::query()->create([
            'studio_id' => $studio->getKey(), 'service_category_id' => $category->getKey(),
            'name' => 'Piano', 'normalized_name' => 'piano', 'default_duration_minutes' => 60,
            'default_capacity' => 2, 'default_price_minor' => 5000, 'currency' => 'USD', 'makeup_policy' => 'none',
        ]);
        $location = Location::query()->create([
            'studio_id' => $studio->getKey(), 'name' => 'Studio', 'kind' => 'physical', 'timezone' => 'UTC',
        ]);
        $series = EventSeries::query()->create([
            'studio_id' => $studio->getKey(), 'service_id' => $service->getKey(), 'location_id' => $location->getKey(),
            'kind' => 'private_lesson', 'status' => 'active', 'visibility' => 'studio', 'title' => 'Piano lesson',
            'timezone' => 'UTC', 'dtstart_local' => '2026-08-12T10:00:00', 'duration_minutes' => 60, 'capacity' => 2,
        ]);
        $occurrence = EventOccurrence::query()->create([
            'studio_id' => $studio->getKey(), 'event_series_id' => $series->getKey(), 'location_id' => $location->getKey(),
            'public_uid' => Str::uuid(), 'recurrence_id_local' => '2026-08-12T10:00:00',
            'starts_at' => now()->subDay()->startOfHour(), 'ends_at' => now()->subDay()->startOfHour()->addHour(),
            'utc_offset_minutes' => 0, 'timezone' => 'UTC', 'status' => 'completed', 'title' => 'Piano lesson',
            'kind' => 'private_lesson', 'capacity' => 2, 'price_minor' => 5000, 'currency' => 'USD',
        ]);
        EventOccurrenceTeacher::query()->create([
            'studio_id' => $studio->getKey(), 'event_occurrence_id' => $occurrence->getKey(),
            'staff_profile_id' => $staff->getKey(), 'role' => 'lead', 'status' => 'assigned',
            'busy_starts_at' => $occurrence->starts_at, 'busy_ends_at' => $occurrence->ends_at,
        ]);
        $student = Person::factory()->create(['studio_id' => $studio->getKey()]);
        $participant = EventOccurrenceParticipant::query()->create([
            'studio_id' => $studio->getKey(), 'event_occurrence_id' => $occurrence->getKey(),
            'event_series_id' => $series->getKey(), 'person_id' => $student->getKey(), 'role' => 'student',
            'status' => 'confirmed', 'blocks_conflicts' => true,
            'busy_starts_at' => $occurrence->starts_at, 'busy_ends_at' => $occurrence->ends_at,
        ]);
        Sanctum::actingAs($teacher);
        $note = $this->postJson("/api/v1/studios/{$studio->slug}/occurrences/{$occurrence->getKey()}/notes", [
            'scope' => 'participant', 'participant_id' => $participant->getKey(),
            'audience' => 'student', 'body_html' => '<p>Practice this piece.</p>',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');

        return [$teacher, $studio, $note, $participant];
    }
}
