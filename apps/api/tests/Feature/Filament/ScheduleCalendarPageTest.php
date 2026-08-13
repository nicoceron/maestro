<?php

namespace Tests\Feature\Filament;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\StaffRole;
use App\Enums\StaffStatus;
use App\Filament\Pages\ScheduleCalendar;
use App\Models\EventEnrollment;
use App\Models\EventOccurrence;
use App\Models\EventOccurrenceTeacher;
use App\Models\EventSeries;
use App\Models\EventSeriesTeacher;
use App\Models\Person;
use App\Models\ScheduleChangePreview;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\StaffAccountLink;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class ScheduleCalendarPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_calendar_renders_and_returns_safe_filterable_events(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $teacher = $this->teacher($studio);
        $visible = $this->event($studio, $teacher, 'Piano studio', '2027-01-04 15:00:00');
        $otherStudio = Studio::factory()->create();
        $this->event($otherStudio, $this->teacher($otherStudio), 'Other tenant', '2027-01-04 15:00:00');
        $this->filamentAs($owner, $studio);

        Livewire::test(ScheduleCalendar::class)
            ->assertSuccessful()
            ->assertSee('Studio calendar')
            ->assertSee('All visible teachers')
            ->call('calendarEvents', '2027-01-01T00:00:00Z', '2027-02-01T00:00:00Z', [
                'q' => 'Piano', 'kind' => 'private_lesson', 'holds' => 'exclude',
            ])
            ->assertReturned(function (array $events) use ($visible): bool {
                $this->assertCount(1, $events);
                $this->assertSame($visible->getKey(), $events[0]['id']);
                $this->assertSame('Piano studio', $events[0]['title']);
                $this->assertSame(['Teacher Example'], $events[0]['extendedProps']['teachers']);
                $this->assertTrue($events[0]['extendedProps']['canManage']);

                return true;
            });
    }

    public function test_teacher_calendar_is_assignment_scoped_and_billing_is_redacted(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        [$teacherUser, , $membership] = $this->member(MembershipRole::Teacher, $studio);
        $assignedTeacher = $this->teacher($studio, $membership);
        $otherTeacher = $this->teacher($studio);
        $assigned = $this->event($studio, $assignedTeacher, 'Assigned lesson', '2027-01-04 15:00:00');
        $this->event($studio, $otherTeacher, 'Private assignment', '2027-01-04 17:00:00');
        $this->filamentAs($teacherUser, $studio);

        Livewire::test(ScheduleCalendar::class)
            ->call('calendarEvents', '2027-01-01T00:00:00Z', '2027-02-01T00:00:00Z')
            ->assertReturned(function (array $events) use ($assigned): bool {
                $this->assertCount(1, $events);
                $this->assertSame($assigned->getKey(), $events[0]['id']);
                $this->assertFalse($events[0]['extendedProps']['canManage']);

                return true;
            });

        [$billing, , $billingMembership] = $this->member(MembershipRole::Billing, $studio);
        $this->filamentAs($billing, $studio, $billingMembership);
        Livewire::test(ScheduleCalendar::class)
            ->call('calendarEvents', '2027-01-01T00:00:00Z', '2027-02-01T00:00:00Z')
            ->assertReturned(function (array $events): bool {
                $this->assertCount(2, $events);
                $this->assertSame([], $events[0]['extendedProps']['teachers']);
                $this->assertNull($events[0]['extendedProps']['participants']);
                $this->assertSame(1, $events[0]['extendedProps']['capacity']);

                return true;
            });
    }

    public function test_calendar_rejects_cross_tenant_filter_ids_and_large_ranges(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $otherStudio = Studio::factory()->create();
        $otherTeacher = $this->teacher($otherStudio);
        $this->filamentAs($owner, $studio);

        $page = Livewire::test(ScheduleCalendar::class)->instance();

        try {
            $page->calendarEvents('2027-01-01T00:00:00Z', '2027-02-01T00:00:00Z', [
                'teacher_id' => $otherTeacher->getKey(),
            ]);
            $this->fail('A cross-tenant teacher filter was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('teacher_id', $exception->errors());
        }

        try {
            $page->calendarEvents('2027-01-01T00:00:00Z', '2027-06-01T00:00:00Z');
            $this->fail('An oversized calendar range was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('to', $exception->errors());
        }
    }

    public function test_manager_creates_an_event_through_preview_and_idempotent_commit(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $this->filamentAs($owner, $studio);
        $start = now($studio->timezone)->addWeek()->startOfHour();
        $component = Livewire::test(ScheduleCalendar::class)->assertActionVisible('createEvent');

        $page = $component->instance();
        $preview = $page->previewEventCreation([
            'kind' => 'general',
            'title' => 'Studio planning session',
            'visibility' => 'studio',
            'timezone' => $studio->timezone,
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'dtstart_resolution' => 'reject',
            'duration_minutes' => 60,
            'capacity' => 10,
            'repeat' => 'none',
            'temporary_hold' => false,
            'teacher_ids' => [],
            'room_ids' => [],
            'equipment' => [],
        ]);
        $page->commitEventCreation($preview->getKey(), (string) Str::uuid());

        $series = EventSeries::query()->where('studio_id', $studio->getKey())
            ->where('title', 'Studio planning session')->sole();

        $this->assertSame('general', $series->kind->value);
        $this->assertSame($start->format('Y-m-d\TH:i:s'), $series->dtstart_local);
        $this->assertCount(1, $series->occurrences);
    }

    public function test_embedded_calendar_fails_closed_instead_of_silently_omitting_dense_results(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $series = EventSeries::query()->create([
            'studio_id' => $studio->getKey(), 'kind' => 'general', 'status' => 'active',
            'visibility' => 'studio', 'title' => 'Dense fixture', 'timezone' => 'UTC',
            'dtstart_local' => '2027-01-01T00:00:00', 'dtstart_resolution' => 'reject',
            'duration_minutes' => 5, 'capacity' => 1,
        ]);
        $now = now();
        $rows = [];
        for ($index = 0; $index < 1001; $index++) {
            $start = now('UTC')->setDate(2027, 1, 1)->startOfDay()->addMinutes($index * 5);
            $rows[] = [
                'id' => (string) Str::ulid(), 'studio_id' => $studio->getKey(), 'event_series_id' => $series->getKey(),
                'public_uid' => (string) Str::uuid(), 'recurrence_id_local' => $start->format('Y-m-d\TH:i:s'),
                'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(5), 'utc_offset_minutes' => 0,
                'timezone' => 'UTC', 'source' => 'generated', 'status' => 'scheduled', 'title' => 'Dense fixture',
                'kind' => 'general', 'capacity' => 1, 'price_minor' => 0, 'currency' => 'USD', 'version' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            EventOccurrence::query()->insert($chunk);
        }
        $this->filamentAs($owner, $studio);
        $page = Livewire::test(ScheduleCalendar::class)->instance();

        try {
            $page->calendarEvents('2027-01-01T00:00:00Z', '2027-01-05T00:00:00Z');
            $this->fail('The embedded calendar silently accepted an unsafe result set.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('to', $exception->errors());
            $this->assertStringContainsString('no events were omitted', $exception->errors()['to'][0]);
        }
    }

    public function test_teacher_and_billing_users_cannot_open_the_create_event_action(): void
    {
        [$teacher, $studio, $teacherMembership] = $this->member(MembershipRole::Teacher);
        $this->filamentAs($teacher, $studio, $teacherMembership);
        Livewire::test(ScheduleCalendar::class)->assertActionHidden('createEvent');

        [$billing, , $billingMembership] = $this->member(MembershipRole::Billing, $studio);
        $this->filamentAs($billing, $studio, $billingMembership);
        Livewire::test(ScheduleCalendar::class)->assertActionHidden('createEvent');
    }

    public function test_manager_runs_schedule_changes_clone_hold_and_roster_through_real_filament_actions(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $teacher = $this->teacher($studio);
        $occurrence = $this->event($studio, $teacher, 'Workflow lesson', '2027-01-04 15:00:00');
        $series = $occurrence->series;
        $this->filamentAs($owner, $studio);

        $component = Livewire::test(ScheduleCalendar::class)
            ->assertActionVisible('rescheduleEvent')
            ->assertActionVisible('changeEventStatus')
            ->assertActionVisible('cloneEvent')
            ->assertActionVisible('manageHold')
            ->assertActionVisible('manageRoster')
            ->mountAction('rescheduleEvent')
            ->setActionData([
                'occurrence_id' => $occurrence->getKey(),
                'scope' => 'one',
                'starts_at' => '2027-01-05 10:00:00',
                'timezone' => 'America/Bogota',
                'start_resolution' => 'reject',
                'duration_minutes' => 60,
                'reason' => 'Family requested a new day.',
            ])->callMountedAction();
        $preview = ScheduleChangePreview::query()->where('command_type', 'reschedule')->sole();
        $component->mountAction('reviewScheduleChange', [
            'previewId' => $preview->getKey(),
            'idempotencyKey' => (string) Str::uuid(),
        ])->setActionData(['acknowledge_soft_warnings' => false])->callMountedAction()
            ->assertNotified('Schedule updated');

        $occurrence->refresh();
        $this->assertSame('2027-01-05 15:00:00', $occurrence->starts_at->format('Y-m-d H:i:s'));

        $component->mountAction('changeEventStatus')->setActionData([
            'occurrence_id' => $occurrence->getKey(),
            'operation' => 'cancel',
            'scope' => 'one',
            'reason' => 'Studio closure.',
            'makeup_required' => true,
            'makeup_reference' => 'Snow-day credit',
        ])->callMountedAction();
        $preview = ScheduleChangePreview::query()->where('command_type', 'cancel')->sole();
        $component->mountAction('reviewScheduleChange', [
            'previewId' => $preview->getKey(),
            'idempotencyKey' => (string) Str::uuid(),
        ])->setActionData(['acknowledge_soft_warnings' => false])->callMountedAction()
            ->assertNotified('Schedule updated');
        $this->assertSame('canceled', $occurrence->refresh()->status->value);

        $component->mountAction('changeEventStatus')->setActionData([
            'occurrence_id' => $occurrence->getKey(),
            'operation' => 'restore',
            'scope' => 'one',
            'reason' => 'Studio reopened.',
        ])->callMountedAction();
        $preview = ScheduleChangePreview::query()->where('command_type', 'restore')->sole();
        $component->mountAction('reviewScheduleChange', [
            'previewId' => $preview->getKey(),
            'idempotencyKey' => (string) Str::uuid(),
        ])->setActionData(['acknowledge_soft_warnings' => false])->callMountedAction()
            ->assertNotified('Schedule updated');
        $this->assertSame('scheduled', $occurrence->refresh()->status->value);

        $component->mountAction('cloneEvent')->setActionData([
            'series_id' => $series->getKey(),
            'title' => 'Workflow lesson copy',
            'starts_at' => '2027-02-02 10:00:00',
            'dtstart_resolution' => 'reject',
        ])->callMountedAction();
        $preview = ScheduleChangePreview::query()->where('command_type', 'clone_event_series')->sole();
        $component->mountAction('reviewEventCreation', [
            'previewId' => $preview->getKey(),
            'idempotencyKey' => (string) Str::uuid(),
        ])->setActionData(['acknowledge_soft_warnings' => false])->callMountedAction()
            ->assertNotified('Event created');
        $this->assertDatabaseHas('event_series', [
            'studio_id' => $studio->getKey(),
            'title' => 'Workflow lesson copy',
            'cloned_from_series_id' => $series->getKey(),
        ]);

        $holdOccurrence = $this->event($studio, $teacher, 'Temporary reservation', '2027-03-04 15:00:00');
        $holdOccurrence->series()->update(['status' => 'draft', 'hold_expires_at' => now()->addDay()]);
        $holdOccurrence->update(['status' => 'tentative', 'hold_expires_at' => now()->addDay()]);
        $holdSeries = $holdOccurrence->series()->firstOrFail();
        $component->callAction('manageHold', data: [
            'series_id' => $holdSeries->getKey(),
            'operation' => 'convert',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertNotified('Temporary hold updated');
        $this->assertSame('active', $holdSeries->refresh()->status->value);

        $student = Person::factory()->for($studio)->create(['first_name' => 'Roster', 'last_name' => 'Student']);
        StudentProfile::query()->create([
            'studio_id' => $studio->getKey(),
            'person_id' => $student->getKey(),
            'status' => 'active',
        ]);
        $component->callAction('manageRoster', data: [
            'series_id' => $series->getKey(),
            'operation' => 'enroll',
            'person_id' => $student->getKey(),
            'status' => 'confirmed',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertNotified('Roster updated');
        $enrollment = EventEnrollment::query()->where('event_series_id', $series->getKey())
            ->where('person_id', $student->getKey())->sole();
        $this->assertSame('confirmed', $enrollment->status->value);

        $component->callAction('manageRoster', data: [
            'series_id' => $series->getKey(),
            'operation' => 'withdraw',
            'enrollment_id' => $enrollment->getKey(),
            'idempotency_key' => (string) Str::uuid(),
        ])->assertNotified('Roster updated');
        $this->assertSame('withdrawn', $enrollment->refresh()->status->value);
    }

    public function test_teacher_and_billing_cannot_invoke_manager_schedule_actions(): void
    {
        foreach ([MembershipRole::Teacher, MembershipRole::Billing] as $role) {
            [$user, $studio, $membership] = $this->member($role);
            $this->filamentAs($user, $studio, $membership);
            Livewire::test(ScheduleCalendar::class)
                ->assertActionHidden('findAvailableTime')
                ->assertActionHidden('rescheduleEvent')
                ->assertActionHidden('changeEventStatus')
                ->assertActionHidden('cloneEvent')
                ->assertActionHidden('manageHold')
                ->assertActionHidden('manageRoster');
        }
    }

    private function event(Studio $studio, StaffProfile $teacher, string $title, string $startsAt): EventOccurrence
    {
        $service = $this->service($studio);
        $endsAt = CarbonImmutable::parse($startsAt, 'UTC')->addHour();
        $series = EventSeries::query()->create([
            'studio_id' => $studio->getKey(), 'service_id' => $service->getKey(),
            'kind' => 'private_lesson', 'status' => 'active',
            'visibility' => 'studio', 'title' => $title, 'timezone' => 'America/Bogota',
            'dtstart_local' => '2027-01-04T10:00:00', 'dtstart_resolution' => 'reject',
            'duration_minutes' => 60, 'capacity' => 1,
        ]);
        EventSeriesTeacher::query()->create([
            'studio_id' => $studio->getKey(), 'event_series_id' => $series->getKey(),
            'staff_profile_id' => $teacher->getKey(), 'role' => 'lead',
        ]);
        $occurrence = EventOccurrence::query()->create([
            'studio_id' => $studio->getKey(), 'event_series_id' => $series->getKey(),
            'public_uid' => Str::uuid(), 'recurrence_id_local' => '2027-01-04T10:00:00',
            'starts_at' => $startsAt, 'ends_at' => $endsAt,
            'utc_offset_minutes' => -300, 'timezone' => 'America/Bogota', 'source' => 'generated',
            'status' => 'scheduled', 'title' => $title, 'kind' => 'private_lesson',
            'capacity' => 1, 'price_minor' => 5000, 'currency' => 'USD',
        ]);
        EventOccurrenceTeacher::query()->create([
            'studio_id' => $studio->getKey(), 'event_occurrence_id' => $occurrence->getKey(),
            'staff_profile_id' => $teacher->getKey(), 'role' => 'lead', 'status' => 'assigned',
            'busy_starts_at' => $occurrence->starts_at, 'busy_ends_at' => $occurrence->ends_at,
        ]);

        return $occurrence;
    }

    private function service(Studio $studio): Service
    {
        $category = ServiceCategory::query()->firstOrCreate(
            ['studio_id' => $studio->getKey(), 'normalized_name' => 'lessons'],
            ['name' => 'Lessons'],
        );

        return Service::query()->firstOrCreate(
            ['studio_id' => $studio->getKey(), 'normalized_name' => 'piano'],
            [
                'service_category_id' => $category->getKey(),
                'name' => 'Piano',
                'default_duration_minutes' => 60,
                'default_capacity' => 1,
                'default_price_minor' => 5000,
                'currency' => 'USD',
                'makeup_policy' => 'none',
            ],
        );
    }

    private function teacher(Studio $studio, ?StudioMembership $membership = null): StaffProfile
    {
        $person = Person::factory()->for($studio)->create(['first_name' => 'Teacher', 'last_name' => 'Example']);
        $profile = StaffProfile::query()->create([
            'studio_id' => $studio->getKey(), 'person_id' => $person->getKey(),
            'roles' => [StaffRole::Teacher], 'status' => StaffStatus::Active,
        ]);

        if ($membership !== null) {
            StaffAccountLink::query()->create([
                'studio_id' => $studio->getKey(), 'staff_profile_id' => $profile->getKey(),
                'studio_membership_id' => $membership->getKey(), 'active' => true,
            ]);
        }

        return $profile;
    }

    private function filamentAs(User $user, Studio $studio, ?StudioMembership $membership = null): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($studio);
        $membership ??= StudioMembership::query()
            ->where('studio_id', $studio->getKey())->where('user_id', $user->getKey())->sole();
        app(TenantContext::class)->activate($studio, $membership);
    }

    /** @return array{User, Studio, StudioMembership} */
    private function member(MembershipRole $role, ?Studio $studio = null): array
    {
        $user = User::factory()->create();
        $studio ??= Studio::factory()->create(['timezone' => 'America/Bogota']);
        $membership = StudioMembership::query()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $user->getKey(),
            'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now(), 'preferences' => [],
        ]);

        return [$user, $studio, $membership];
    }
}
