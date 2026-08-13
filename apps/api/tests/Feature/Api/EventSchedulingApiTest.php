<?php

namespace Tests\Feature\Api;

use App\Actions\Scheduling\MaterializeEventSeries;
use App\Enums\MembershipRole;
use App\Enums\StaffRole;
use App\Enums\StaffStatus;
use App\Jobs\ExtendEventSeriesHorizon;
use App\Models\EventOccurrence;
use App\Models\EventSeries;
use App\Models\Location;
use App\Models\Person;
use App\Models\Room;
use App\Models\ScheduleChangeEvent;
use App\Models\ScheduleChangePreview;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\StaffAccountLink;
use App\Models\StaffAvailabilityWindow;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Tenancy\RequestDatabaseContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class EventSchedulingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_previews_and_commits_a_series_from_the_server_stored_command(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $payload = $this->payload($service, $location, $room, $staff);

        $preview = $this->actingAs($owner)->postJson("/api/v1/studios/{$studio->slug}/event-series/previews", $payload)
            ->assertCreated()->assertJsonPath('data.status', 'ready')->json('data.id');

        $response = $this->postJson(
            "/api/v1/studios/{$studio->slug}/event-series/previews/{$preview}/commit",
            [],
            ['Idempotency-Key' => 'series-create-1'],
        )->assertCreated()->assertJsonPath('data.title', 'Piano Lab');

        self::assertCount(3, $response->json('data.occurrences'));
        $this->assertDatabaseHas('schedule_change_previews', ['id' => $preview, 'consumed_at' => now()]);
        $this->assertDatabaseCount('schedule_change_events', 1);
        $this->assertDatabaseCount('scheduling_outbox_messages', 1);
    }

    public function test_commit_rejects_client_command_and_preview_is_one_shot(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $preview = $this->actingAs($owner)->postJson(
            "/api/v1/studios/{$studio->slug}/event-series/previews",
            $this->payload($service, $location, $room, $staff),
        )->json('data.id');
        $url = "/api/v1/studios/{$studio->slug}/event-series/previews/{$preview}/commit";

        $this->postJson($url, ['command' => ['title' => 'Tampered']], ['Idempotency-Key' => 'tamper'])
            ->assertUnprocessable()->assertJsonValidationErrors('command');
        $this->postJson($url, [], ['Idempotency-Key' => 'clean'])->assertCreated();
        $this->postJson($url, [], ['Idempotency-Key' => 'different'])
            ->assertConflict()->assertJsonPath('code', 'preview_consumed');
        $this->postJson($url, [], ['Idempotency-Key' => 'clean'])->assertCreated();

        $otherPreview = $this->postJson(
            "/api/v1/studios/{$studio->slug}/event-series/previews",
            $this->payload($service, $location, $room, $staff),
        )->json('data.id');
        $this->postJson(
            "/api/v1/studios/{$studio->slug}/event-series/previews/{$otherPreview}/commit",
            [],
            ['Idempotency-Key' => 'clean'],
        )->assertConflict()->assertJsonPath('code', 'idempotency_key_reused');
    }

    public function test_explicit_gap_hold_bounds_and_blank_title_are_rejected(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $payload = $this->payload($service, $location, $room, $staff);
        $payload['title'] = '   ';
        $payload['timezone'] = 'America/New_York';
        $payload['dtstart_local'] = '2027-03-14T02:30:00';
        $payload['hold_expires_at'] = now()->addDays(8)->toAtomString();

        $this->actingAs($owner)->postJson("/api/v1/studios/{$studio->slug}/event-series/previews", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'hold_expires_at']);
    }

    public function test_teacher_sees_only_assigned_calendar_and_billing_receives_redacted_fields(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $online = Location::query()->create([
            'studio_id' => $studio->getKey(), 'name' => 'Piano video room', 'kind' => 'online',
            'timezone' => 'America/Bogota', 'online_url' => 'https://meet.example.test/piano',
        ]);
        $payload = $this->payload($service, $online, $room, $staff);
        $payload['room_ids'] = [];
        $preview = $this->actingAs($owner)->postJson(
            "/api/v1/studios/{$studio->slug}/event-series/previews",
            $payload,
        )->json('data.id');
        $seriesId = $this->postJson(
            "/api/v1/studios/{$studio->slug}/event-series/previews/{$preview}/commit",
            [], ['Idempotency-Key' => 'privacy-create'],
        )->json('data.id');
        $teacher = User::factory()->create(['email_verified_at' => now()]);
        $teacherMembership = StudioMembership::factory()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $teacher->getKey(), 'role' => MembershipRole::Teacher,
        ]);
        StaffAccountLink::query()->create([
            'studio_id' => $studio->getKey(), 'studio_membership_id' => $teacherMembership->getKey(),
            'staff_profile_id' => $staff->getKey(),
        ]);
        $billing = User::factory()->create(['email_verified_at' => now()]);
        StudioMembership::factory()->create(['studio_id' => $studio->getKey(), 'user_id' => $billing->getKey(), 'role' => MembershipRole::Billing]);

        $this->actingAs($billing)->getJson("/api/v1/studios/{$studio->slug}/event-series/{$seriesId}")
            ->assertOk()->assertJsonPath('data.internal_description', null);
        $calendar = $this->getJson("/api/v1/studios/{$studio->slug}/calendar?from=2027-01-01T00:00:00Z&to=2027-02-01T00:00:00Z")
            ->assertOk()->assertJsonMissingPath('data.0.price_minor')->assertJsonMissingPath('data.0.policy')
            ->assertJsonMissingPath('data.0.location_id')->assertJsonPath('data.0.online_join_url', null)
            ->assertJsonCount(0, 'data.0.teachers')->assertJsonCount(0, 'data.0.participants');
        self::assertNotEmpty($calendar->json('data'));

        $this->getJson("/api/v1/studios/{$studio->slug}/calendar?from=2027-01-01T00:00:00Z&to=2027-02-01T00:00:00Z&room_ids[]={$room->getKey()}")
            ->assertUnprocessable()->assertJsonValidationErrors('room_ids');
        $this->actingAs($teacher)->getJson("/api/v1/studios/{$studio->slug}/calendar?from=2027-01-01T00:00:00Z&to=2027-02-01T00:00:00Z&q=Piano")
            ->assertOk()->assertJsonPath('data.0.online_join_url', 'https://meet.example.test/piano')
            ->assertJsonPath('data.0.teachers.0.staff_profile_id', $staff->getKey());
    }

    public function test_public_visibility_publishes_only_a_minimal_unauthenticated_calendar_projection(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $payload = $this->payload($service, $location, $room, $staff);
        $payload['visibility'] = 'public';
        $payload['shared_description'] = 'Everyone is welcome.';
        $this->createSeries($owner, $studio, $payload, 'public-calendar');
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/public/studios/{$studio->slug}/calendar?from=2027-01-01T00:00:00Z&to=2027-02-01T00:00:00Z")
            ->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.shared_description', 'Everyone is welcome.')
            ->assertJsonMissingPath('data.0.id')->assertJsonMissingPath('data.0.series_id')
            ->assertJsonMissingPath('data.0.internal_description')->assertJsonMissingPath('data.0.online_join_url')
            ->assertJsonMissingPath('data.0.location_id')->assertJsonMissingPath('data.0.teachers');
    }

    public function test_one_scope_reschedule_preserves_occurrence_identity_and_public_uid(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $series = $this->createSeries($owner, $studio, $this->payload($service, $location, $room, $staff), 'one-create');
        $occurrence = EventOccurrence::query()->where('event_series_id', $series)->orderBy('starts_at')->firstOrFail();
        $id = $occurrence->getKey();
        $uid = $occurrence->public_uid;
        $preview = $this->actingAs($owner)->postJson(
            "/api/v1/studios/{$studio->slug}/occurrences/{$id}/reschedule/previews",
            ['version' => $occurrence->version, 'scope' => 'one', 'starts_at_local' => '2027-01-04T11:00:00', 'start_resolution' => 'reject', 'reason' => 'Requested'],
        )->assertCreated()->json('data.id');
        $this->postJson("/api/v1/studios/{$studio->slug}/schedule/previews/{$preview}/commit", [], ['Idempotency-Key' => 'one-move'])
            ->assertOk();

        $occurrence->refresh();
        self::assertSame($id, $occurrence->getKey());
        self::assertSame($uid, $occurrence->public_uid);
        self::assertSame('2027-01-04T16:00:00+00:00', $occurrence->starts_at->toAtomString());
        $this->assertDatabaseHas('event_occurrence_overrides', ['event_occurrence_id' => $id, 'type' => 'modified']);
    }

    public function test_one_scope_move_rebinds_location_resources_and_recomputes_the_offset(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $series = $this->createSeries($owner, $studio, $this->payload($service, $location, $room, $staff), 'move-create');
        $occurrence = EventOccurrence::query()->where('event_series_id', $series)->orderBy('starts_at')->firstOrFail();
        $uid = $occurrence->public_uid;
        $west = Location::query()->create([
            'studio_id' => $studio->getKey(), 'name' => 'West studio', 'kind' => 'physical',
            'timezone' => 'America/Los_Angeles',
        ]);
        $westRoom = Room::query()->create([
            'studio_id' => $studio->getKey(), 'location_id' => $west->getKey(), 'name' => 'West A', 'capacity' => 4,
        ]);
        $url = "/api/v1/studios/{$studio->slug}/occurrences/{$occurrence->getKey()}/reschedule/previews";

        $this->actingAs($owner)->postJson($url, [
            'version' => $occurrence->version, 'scope' => 'one', 'location_id' => $west->getKey(),
            'start_resolution' => 'reject',
        ])->assertUnprocessable()->assertJsonValidationErrors('room_ids');

        $response = $this->postJson($url, [
            'version' => $occurrence->version, 'scope' => 'one', 'location_id' => $west->getKey(),
            'start_resolution' => 'reject', 'room_ids' => [$westRoom->getKey()], 'equipment' => [],
        ])->assertCreated();
        self::assertSame('ready', $response->json('data.status'), json_encode($response->json('data.conflicts')));
        $preview = $response->json('data.id');
        $this->postJson(
            "/api/v1/studios/{$studio->slug}/schedule/previews/{$preview}/commit",
            [],
            ['Idempotency-Key' => 'move-one'],
        )->assertOk();

        $occurrence->refresh();
        self::assertSame($uid, $occurrence->public_uid);
        self::assertSame($west->getKey(), $occurrence->location_id);
        self::assertSame('America/Los_Angeles', $occurrence->timezone);
        self::assertSame(-480, $occurrence->utc_offset_minutes);
        self::assertSame('2027-01-04T18:00:00+00:00', $occurrence->starts_at->toAtomString());
        $this->assertDatabaseMissing('event_occurrence_rooms', [
            'event_occurrence_id' => $occurrence->getKey(), 'room_id' => $room->getKey(),
        ]);
        $this->assertDatabaseHas('event_occurrence_rooms', [
            'event_occurrence_id' => $occurrence->getKey(), 'room_id' => $westRoom->getKey(), 'status' => 'assigned',
        ]);
    }

    public function test_one_scope_can_substitute_an_active_teacher_without_leaving_the_old_reservation_blocking(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $series = $this->createSeries($owner, $studio, $this->payload($service, $location, $room, $staff), 'substitute-create');
        $occurrence = EventOccurrence::query()->where('event_series_id', $series)->firstOrFail();
        $person = Person::factory()->create(['studio_id' => $studio->getKey()]);
        $substitute = StaffProfile::query()->create([
            'studio_id' => $studio->getKey(), 'person_id' => $person->getKey(),
            'roles' => [StaffRole::Teacher->value, StaffRole::Substitute->value], 'status' => StaffStatus::Active,
        ]);
        $preview = $this->actingAs($owner)->postJson(
            "/api/v1/studios/{$studio->slug}/occurrences/{$occurrence->getKey()}/reschedule/previews",
            [
                'version' => $occurrence->version, 'scope' => 'one',
                'teachers' => [['staff_profile_id' => $substitute->getKey(), 'role' => 'substitute']],
                'reason' => 'Lead teacher unavailable',
            ],
        )->assertCreated()->assertJsonPath('data.status', 'ready')->json('data.id');
        $this->postJson(
            "/api/v1/studios/{$studio->slug}/schedule/previews/{$preview}/commit",
            [],
            ['Idempotency-Key' => 'substitute-one'],
        )->assertOk();

        $this->assertDatabaseHas('event_occurrence_teachers', [
            'event_occurrence_id' => $occurrence->getKey(), 'staff_profile_id' => $staff->getKey(), 'status' => 'canceled',
        ]);
        $this->assertDatabaseHas('event_occurrence_teachers', [
            'event_occurrence_id' => $occurrence->getKey(), 'staff_profile_id' => $substitute->getKey(),
            'status' => 'assigned', 'role' => 'substitute',
        ]);

        $occurrence->refresh();
        $cancel = $this->postJson(
            "/api/v1/studios/{$studio->slug}/occurrences/{$occurrence->getKey()}/cancel/previews",
            ['version' => $occurrence->version, 'scope' => 'one', 'reason' => 'Temporary cancellation'],
        )->assertCreated()->json('data.id');
        $this->postJson(
            "/api/v1/studios/{$studio->slug}/schedule/previews/{$cancel}/commit", [], ['Idempotency-Key' => 'substitute-cancel'],
        )->assertOk();
        $occurrence->refresh();
        $restore = $this->postJson(
            "/api/v1/studios/{$studio->slug}/occurrences/{$occurrence->getKey()}/restore/previews",
            ['version' => $occurrence->version, 'scope' => 'one', 'reason' => 'Reopened'],
        )->assertCreated()->json('data.id');
        $this->postJson(
            "/api/v1/studios/{$studio->slug}/schedule/previews/{$restore}/commit", [], ['Idempotency-Key' => 'substitute-restore'],
        )->assertOk();
        $this->assertDatabaseHas('event_occurrence_teachers', [
            'event_occurrence_id' => $occurrence->getKey(), 'staff_profile_id' => $staff->getKey(), 'status' => 'canceled',
        ]);
        $this->assertDatabaseHas('event_occurrence_teachers', [
            'event_occurrence_id' => $occurrence->getKey(), 'staff_profile_id' => $substitute->getKey(), 'status' => 'assigned',
        ]);
    }

    public function test_future_cancel_releases_reservations_and_keeps_completed_history(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $series = $this->createSeries($owner, $studio, $this->payload($service, $location, $room, $staff), 'cancel-create');
        $occurrences = EventOccurrence::query()->where('event_series_id', $series)->orderBy('starts_at')->get();
        $preview = $this->actingAs($owner)->postJson(
            "/api/v1/studios/{$studio->slug}/occurrences/{$occurrences[1]->getKey()}/cancel/previews",
            ['version' => $occurrences[1]->version, 'scope' => 'future', 'reason' => 'Studio closed', 'makeup_required' => true, 'makeup_reference' => 'weather'],
        )->assertCreated()->json('data.id');
        $this->postJson("/api/v1/studios/{$studio->slug}/schedule/previews/{$preview}/commit", [], ['Idempotency-Key' => 'future-cancel'])
            ->assertOk();

        self::assertSame('scheduled', $occurrences[0]->refresh()->status->value);
        self::assertSame('canceled', $occurrences[1]->refresh()->status->value);
        self::assertTrue($occurrences[1]->makeup_required);
        $this->assertDatabaseHas('event_occurrence_teachers', ['event_occurrence_id' => $occurrences[1]->getKey(), 'status' => 'canceled']);
        self::assertSame(true, ScheduleChangeEvent::query()->where('event_type', 'schedule.cancel')->firstOrFail()->payload['makeup_required']);
    }

    public function test_version_drift_is_a_typed_conflict_and_preview_is_not_consumed(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $series = $this->createSeries($owner, $studio, $this->payload($service, $location, $room, $staff), 'drift-create');
        $occurrence = EventOccurrence::query()->where('event_series_id', $series)->firstOrFail();
        $preview = $this->actingAs($owner)->postJson(
            "/api/v1/studios/{$studio->slug}/occurrences/{$occurrence->getKey()}/reschedule/previews",
            ['version' => $occurrence->version, 'scope' => 'one', 'title' => 'Renamed'],
        )->json('data.id');
        $occurrence->increment('version');

        $this->postJson("/api/v1/studios/{$studio->slug}/schedule/previews/{$preview}/commit", [], ['Idempotency-Key' => 'drift'])
            ->assertConflict()->assertJsonPath('code', 'schedule_changed_after_preview');
        $this->assertDatabaseHas('schedule_change_previews', ['id' => $preview, 'consumed_at' => null]);
    }

    public function test_hold_conversion_release_and_replay_are_idempotent(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $payload = $this->payload($service, $location, $room, $staff);
        $payload['hold_expires_at'] = now()->addHour()->toAtomString();
        $seriesId = $this->createSeries($owner, $studio, $payload, 'hold-create');
        $series = EventSeries::query()->findOrFail($seriesId);
        self::assertSame('draft', $series->status->value);
        $url = "/api/v1/studios/{$studio->slug}/event-series/{$seriesId}/hold/convert";
        $this->actingAs($owner)->postJson($url, ['version' => $series->version], ['Idempotency-Key' => 'hold-convert'])->assertOk();
        $this->postJson($url, ['version' => $series->version], ['Idempotency-Key' => 'hold-convert'])->assertOk();
        self::assertSame('active', $series->refresh()->status->value);
        self::assertSame(1, ScheduleChangeEvent::query()->where('event_type', 'schedule.hold_converted')->count());
    }

    public function test_system_scheduler_expires_holds_once_and_queues_bounded_horizon_work_without_a_human_actor(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $payload = $this->payload($service, $location, $room, $staff);
        $payload['hold_expires_at'] = now()->addMinute()->toAtomString();
        $seriesId = $this->createSeries($owner, $studio, $payload, 'scheduler-hold');
        Carbon::setTestNow(now()->addMinutes(2));

        try {
            self::assertSame(0, Artisan::call('scheduling:expire-holds'));
            self::assertSame(0, Artisan::call('scheduling:expire-holds'));
            self::assertSame('canceled', EventSeries::query()->findOrFail($seriesId)->status->value);
            $event = ScheduleChangeEvent::query()->where('event_type', 'schedule.hold_expired')->firstOrFail();
            self::assertNull($event->actor_id);
            self::assertSame(1, ScheduleChangeEvent::query()->where('event_type', 'schedule.hold_expired')->count());

            $active = $this->createSeries($owner, $studio, [
                ...$this->payload($service, $location, $room, $staff),
                'dtstart_local' => '2027-06-07T10:00:00',
            ], 'scheduler-active');
            Queue::fake();
            self::assertSame(0, Artisan::call('scheduling:extend-horizon', ['--days' => 548]));
            Queue::assertPushed(ExtendEventSeriesHorizon::class, fn (ExtendEventSeriesHorizon $job): bool => $job->eventSeriesId === $active);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_room_and_teacher_hard_conflicts_block_preview_and_commit(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $payload = $this->payload($service, $location, $room, $staff);
        $this->createSeries($owner, $studio, $payload, 'first-conflict');
        $preview = $this->actingAs($owner)->postJson("/api/v1/studios/{$studio->slug}/event-series/previews", $payload)
            ->assertCreated()->assertJsonPath('data.status', 'blocked');
        self::assertContains('teacher_overlap', array_column($preview->json('data.conflicts'), 'code'));

        $this->postJson(
            "/api/v1/studios/{$studio->slug}/event-series/previews/{$preview->json('data.id')}/commit",
            [], ['Idempotency-Key' => 'blocked-commit'],
        )->assertConflict()->assertJsonPath('code', 'hard_scheduling_conflict');
    }

    public function test_enrollment_capacity_waitlist_and_withdrawal_are_consistent(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $payload = $this->payload($service, $location, $room, $staff);
        $payload['capacity'] = 1;
        $series = $this->createSeries($owner, $studio, $payload, 'roster-create');
        $first = Person::factory()->create(['studio_id' => $studio->getKey()]);
        $second = Person::factory()->create(['studio_id' => $studio->getKey()]);
        StudentProfile::query()->create(['studio_id' => $studio->getKey(), 'person_id' => $first->getKey(), 'status' => 'active']);
        StudentProfile::query()->create(['studio_id' => $studio->getKey(), 'person_id' => $second->getKey(), 'status' => 'active']);
        $url = "/api/v1/studios/{$studio->slug}/event-series/{$series}/enrollments/previews";
        $firstPreview = $this->actingAs($owner)->postJson(
            $url, ['person_id' => $first->getKey(), 'status' => 'confirmed'],
        )->assertCreated()->json('data.id');
        $enrollment = $this->postJson(
            "/api/v1/studios/{$studio->slug}/schedule/enrollment-previews/{$firstPreview}/commit",
            [],
            ['Idempotency-Key' => 'enroll-one'],
        )->assertOk()->json('data');
        $blocked = $this->postJson(
            $url, ['person_id' => $second->getKey(), 'status' => 'confirmed'],
        )->assertCreated()->assertJsonPath('data.status', 'blocked')->json('data.id');
        $this->postJson(
            "/api/v1/studios/{$studio->slug}/schedule/enrollment-previews/{$blocked}/commit",
            [],
            ['Idempotency-Key' => 'enroll-two'],
        )->assertConflict()->assertJsonPath('code', 'hard_scheduling_conflict');
        $waitlist = $this->postJson(
            $url, ['person_id' => $second->getKey(), 'status' => 'waitlisted'],
        )->assertCreated()->json('data.id');
        $this->postJson(
            "/api/v1/studios/{$studio->slug}/schedule/enrollment-previews/{$waitlist}/commit",
            [],
            ['Idempotency-Key' => 'waitlist-two'],
        )->assertOk();
        $this->getJson("/api/v1/studios/{$studio->slug}/event-series/{$series}/enrollments")
            ->assertOk()->assertJsonCount(2, 'data');

        $former = Person::factory()->create(['studio_id' => $studio->getKey()]);
        StudentProfile::query()->create(['studio_id' => $studio->getKey(), 'person_id' => $former->getKey(), 'status' => 'former']);
        $this->postJson($url, ['person_id' => $former->getKey(), 'status' => 'waitlisted'])
            ->assertUnprocessable()->assertJsonValidationErrors('person_id');
        $withdraw = $this->postJson(
            "/api/v1/studios/{$studio->slug}/event-series/{$series}/enrollments/{$enrollment['id']}/withdraw/previews",
            ['version' => $enrollment['version']],
        )->assertCreated()->json('data.id');
        $this->postJson(
            "/api/v1/studios/{$studio->slug}/schedule/enrollment-previews/{$withdraw}/commit",
            [],
            ['Idempotency-Key' => 'withdraw-one'],
        )->assertOk()->assertJsonPath('data.status', 'withdrawn');
    }

    public function test_clone_is_previewed_and_does_not_copy_roster_or_history(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $source = $this->createSeries($owner, $studio, $this->payload($service, $location, $room, $staff), 'clone-source');
        $preview = $this->actingAs($owner)->postJson(
            "/api/v1/studios/{$studio->slug}/event-series/{$source}/clone/previews",
            ['version' => EventSeries::query()->findOrFail($source)->version, 'title' => 'Piano Lab Copy', 'dtstart_local' => '2027-03-01T10:00:00', 'dtstart_resolution' => 'reject'],
        )->assertCreated()->assertJsonPath('data.command_type', 'clone_event_series')->json('data.id');
        $clone = $this->postJson(
            "/api/v1/studios/{$studio->slug}/event-series/previews/{$preview}/commit",
            [], ['Idempotency-Key' => 'clone-commit'],
        )->assertCreated()->json('data.id');

        $this->assertDatabaseHas('event_series', ['id' => $clone, 'cloned_from_series_id' => $source, 'title' => 'Piano Lab Copy']);
        $this->assertDatabaseMissing('event_enrollments', ['event_series_id' => $clone]);
        $this->assertDatabaseMissing('event_occurrence_overrides', ['event_series_id' => $clone]);
    }

    public function test_clone_translates_until_rdates_and_exdates_by_the_wall_clock_anchor_delta(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $payload = $this->payload($service, $location, $room, $staff);
        $payload['rrule'] = 'FREQ=WEEKLY;UNTIL=20270125T150000Z';
        $payload['rdates'] = [['local' => '2027-01-07T10:00:00', 'resolution' => 'reject']];
        $payload['exdates'] = ['2027-01-11T10:00:00'];
        StaffAvailabilityWindow::query()->create([
            'studio_id' => $studio->getKey(), 'staff_profile_id' => $staff->getKey(), 'weekday' => 4,
            'start_time' => '08:00:00', 'end_time' => '18:00:00', 'timezone' => 'America/Bogota', 'enforcement' => 'hard',
        ]);
        $sourceId = $this->createSeries($owner, $studio, $payload, 'clone-translate-source');
        $source = EventSeries::query()->findOrFail($sourceId);
        $preview = $this->actingAs($owner)->postJson(
            "/api/v1/studios/{$studio->slug}/event-series/{$sourceId}/clone/previews",
            [
                'version' => $source->version,
                'title' => 'Translated clone',
                'dtstart_local' => '2027-03-01T11:00:00',
                'dtstart_resolution' => 'reject',
            ],
        )->assertCreated()->json('data.id');
        $cloneId = $this->postJson(
            "/api/v1/studios/{$studio->slug}/event-series/previews/{$preview}/commit",
            [],
            ['Idempotency-Key' => 'clone-translate-commit'],
        )->assertCreated()->json('data.id');
        $clone = EventSeries::query()->findOrFail($cloneId);

        self::assertSame('FREQ=WEEKLY;UNTIL=20270322T160000Z', $clone->rrule);
        self::assertSame('2027-03-04T11:00:00', $clone->rdates[0]['local']);
        self::assertSame(['2027-03-08T11:00:00'], $clone->exdates);
        self::assertSame($sourceId, $clone->cloned_from_series_id);
    }

    public function test_bounded_horizon_extension_materializes_recurrence_beyond_the_initial_ninety_two_days(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $payload = $this->payload($service, $location, $room, $staff);
        $payload['rrule'] = 'FREQ=WEEKLY;COUNT=30';
        $seriesId = $this->createSeries($owner, $studio, $payload, 'long-horizon-source');
        $series = EventSeries::query()->findOrFail($seriesId);
        $initialCount = $series->occurrences()->count();
        self::assertGreaterThan(1, $initialCount);
        self::assertLessThan(30, $initialCount);

        (new ExtendEventSeriesHorizon($studio->getKey(), $seriesId, $series->version))
            ->handle(app(MaterializeEventSeries::class), app(RequestDatabaseContext::class));

        self::assertGreaterThan($initialCount, EventOccurrence::query()->where('event_series_id', $seriesId)->count());
        self::assertLessThanOrEqual(30, EventOccurrence::query()->where('event_series_id', $seriesId)->count());
        self::assertSame(
            1,
            EventOccurrence::query()->where('event_series_id', $seriesId)->where('recurrence_id_local', '2027-04-12T10:00:00')->count(),
        );
    }

    public function test_slot_search_is_bounded_and_returns_deterministic_available_candidates(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $response = $this->actingAs($owner)->postJson("/api/v1/studios/{$studio->slug}/schedule/slot-search", [
            'from' => '2027-01-04T13:00:00Z', 'to' => '2027-01-04T16:00:00Z',
            'duration_minutes' => 60, 'step_minutes' => 30, 'location_id' => $location->getKey(),
            'staff_profile_ids' => [$staff->getKey()], 'room_ids' => [$room->getKey()],
        ])->assertOk();

        self::assertSame('2027-01-04T13:00:00+00:00', $response->json('data.0.starts_at'));
        self::assertCount(5, $response->json('data'));
        $this->postJson("/api/v1/studios/{$studio->slug}/schedule/slot-search", [
            'from' => '2027-01-01T00:00:00Z', 'to' => '2027-03-01T00:00:00Z',
            'duration_minutes' => 60,
        ])->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_calendar_rejects_an_oversized_range_with_carbon_three_signed_differences(): void
    {
        [$owner, $studio] = $this->context();

        $this->actingAs($owner)->getJson(
            "/api/v1/studios/{$studio->slug}/calendar?from=2027-01-01T00:00:00Z&to=2027-05-01T00:00:00Z",
        )->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_stale_preview_input_is_422_and_expired_or_cross_actor_preview_is_safe(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $seriesId = $this->createSeries($owner, $studio, $this->payload($service, $location, $room, $staff), 'semantics-create');
        $occurrence = EventOccurrence::query()->where('event_series_id', $seriesId)->firstOrFail();

        $this->actingAs($owner)->postJson(
            "/api/v1/studios/{$studio->slug}/occurrences/{$occurrence->getKey()}/reschedule/previews",
            ['version' => $occurrence->version + 1, 'scope' => 'one', 'title' => 'Stale'],
        )->assertUnprocessable()->assertJsonValidationErrors('version');

        $preview = $this->postJson(
            "/api/v1/studios/{$studio->slug}/occurrences/{$occurrence->getKey()}/reschedule/previews",
            ['version' => $occurrence->version, 'scope' => 'one', 'title' => 'Fresh'],
        )->assertCreated()->json('data.id');
        Carbon::setTestNow(now()->addMinutes(11));

        try {
            $this->postJson(
                "/api/v1/studios/{$studio->slug}/schedule/previews/{$preview}/commit",
                [],
                ['Idempotency-Key' => 'expired-preview'],
            )->assertConflict()->assertJsonPath('code', 'preview_expired');
        } finally {
            Carbon::setTestNow();
        }

        $other = User::factory()->create(['email_verified_at' => now()]);
        StudioMembership::factory()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $other->getKey(), 'role' => MembershipRole::Administrator,
        ]);
        $this->actingAs($other)->postJson(
            "/api/v1/studios/{$studio->slug}/schedule/previews/{$preview}/commit",
            [],
            ['Idempotency-Key' => 'cross-actor'],
        )->assertNotFound();
    }

    public function test_preview_envelope_rejects_server_row_tampering_across_command_versions_scope_and_warning_fingerprint(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $url = "/api/v1/studios/{$studio->slug}/event-series/previews";
        $mutations = [
            fn (ScheduleChangePreview $preview) => $preview->forceFill(['command' => [...$preview->command, 'title' => 'Tampered']])->save(),
            fn (ScheduleChangePreview $preview) => $preview->forceFill(['aggregate_versions' => [...$preview->aggregate_versions, 'location' => 999]])->save(),
            fn (ScheduleChangePreview $preview) => $preview->forceFill(['scope' => 'one'])->save(),
            fn (ScheduleChangePreview $preview) => $preview->forceFill(['soft_warning_fingerprint' => str_repeat('0', 64)])->save(),
        ];

        foreach ($mutations as $index => $mutate) {
            $previewId = $this->actingAs($owner)->postJson($url, $this->payload($service, $location, $room, $staff))
                ->assertCreated()->json('data.id');
            $preview = ScheduleChangePreview::query()->findOrFail($previewId);
            $mutate($preview);
            $this->postJson(
                "/api/v1/studios/{$studio->slug}/event-series/previews/{$previewId}/commit",
                [],
                ['Idempotency-Key' => "envelope-tamper-{$index}"],
            )->assertConflict()->assertJsonPath('code', 'preview_envelope_mismatch');
            $this->assertDatabaseHas('schedule_change_previews', ['id' => $previewId, 'consumed_at' => null]);
        }
    }

    public function test_idempotent_replay_returns_the_original_result_and_reauthorizes_before_replay(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $preview = $this->actingAs($owner)->postJson(
            "/api/v1/studios/{$studio->slug}/event-series/previews",
            $this->payload($service, $location, $room, $staff),
        )->assertCreated()->json('data.id');
        $url = "/api/v1/studios/{$studio->slug}/event-series/previews/{$preview}/commit";
        $first = $this->postJson($url, [], ['Idempotency-Key' => 'exact-result'])
            ->assertCreated()->json('data');
        EventSeries::query()->whereKey($first['id'])->update(['title' => 'Later title', 'version' => 2]);
        $replay = $this->postJson($url, [], ['Idempotency-Key' => 'exact-result'])
            ->assertCreated()->json('data');
        self::assertSame($first['title'], $replay['title']);
        self::assertSame($first['version'], $replay['version']);
        self::assertSame(1, DB::table('scheduling_command_claims')->count());

        StudioMembership::query()->where('studio_id', $studio->getKey())->where('user_id', $owner->getKey())->delete();
        $this->postJson($url, [], ['Idempotency-Key' => 'exact-result'])->assertForbidden();
    }

    public function test_commit_rejects_when_the_fresh_soft_warning_set_no_longer_matches_the_acknowledged_preview(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        StaffAvailabilityWindow::query()->where('staff_profile_id', $staff->getKey())->delete();
        $window = StaffAvailabilityWindow::query()->create([
            'studio_id' => $studio->getKey(), 'staff_profile_id' => $staff->getKey(), 'weekday' => 1,
            'start_time' => '11:00:00', 'end_time' => '12:00:00', 'timezone' => 'America/Bogota', 'enforcement' => 'soft',
        ]);
        $payload = [...$this->payload($service, $location, $room, $staff), 'acknowledge_soft_warnings' => true];
        $preview = $this->actingAs($owner)->postJson(
            "/api/v1/studios/{$studio->slug}/event-series/previews", $payload,
        )->assertCreated()->assertJsonPath('data.soft_warnings_acknowledged', true)->json('data.id');
        $window->update(['active' => false]);

        $this->postJson(
            "/api/v1/studios/{$studio->slug}/event-series/previews/{$preview}/commit",
            [],
            ['Idempotency-Key' => 'warning-drift'],
        )->assertConflict()->assertJsonPath('code', 'soft_warnings_changed');
    }

    public function test_future_recurrence_edit_partitions_count_and_translates_explicit_dates_without_resetting_history(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $payload = $this->payload($service, $location, $room, $staff);
        $payload['rrule'] = 'FREQ=WEEKLY;COUNT=6';
        $payload['rdates'] = [['local' => '2027-02-17T10:00:00', 'resolution' => 'reject']];
        $payload['exdates'] = ['2027-02-08T10:00:00'];
        StaffAvailabilityWindow::query()->create([
            'studio_id' => $studio->getKey(), 'staff_profile_id' => $staff->getKey(), 'weekday' => 3,
            'start_time' => '08:00:00', 'end_time' => '18:00:00', 'timezone' => 'America/Bogota', 'enforcement' => 'hard',
        ]);
        $seriesId = $this->createSeries($owner, $studio, $payload, 'future-recurrence-source');
        $source = EventSeries::query()->findOrFail($seriesId);
        $occurrences = EventOccurrence::query()->where('event_series_id', $seriesId)->orderBy('recurrence_id_local')->get();
        $anchor = $occurrences->firstWhere('recurrence_id_local', '2027-01-18T10:00:00');
        self::assertNotNull($anchor);
        $preview = $this->actingAs($owner)->postJson(
            "/api/v1/studios/{$studio->slug}/occurrences/{$anchor->getKey()}/reschedule/previews",
            [
                'version' => $anchor->version,
                'scope' => 'future',
                'dtstart_local' => '2027-01-18T11:00:00',
                'dtstart_resolution' => 'reject',
            ],
        )->assertCreated()->json('data.id');
        $newId = $this->postJson(
            "/api/v1/studios/{$studio->slug}/schedule/previews/{$preview}/commit",
            [],
            ['Idempotency-Key' => 'future-recurrence-edit'],
        )->assertOk()->json('data.id');
        $new = EventSeries::query()->findOrFail($newId);

        self::assertSame('FREQ=WEEKLY;COUNT=4', $new->rrule);
        self::assertSame('2027-02-17T11:00:00', $new->rdates[0]['local']);
        self::assertSame(['2027-02-08T11:00:00'], $new->exdates);
        self::assertSame([], $source->refresh()->rdates);
        self::assertSame([], $source->exdates);
        self::assertSame('scheduled', $occurrences[0]->refresh()->status->value);
        self::assertSame('scheduled', $occurrences[1]->refresh()->status->value);
        self::assertSame('canceled', $anchor->refresh()->status->value);
        self::assertSame(4, EventOccurrence::query()->where('event_series_id', $newId)->count());
    }

    public function test_series_recurrence_edit_uses_a_full_lineage_split_and_calendar_cursor_has_no_silent_truncation(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $seriesId = $this->createSeries($owner, $studio, $this->payload($service, $location, $room, $staff), 'series-shape-source');
        $anchor = EventOccurrence::query()->where('event_series_id', $seriesId)->orderBy('starts_at')->firstOrFail();
        $preview = $this->actingAs($owner)->postJson(
            "/api/v1/studios/{$studio->slug}/occurrences/{$anchor->getKey()}/reschedule/previews",
            [
                'version' => $anchor->version,
                'scope' => 'series',
                'dtstart_local' => '2027-04-05T09:00:00',
                'dtstart_resolution' => 'reject',
                'rrule' => 'FREQ=WEEKLY;COUNT=2',
            ],
        )->assertCreated()->json('data.id');
        $newId = $this->postJson(
            "/api/v1/studios/{$studio->slug}/schedule/previews/{$preview}/commit",
            [],
            ['Idempotency-Key' => 'series-shape-edit'],
        )->assertOk()->json('data.id');

        self::assertNotSame($seriesId, $newId);
        $this->assertDatabaseHas('event_series_splits', ['old_series_id' => $seriesId, 'new_series_id' => $newId]);
        self::assertSame(2, EventOccurrence::query()->where('event_series_id', $newId)->count());

        $firstPage = $this->getJson(
            "/api/v1/studios/{$studio->slug}/calendar?from=2027-01-01T00:00:00Z&to=2027-05-01T00:00:00Z&page_size=2",
        )->assertUnprocessable();
        unset($firstPage);
        $firstPage = $this->getJson(
            "/api/v1/studios/{$studio->slug}/calendar?from=2027-01-01T00:00:00Z&to=2027-04-01T00:00:00Z&page_size=2",
        )->assertOk()->assertJsonCount(2, 'data');
        $next = $firstPage->json('links.next');
        self::assertIsString($next);
        $cursor = parse_url($next, PHP_URL_QUERY);
        self::assertIsString($cursor);
        parse_str($cursor, $cursorParameters);
        $this->getJson(
            "/api/v1/studios/{$studio->slug}/calendar?from=2027-01-01T00:00:00Z&to=2027-04-01T00:00:00Z&page_size=2&cursor="
            .urlencode((string) $cursorParameters['cursor']),
        )->assertOk();
    }

    public function test_slot_ranking_prefers_warning_free_capacity_safe_slots_and_rejects_cross_tenant_resources(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        unset($service);
        StaffAvailabilityWindow::query()->where('staff_profile_id', $staff->getKey())->delete();
        StaffAvailabilityWindow::query()->create([
            'studio_id' => $studio->getKey(), 'staff_profile_id' => $staff->getKey(), 'weekday' => 1,
            'start_time' => '09:00:00', 'end_time' => '11:00:00', 'timezone' => 'America/Bogota', 'enforcement' => 'soft',
        ]);
        $response = $this->actingAs($owner)->postJson("/api/v1/studios/{$studio->slug}/schedule/slot-search", [
            'from' => '2027-01-04T13:00:00Z', 'to' => '2027-01-04T18:00:00Z',
            'duration_minutes' => 60, 'step_minutes' => 60, 'capacity' => 4,
            'location_id' => $location->getKey(), 'staff_profile_ids' => [$staff->getKey()], 'room_ids' => [$room->getKey()],
        ])->assertOk();
        self::assertSame(0, $response->json('data.0.score'));
        self::assertSame('2027-01-04T14:00:00+00:00', $response->json('data.0.starts_at'));

        $this->postJson("/api/v1/studios/{$studio->slug}/schedule/slot-search", [
            'from' => '2027-01-04T13:00:00Z', 'to' => '2027-01-04T18:00:00Z',
            'duration_minutes' => 60, 'capacity' => 5, 'location_id' => $location->getKey(), 'room_ids' => [$room->getKey()],
        ])->assertOk()->assertJsonCount(0, 'data');

        $otherStudio = Studio::factory()->create();
        $otherLocation = Location::query()->create([
            'studio_id' => $otherStudio->getKey(), 'name' => 'Other', 'kind' => 'physical', 'timezone' => 'UTC',
        ]);
        $this->postJson("/api/v1/studios/{$studio->slug}/schedule/slot-search", [
            'from' => '2027-01-04T13:00:00Z', 'to' => '2027-01-04T18:00:00Z',
            'duration_minutes' => 60, 'location_id' => $otherLocation->getKey(),
        ])->assertUnprocessable()->assertJsonValidationErrors('location_id');
    }

    public function test_slot_search_is_manager_only_and_rejects_expensive_candidate_grids_before_searching(): void
    {
        [$owner, $studio] = $this->context();
        $billing = User::factory()->create(['email_verified_at' => now()]);
        StudioMembership::factory()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $billing->getKey(), 'role' => MembershipRole::Billing,
        ]);
        $payload = [
            'from' => '2027-01-01T00:00:00Z', 'to' => '2027-01-05T00:00:00Z',
            'duration_minutes' => 5, 'step_minutes' => 5,
        ];

        $this->actingAs($billing)->postJson("/api/v1/studios/{$studio->slug}/schedule/slot-search", $payload)
            ->assertForbidden();
        $this->actingAs($owner)->postJson("/api/v1/studios/{$studio->slug}/schedule/slot-search", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_one_scope_resource_move_writes_append_only_before_after_history_and_projection_only_intents(): void
    {
        [$owner, $studio, $service, $location, $room, $staff] = $this->context();
        $seriesId = $this->createSeries($owner, $studio, $this->payload($service, $location, $room, $staff), 'audit-source');
        $occurrence = EventOccurrence::query()->where('event_series_id', $seriesId)->firstOrFail();
        $preview = $this->actingAs($owner)->postJson(
            "/api/v1/studios/{$studio->slug}/occurrences/{$occurrence->getKey()}/reschedule/previews",
            ['version' => $occurrence->version, 'scope' => 'one', 'starts_at_local' => '2027-01-04T11:00:00', 'start_resolution' => 'reject'],
        )->assertCreated()->json('data.id');
        $this->postJson(
            "/api/v1/studios/{$studio->slug}/schedule/previews/{$preview}/commit",
            [],
            ['Idempotency-Key' => 'audit-move'],
        )->assertOk();
        $event = ScheduleChangeEvent::query()->where('idempotency_key', 'audit-move')->firstOrFail();

        self::assertSame($occurrence->public_uid, $event->payload['before']['public_uid']);
        self::assertSame($occurrence->public_uid, $event->payload['after']['public_uid']);
        self::assertNotSame($event->payload['before']['starts_at'], $event->payload['after']['starts_at']);
        self::assertSame(['billing_recalculation', 'payroll_recalculation'], array_column($event->payload['projection_intents'], 'type'));
        self::assertSame(['project_only', 'project_only'], array_column($event->payload['projection_intents'], 'mode'));
    }

    private function context(): array
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $studio = Studio::factory()->create(['timezone' => 'America/Bogota']);
        StudioMembership::factory()->owner()->create(['studio_id' => $studio->getKey(), 'user_id' => $owner->getKey()]);
        $category = ServiceCategory::query()->create(['studio_id' => $studio->getKey(), 'name' => 'Lessons']);
        $service = Service::query()->create([
            'studio_id' => $studio->getKey(), 'service_category_id' => $category->getKey(), 'name' => 'Piano',
            'default_duration_minutes' => 60, 'default_capacity' => 4, 'default_price_minor' => 5000,
            'currency' => 'USD', 'makeup_policy' => 'none',
        ]);
        $location = Location::query()->create(['studio_id' => $studio->getKey(), 'name' => 'Main', 'kind' => 'physical', 'timezone' => 'America/Bogota']);
        $room = Room::query()->create(['studio_id' => $studio->getKey(), 'location_id' => $location->getKey(), 'name' => 'A', 'capacity' => 4]);
        $person = Person::factory()->create(['studio_id' => $studio->getKey()]);
        $staff = StaffProfile::query()->create([
            'studio_id' => $studio->getKey(), 'person_id' => $person->getKey(), 'roles' => [StaffRole::Teacher->value], 'status' => StaffStatus::Active,
        ]);
        StaffAvailabilityWindow::query()->create([
            'studio_id' => $studio->getKey(), 'staff_profile_id' => $staff->getKey(), 'weekday' => 1,
            'start_time' => '08:00:00', 'end_time' => '18:00:00', 'timezone' => 'America/Bogota', 'enforcement' => 'hard',
        ]);

        return [$owner, $studio, $service, $location, $room, $staff];
    }

    private function payload(Service $service, Location $location, Room $room, StaffProfile $staff): array
    {
        return [
            'service_id' => $service->getKey(), 'location_id' => $location->getKey(),
            'kind' => 'group_class', 'visibility' => 'studio', 'title' => '  Piano   Lab ',
            'internal_description' => 'Staff only', 'timezone' => 'America/Bogota',
            'dtstart_local' => '2027-01-04T10:00:00', 'dtstart_resolution' => 'reject',
            'duration_minutes' => 60, 'rrule' => 'FREQ=WEEKLY;COUNT=3', 'capacity' => 4,
            'teachers' => [['staff_profile_id' => $staff->getKey(), 'role' => 'lead']],
            'room_ids' => [$room->getKey()],
        ];
    }

    private function createSeries(User $owner, Studio $studio, array $payload, string $key): string
    {
        $preview = $this->actingAs($owner)->postJson("/api/v1/studios/{$studio->slug}/event-series/previews", $payload)
            ->assertCreated()->json('data.id');

        return $this->postJson(
            "/api/v1/studios/{$studio->slug}/event-series/previews/{$preview}/commit", [], ['Idempotency-Key' => $key],
        )->assertCreated()->json('data.id');
    }
}
