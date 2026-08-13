<?php

namespace Tests\Feature\Api;

use App\Enums\MembershipRole;
use App\Enums\StaffRole;
use App\Enums\StaffStatus;
use App\Models\AttendanceCorrection;
use App\Models\EventOccurrence;
use App\Models\EventOccurrenceParticipant;
use App\Models\EventOccurrenceTeacher;
use App\Models\EventSeries;
use App\Models\LessonNoteRevision;
use App\Models\Location;
use App\Models\Person;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\StaffAccountLink;
use App\Models\StaffProfile;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AttendanceNotesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_teacher_can_record_correct_bulk_and_express_attendance_atomically(): void
    {
        [$owner, $teacher, $studio, $occurrence, $participants] = $this->context();
        Sanctum::actingAs($teacher);
        $base = "/api/v1/studios/{$studio->slug}/occurrences/{$occurrence->getKey()}";
        $first = $this->putJson("{$base}/participants/{$participants[0]->getKey()}/attendance", [
            'outcome' => 'late', 'minutes_late' => 8,
        ], ['Idempotency-Key' => 'attendance-one'])->assertCreated()->assertJsonPath('data.outcome', 'late')->json('data');
        $this->putJson("{$base}/participants/{$participants[0]->getKey()}/attendance", [
            'version' => $first['version'], 'outcome' => 'present',
            'minutes_late' => 0, 'correction_reason' => 'Teacher corrected roll.',
        ], ['Idempotency-Key' => 'attendance-two'])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson("{$base}/participants/{$participants[0]->getKey()}/attendance", [
            'outcome' => 'late', 'minutes_late' => 8,
        ], ['Idempotency-Key' => 'attendance-one'])->assertOk()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.outcome', 'late');
        $this->assertDatabaseCount('attendance_corrections', 1);
        $this->assertDatabaseHas('scheduling_outbox_messages', [
            'aggregate_type' => 'attendance_record', 'aggregate_version' => 2,
        ]);

        $this->postJson("{$base}/attendance/express-present", [], ['Idempotency-Key' => 'attendance-express'])
            ->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('attendance_records', 2);

        Sanctum::actingAs($owner);
        $this->getJson("{$base}/attendance")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_invalid_disposition_matrix_and_cross_occurrence_subject_are_rejected(): void
    {
        [, $teacher, $studio, $occurrence, $participants] = $this->context();
        Sanctum::actingAs($teacher);
        $base = "/api/v1/studios/{$studio->slug}/occurrences/{$occurrence->getKey()}";
        $this->putJson("{$base}/participants/{$participants[0]->getKey()}/attendance", [
            'outcome' => 'present', 'billing_disposition' => 'credit', 'makeup_disposition' => 'required',
        ], ['Idempotency-Key' => 'invalid-disposition'])->assertUnprocessable()
            ->assertJsonValidationErrors(['billing_disposition', 'makeup_disposition']);

        [, , , $otherOccurrence, $otherParticipants] = $this->context();
        $this->putJson("{$base}/participants/{$otherParticipants[0]->getKey()}/attendance", [
            'outcome' => 'present',
        ], ['Idempotency-Key' => 'cross-occurrence'])->assertNotFound();
        $this->assertNotSame($occurrence->getKey(), $otherOccurrence->getKey());
    }

    public function test_notes_are_sanitized_revisioned_private_and_delivery_is_one_shot_idempotent(): void
    {
        [, $teacher, $studio, $occurrence, $participants] = $this->context();
        Sanctum::actingAs($teacher);
        $base = "/api/v1/studios/{$studio->slug}";
        $note = $this->postJson("{$base}/occurrences/{$occurrence->getKey()}/notes", [
            'scope' => 'participant', 'participant_id' => $participants[0]->getKey(),
            'audience' => 'student', 'title' => 'Practice',
            'body_html' => '<p>Practice scales</p><script>alert(1)</script><img src="https://tracker.test/pixel">',
        ], ['Idempotency-Key' => 'note-create-1'])->assertCreated()
            ->assertJsonMissing(['script'])
            ->json('data');
        $this->assertStringNotContainsString('<script', $note['body_html']);
        $this->assertStringNotContainsString('<img', $note['body_html']);

        $this->patchJson("{$base}/notes/{$note['id']}", [
            'version' => 1, 'body_html' => '<p>Practice scales slowly</p>', 'reason' => 'Added tempo guidance.',
        ], ['Idempotency-Key' => 'note-revise-1'])->assertOk()->assertJsonPath('data.current_revision', 2);
        $this->assertDatabaseCount('lesson_note_revisions', 2);
        $this->postJson("{$base}/occurrences/{$occurrence->getKey()}/notes", [
            'scope' => 'participant', 'participant_id' => $participants[0]->getKey(),
            'audience' => 'student', 'title' => 'Practice',
            'body_html' => '<p>Practice scales</p><script>alert(1)</script><img src="https://tracker.test/pixel">',
        ], ['Idempotency-Key' => 'note-create-1'])->assertOk()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.current_revision', 1)
            ->assertJsonPath('data.body_html', '<p>Practice scales</p>');

        $preview = $this->postJson("{$base}/notes/{$note['id']}/delivery-previews")
            ->assertUnprocessable()->assertJsonValidationErrors('recipients');

        $student = Person::query()->findOrFail($participants[0]->person_id);
        $billingUser = User::factory()->create(['email_verified_at' => now()]);
        StudioMembership::factory()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $billingUser->getKey(), 'role' => MembershipRole::Billing,
        ]);
        $student->user_id = $billingUser->getKey();
        $student->save();
        $this->postJson("{$base}/notes/{$note['id']}/delivery-previews")
            ->assertUnprocessable()->assertJsonValidationErrors('recipients');
        $student->user_id = $teacher->getKey();
        $student->save();
        $preview = $this->postJson("{$base}/notes/{$note['id']}/delivery-previews")
            ->assertCreated()->assertJsonPath('data.recipient_count', 1)->json('data.id');
        $url = "{$base}/note-delivery-previews/{$preview}/commit";
        $first = $this->postJson($url, [], ['Idempotency-Key' => 'note-delivery-1'])
            ->assertCreated()->json('data.id');
        $this->postJson($url, [], ['Idempotency-Key' => 'note-delivery-1'])
            ->assertOk()->assertJsonPath('data.id', $first);
        $this->assertDatabaseCount('lesson_note_delivery_intents', 1);
        $this->assertDatabaseCount('scheduling_outbox_messages', 1);

        $private = $this->postJson("{$base}/occurrences/{$occurrence->getKey()}/notes", [
            'scope' => 'group', 'audience' => 'author_private', 'body_html' => '<p>Private coaching observation</p>',
        ], ['Idempotency-Key' => 'private-note-create'])->assertCreated()->json('data.id');
        $otherTeacher = User::factory()->create(['email_verified_at' => now()]);
        StudioMembership::factory()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $otherTeacher->getKey(), 'role' => MembershipRole::Teacher,
        ]);
        Sanctum::actingAs($otherTeacher);
        $this->getJson("{$base}/occurrences/{$occurrence->getKey()}/notes")
            ->assertForbidden()->assertJsonMissing(['id' => $private]);
    }

    public function test_correction_and_revision_history_are_database_immutable(): void
    {
        [, $teacher, $studio, $occurrence, $participants] = $this->context();
        Sanctum::actingAs($teacher);
        $url = "/api/v1/studios/{$studio->slug}/occurrences/{$occurrence->getKey()}/participants/{$participants[0]->getKey()}/attendance";
        $this->putJson($url, [
            'outcome' => 'late', 'minutes_late' => 5,
        ], ['Idempotency-Key' => 'immutable-attendance-1'])->assertCreated();
        $this->putJson($url, [
            'version' => 1, 'outcome' => 'present',
            'correction_reason' => 'Corrected.',
        ], ['Idempotency-Key' => 'immutable-attendance-2'])->assertOk();

        $noteUrl = "/api/v1/studios/{$studio->slug}/occurrences/{$occurrence->getKey()}/notes";
        $note = $this->postJson($noteUrl, [
            'scope' => 'group', 'audience' => 'author_private', 'body_html' => '<p>First</p>',
        ], ['Idempotency-Key' => 'immutable-note-1'])->assertCreated()->json('data');
        $this->patchJson("/api/v1/studios/{$studio->slug}/notes/{$note['id']}", [
            'version' => 1, 'body_html' => '<p>Second</p>',
        ], ['Idempotency-Key' => 'immutable-note-2'])->assertOk();

        foreach ([AttendanceCorrection::query()->firstOrFail(), LessonNoteRevision::query()->first()] as $record) {
            if ($record === null) {
                continue;
            }
            $this->assertDatabaseMutationRejected(fn () => DB::table($record->getTable())
                ->where('id', $record->getKey())->update(['id' => (string) Str::ulid()]));
        }

        foreach (['attendance_records', 'lesson_notes'] as $table) {
            $this->assertDatabaseMutationRejected(fn () => DB::table($table)->update(['version' => 99]));
        }
    }

    public function test_templates_are_sanitized_versioned_unique_and_safely_retired(): void
    {
        [$owner, , $studio] = $this->context();
        Sanctum::actingAs($owner);
        $base = "/api/v1/studios/{$studio->slug}/note-templates";
        $template = $this->postJson($base, [
            'name' => '  Weekly   recap ', 'audience' => 'guardian',
            'body_html' => '<p>Progress summary</p><img src="https://tracker.test/pixel">',
        ], ['Idempotency-Key' => 'template-create'])->assertCreated()->assertJsonPath('data.name', 'Weekly recap')->json('data');
        $this->assertStringNotContainsString('<img', $template['body_html']);

        $this->postJson($base, [
            'name' => 'weekly recap', 'audience' => 'student', 'body_html' => '<p>Duplicate</p>',
        ], ['Idempotency-Key' => 'template-duplicate'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson("{$base}/{$template['id']}", [
            'version' => 1, 'active' => false, 'reason' => 'Retired old wording.',
        ], ['Idempotency-Key' => 'template-update'])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.active', false);
        $this->patchJson("{$base}/{$template['id']}", [
            'version' => 1, 'active' => true, 'reason' => 'Stale attempt.',
        ], ['Idempotency-Key' => 'template-stale'])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->postJson($base, [
            'name' => '  Weekly   recap ', 'audience' => 'guardian',
            'body_html' => '<p>Progress summary</p><img src="https://tracker.test/pixel">',
        ], ['Idempotency-Key' => 'template-create'])->assertOk()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.active', true)
            ->assertJsonPath('data.body_html', '<p>Progress summary</p>');
        $this->assertDatabaseCount('lesson_note_template_revisions', 2);
    }

    public function test_billing_reads_only_financial_attendance_projection_and_cannot_read_notes(): void
    {
        [, $teacher, $studio, $occurrence, $participants] = $this->context();
        $base = "/api/v1/studios/{$studio->slug}/occurrences/{$occurrence->getKey()}";
        Sanctum::actingAs($teacher);
        $this->putJson("{$base}/participants/{$participants[0]->getKey()}/attendance", [
            'outcome' => 'absent_excused', 'reason' => 'Private health context.',
        ], ['Idempotency-Key' => 'billing-redaction-attendance'])->assertCreated();
        $this->postJson("{$base}/notes", [
            'scope' => 'participant', 'participant_id' => $participants[0]->getKey(),
            'audience' => 'guardian', 'body_html' => '<p>Private learning detail.</p>',
        ], ['Idempotency-Key' => 'billing-redaction-note'])->assertCreated();

        $billing = User::factory()->create(['email_verified_at' => now()]);
        StudioMembership::factory()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $billing->getKey(), 'role' => MembershipRole::Billing,
        ]);
        Sanctum::actingAs($billing);
        $this->getJson("{$base}/attendance")->assertOk()
            ->assertJsonPath('data.0.billing_disposition', 'pending_review')
            ->assertJsonPath('data.0.person_id', null)
            ->assertJsonPath('data.0.reason', null)
            ->assertJsonPath('data.0.minutes_late', null)
            ->assertJsonPath('data.0.makeup_disposition', null);
        $this->getJson("{$base}/notes")->assertForbidden();
    }

    public function test_delivery_commit_rejects_recipient_drift_and_idempotency_key_reuse(): void
    {
        [, $teacher, $studio, $occurrence, $participants] = $this->context();
        Sanctum::actingAs($teacher);
        $base = "/api/v1/studios/{$studio->slug}";
        $person = Person::query()->findOrFail($participants[0]->person_id);
        $person->user_id = $teacher->getKey();
        $person->save();
        $note = $this->postJson("{$base}/occurrences/{$occurrence->getKey()}/notes", [
            'scope' => 'participant', 'participant_id' => $participants[0]->getKey(),
            'audience' => 'student', 'body_html' => '<p>Recipient drift.</p>',
        ], ['Idempotency-Key' => 'drift-note'])->assertCreated()->json('data');
        $preview = $this->postJson("{$base}/notes/{$note['id']}/delivery-previews")
            ->assertCreated()->json('data.id');
        $person->user_id = null;
        $person->save();
        $this->postJson("{$base}/note-delivery-previews/{$preview}/commit", [], ['Idempotency-Key' => 'drift-delivery'])
            ->assertConflict()->assertJsonPath('code', 'recipients_changed_after_preview');

        $this->putJson(
            "{$base}/occurrences/{$occurrence->getKey()}/participants/{$participants[0]->getKey()}/attendance",
            ['outcome' => 'present'],
            ['Idempotency-Key' => 'shared-key'],
        )->assertCreated();
        $this->putJson(
            "{$base}/occurrences/{$occurrence->getKey()}/participants/{$participants[1]->getKey()}/attendance",
            ['outcome' => 'present'],
            ['Idempotency-Key' => 'shared-key'],
        )->assertConflict()->assertJsonPath('code', 'idempotency_key_reused');
    }

    public function test_teacher_cancelled_is_limited_to_canonical_canceled_participants_and_uses_policy_projection(): void
    {
        [, $teacher, $studio, $occurrence, $participants] = $this->context();
        Sanctum::actingAs($teacher);
        $url = "/api/v1/studios/{$studio->slug}/occurrences/{$occurrence->getKey()}/participants/{$participants[0]->getKey()}/attendance";
        $this->putJson($url, ['outcome' => 'teacher_cancelled'], ['Idempotency-Key' => 'invalid-teacher-cancel'])
            ->assertUnprocessable()->assertJsonValidationErrors('occurrence');

        $occurrence->update(['status' => 'canceled', 'policy_snapshot' => ['makeup_policy' => 'none']]);
        $participants[0]->update(['previous_status' => 'confirmed', 'status' => 'canceled', 'blocks_conflicts' => false]);
        $this->putJson($url, ['outcome' => 'teacher_cancelled'], ['Idempotency-Key' => 'valid-teacher-cancel'])
            ->assertCreated()
            ->assertJsonPath('data.makeup_disposition', 'waived');
        $this->assertDatabaseHas('attendance_records', [
            'event_occurrence_participant_id' => $participants[0]->getKey(),
            'billing_disposition' => 'pending_review', 'makeup_disposition' => 'waived',
        ]);

        $student = Person::query()->findOrFail($participants[0]->person_id);
        $studentUser = User::factory()->create(['email_verified_at' => now()]);
        StudioMembership::factory()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $studentUser->getKey(), 'role' => MembershipRole::Teacher,
        ]);
        $student->user_id = $studentUser->getKey();
        $student->save();
        Sanctum::actingAs($studentUser);
        $this->getJson("/api/v1/studios/{$studio->slug}/occurrences/{$occurrence->getKey()}/attendance")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.outcome', 'teacher_cancelled');
    }

    private function context(?Studio $studio = null): array
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $teacher = User::factory()->create(['email_verified_at' => now()]);
        $studio ??= Studio::factory()->create();
        StudioMembership::factory()->owner()->create(['studio_id' => $studio->getKey(), 'user_id' => $owner->getKey()]);
        $membership = StudioMembership::factory()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $teacher->getKey(), 'role' => MembershipRole::Teacher,
        ]);
        $category = ServiceCategory::query()->firstOrCreate(['studio_id' => $studio->getKey(), 'normalized_name' => 'lessons'], ['name' => 'Lessons']);
        $service = Service::query()->firstOrCreate([
            'studio_id' => $studio->getKey(), 'normalized_name' => 'piano',
        ], [
            'service_category_id' => $category->getKey(), 'name' => 'Piano', 'default_duration_minutes' => 60,
            'default_capacity' => 4, 'default_price_minor' => 5000, 'currency' => 'USD', 'makeup_policy' => 'none',
        ]);
        $location = Location::query()->firstOrCreate([
            'studio_id' => $studio->getKey(), 'normalized_name' => 'main',
        ], ['name' => 'Main', 'kind' => 'physical', 'timezone' => 'UTC']);
        $staffPerson = Person::factory()->create(['studio_id' => $studio->getKey()]);
        $staff = StaffProfile::query()->create([
            'studio_id' => $studio->getKey(), 'person_id' => $staffPerson->getKey(),
            'roles' => [StaffRole::Teacher], 'status' => StaffStatus::Active,
        ]);
        StaffAccountLink::query()->create([
            'studio_id' => $studio->getKey(), 'staff_profile_id' => $staff->getKey(),
            'studio_membership_id' => $membership->getKey(),
        ]);
        $series = EventSeries::query()->create([
            'studio_id' => $studio->getKey(), 'service_id' => $service->getKey(), 'location_id' => $location->getKey(),
            'kind' => 'group_class', 'status' => 'active', 'visibility' => 'studio', 'title' => 'Lesson',
            'timezone' => 'UTC', 'dtstart_local' => '2026-08-12T10:00:00', 'duration_minutes' => 60, 'capacity' => 4,
        ]);
        $occurrence = EventOccurrence::query()->create([
            'studio_id' => $studio->getKey(), 'event_series_id' => $series->getKey(), 'location_id' => $location->getKey(),
            'public_uid' => Str::uuid(), 'recurrence_id_local' => '2026-08-12T10:00:00',
            'starts_at' => now()->subDay()->startOfHour(), 'ends_at' => now()->subDay()->startOfHour()->addHour(),
            'utc_offset_minutes' => 0, 'timezone' => 'UTC', 'status' => 'completed', 'title' => 'Lesson', 'kind' => 'group_class',
            'capacity' => 4, 'price_minor' => 5000, 'currency' => 'USD',
        ]);
        EventOccurrenceTeacher::query()->create([
            'studio_id' => $studio->getKey(), 'event_occurrence_id' => $occurrence->getKey(),
            'staff_profile_id' => $staff->getKey(), 'role' => 'lead', 'status' => 'assigned',
            'busy_starts_at' => $occurrence->starts_at, 'busy_ends_at' => $occurrence->ends_at,
        ]);
        $participants = collect(range(1, 2))->map(function () use ($studio, $series, $occurrence): EventOccurrenceParticipant {
            $person = Person::factory()->create(['studio_id' => $studio->getKey()]);

            return EventOccurrenceParticipant::query()->create([
                'studio_id' => $studio->getKey(), 'event_occurrence_id' => $occurrence->getKey(),
                'event_series_id' => $series->getKey(), 'person_id' => $person->getKey(), 'role' => 'student',
                'status' => 'confirmed', 'blocks_conflicts' => true,
                'busy_starts_at' => $occurrence->starts_at, 'busy_ends_at' => $occurrence->ends_at,
            ]);
        })->all();

        return [$owner, $teacher, $studio, $occurrence, $participants];
    }

    private function assertDatabaseMutationRejected(callable $mutation): void
    {
        try {
            DB::transaction(function () use ($mutation): void {
                $mutation();
                $this->fail('A protected attendance or note record accepted direct mutation.');
            });
        } catch (QueryException $exception) {
            $this->assertTrue(
                str_contains(mb_strtolower($exception->getMessage()), 'immutable')
                || str_contains(mb_strtolower($exception->getMessage()), 'history'),
                $exception->getMessage(),
            );
        }
    }
}
