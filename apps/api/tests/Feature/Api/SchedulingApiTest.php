<?php

namespace Tests\Feature\Api;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\StaffRole;
use App\Enums\StaffStatus;
use App\Models\Person;
use App\Models\RoomEquipment;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\StaffProfile;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SchedulingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_can_build_catalog_resources_with_integer_money_and_retire_them_optimistically(): void
    {
        [, $studio] = $this->member(MembershipRole::Office);
        $category = $this->postJson($this->url($studio, 'service-categories'), [
            'name' => '  Private Lessons ',
            'color' => '#4f46e5',
        ])->assertCreated()->assertJsonPath('data.name', 'Private Lessons');
        $service = $this->postJson($this->url($studio, 'services'), [
            'service_category_id' => $category->json('data.id'),
            'name' => 'Piano 45',
            'default_duration_minutes' => 45,
            'default_capacity' => 1,
            'default_price_minor' => 12500,
            'currency' => 'usd',
            'booking_lead_minutes' => 120,
            'cancellation_notice_minutes' => 1440,
            'makeup_policy' => 'reschedule',
        ])->assertCreated()
            ->assertJsonPath('data.default_price_minor', 12500)
            ->assertJsonPath('data.currency', 'USD');

        $this->patchJson($this->url($studio, 'services').'/'.$service->json('data.id'), [
            'version' => 1,
            'active' => false,
        ])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.active', false);
        $this->patchJson($this->url($studio, 'services').'/'.$service->json('data.id'), [
            'version' => 1,
            'name' => 'Stale overwrite',
        ])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->deleteJson($this->url($studio, 'services').'/'.$service->json('data.id'))
            ->assertMethodNotAllowed();
    }

    public function test_locations_rooms_and_equipment_are_same_tenant_and_stock_safe(): void
    {
        [, $studio] = $this->member(MembershipRole::Administrator);
        $location = $this->postJson($this->url($studio, 'locations'), [
            'name' => 'Downtown',
            'kind' => 'physical',
            'timezone' => 'America/New_York',
            'private_instructions' => 'Alarm code 4312',
        ])->assertCreated();
        $roomA = $this->postJson($this->url($studio, 'rooms'), [
            'location_id' => $location->json('data.id'), 'name' => 'Room A', 'capacity' => 4,
        ])->assertCreated();
        $roomB = $this->postJson($this->url($studio, 'rooms'), [
            'location_id' => $location->json('data.id'), 'name' => 'Room B', 'capacity' => 4,
        ])->assertCreated();
        $equipment = $this->postJson($this->url($studio, 'equipment'), [
            'location_id' => $location->json('data.id'), 'name' => 'Digital piano', 'quantity' => 2,
        ])->assertCreated();

        $this->postJson($this->url($studio, 'room-equipment'), [
            'location_id' => $location->json('data.id'),
            'room_id' => $roomA->json('data.id'),
            'equipment_id' => $equipment->json('data.id'),
            'quantity' => 1,
        ])->assertCreated();
        $this->postJson($this->url($studio, 'room-equipment'), [
            'location_id' => $location->json('data.id'),
            'room_id' => $roomB->json('data.id'),
            'equipment_id' => $equipment->json('data.id'),
            'quantity' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        [, $otherStudio] = $this->member(MembershipRole::Owner);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson($this->url($otherStudio, 'locations'))->assertForbidden();
    }

    public function test_database_rejects_cross_location_room_equipment_even_when_api_is_bypassed(): void
    {
        [, $studio] = $this->member(MembershipRole::Owner);
        $first = $this->postJson($this->url($studio, 'locations'), [
            'name' => 'First', 'kind' => 'physical', 'timezone' => 'UTC',
        ])->json('data.id');
        $second = $this->postJson($this->url($studio, 'locations'), [
            'name' => 'Second', 'kind' => 'physical', 'timezone' => 'UTC',
        ])->json('data.id');
        $room = $this->postJson($this->url($studio, 'rooms'), [
            'location_id' => $first, 'name' => 'Room', 'capacity' => 2,
        ])->json('data.id');
        $equipment = $this->postJson($this->url($studio, 'equipment'), [
            'location_id' => $second, 'name' => 'Keyboard', 'quantity' => 1,
        ])->json('data.id');

        $this->expectException(QueryException::class);
        RoomEquipment::query()->create([
            'studio_id' => $studio->getKey(), 'location_id' => $first,
            'room_id' => $room, 'equipment_id' => $equipment, 'quantity' => 1,
        ]);
    }

    public function test_teacher_catalog_projection_redacts_private_location_and_equipment_fields(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $location = $this->postJson($this->url($studio, 'locations'), [
            'name' => 'Online', 'kind' => 'online', 'timezone' => 'UTC',
            'online_url' => 'https://example.test/private-room',
            'private_instructions' => 'Host PIN 1234',
        ])->json('data.id');
        $equipment = $this->postJson($this->url($studio, 'equipment'), [
            'location_id' => $location, 'name' => 'License', 'quantity' => 10,
            'notes' => 'Admin-only license key',
        ])->json('data.id');
        [$teacher] = $this->member(MembershipRole::Teacher, $studio);
        Sanctum::actingAs($teacher);

        $this->getJson($this->url($studio, 'locations').'/'.$location)
            ->assertOk()->assertJsonPath('data.online_url', null)
            ->assertJsonPath('data.private_instructions', null);
        $this->getJson($this->url($studio, 'equipment').'/'.$equipment)
            ->assertOk()->assertJsonPath('data.notes', null)
            ->assertJsonPath('data.permissions.edit', false);
        Sanctum::actingAs($owner);
    }

    public function test_effective_price_policy_and_teacher_location_overrides_merge_per_field_in_precedence_order(): void
    {
        [, $studio] = $this->member(MembershipRole::Owner);
        [$service, $offering, $staff, $location] = $this->catalog($studio);
        $this->postJson($this->url($studio, 'service-prices'), [
            'service_id' => $service->getKey(), 'amount_minor' => 9000, 'currency' => 'USD',
            'effective_from' => '2026-01-01',
        ])->assertCreated();
        $this->postJson($this->url($studio, 'service-policies'), [
            'service_id' => $service->getKey(), 'booking_lead_minutes' => 60,
            'cancellation_notice_minutes' => 720, 'makeup_policy' => 'studio_credit',
            'effective_from' => '2026-01-01',
        ])->assertCreated();
        $this->postJson($this->url($studio, 'program-offering-overrides'), [
            'program_offering_id' => $offering, 'location_id' => $location,
            'capacity' => 8, 'effective_from' => '2026-01-01',
        ])->assertCreated();
        $this->postJson($this->url($studio, 'program-offering-overrides'), [
            'program_offering_id' => $offering, 'staff_profile_id' => $staff,
            'price_minor' => 11000, 'currency' => 'USD', 'effective_from' => '2026-01-01',
        ])->assertCreated();
        $this->postJson($this->url($studio, 'program-offering-overrides'), [
            'program_offering_id' => $offering, 'staff_profile_id' => $staff, 'location_id' => $location,
            'duration_minutes' => 75, 'effective_from' => '2026-01-01',
        ])->assertCreated();

        $this->getJson($this->url($studio, 'program-offerings')."/{$offering}?effective_on=2026-08-13&staff_profile_id={$staff}&location_id={$location}")
            ->assertOk()
            ->assertJsonPath('data.resolved.duration_minutes', 75)
            ->assertJsonPath('data.resolved.capacity', 8)
            ->assertJsonPath('data.resolved.price_minor', 11000)
            ->assertJsonPath('data.resolved.makeup_policy', 'studio_credit')
            ->assertJsonPath('data.resolved.sources.duration_minutes', 'teacher_location_override')
            ->assertJsonPath('data.resolved.sources.capacity', 'location_override')
            ->assertJsonPath('data.resolved.sources.price_minor', 'teacher_override');

        $this->postJson($this->url($studio, 'service-prices'), [
            'service_id' => $service->getKey(), 'amount_minor' => 9500, 'currency' => 'USD',
            'effective_from' => '2026-06-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('effective_from');

        try {
            DB::transaction(fn () => ServicePrice::query()->create([
                'studio_id' => $studio->getKey(), 'service_id' => $service->getKey(),
                'amount_minor' => 9500, 'currency' => 'USD', 'effective_from' => '2026-06-01',
            ]));
            $this->fail('The database accepted an overlapping effective price period.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('overlap', mb_strtolower($exception->getMessage()));
        }
    }

    public function test_teacher_self_service_is_bound_to_linked_profile_and_time_off_requires_management_approval(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        [, , $staff] = $this->catalog($studio);
        [$teacher, , $membership] = $this->member(MembershipRole::Teacher, $studio);
        Sanctum::actingAs($owner);
        $this->postJson($this->url($studio, 'staff-account-links'), [
            'staff_profile_id' => $staff,
            'studio_membership_id' => $membership->getKey(),
        ])->assertCreated();
        Sanctum::actingAs($teacher);
        $this->postJson($this->url($studio, 'availability-windows'), [
            'staff_profile_id' => $staff, 'weekday' => 1, 'start_time' => '09:00:00',
            'end_time' => '12:00:00', 'timezone' => 'America/New_York', 'enforcement' => 'soft',
        ])->assertUnprocessable()->assertJsonValidationErrors('enforcement');
        $window = $this->postJson($this->url($studio, 'availability-windows'), [
            'staff_profile_id' => $staff, 'weekday' => 1, 'start_time' => '09:00:00',
            'end_time' => '12:00:00', 'timezone' => 'America/New_York',
        ])->assertCreated();
        $this->postJson($this->url($studio, 'availability-windows'), [
            'staff_profile_id' => $staff, 'weekday' => 1, 'start_time' => '11:00:00',
            'end_time' => '13:00:00', 'timezone' => 'America/New_York',
        ])->assertUnprocessable()->assertJsonValidationErrors('schedule');
        $override = $this->postJson($this->url($studio, 'availability-overrides'), [
            'staff_profile_id' => $staff, 'kind' => 'time_off',
            'starts_at' => '2026-12-10T09:00:00', 'ends_at' => '2026-12-10T12:00:00',
            'timezone' => 'America/New_York',
        ])->assertCreated()->assertJsonPath('data.approval_status', null);
        $this->patchJson($this->url($studio, 'availability-overrides').'/'.$override->json('data.id'), [
            'version' => 1, 'approval_status' => 'approved',
        ])->assertUnprocessable()->assertJsonValidationErrors('approval_status');

        Sanctum::actingAs($owner);
        $this->patchJson($this->url($studio, 'availability-overrides').'/'.$override->json('data.id'), [
            'version' => 1, 'approval_status' => 'approved', 'enforcement' => 'soft',
        ])->assertUnprocessable()->assertJsonValidationErrors(['approval_status', 'enforcement']);
        $this->postJson(
            $this->url($studio, 'availability-overrides').'/'.$override->json('data.id').'/approval',
            ['version' => 1, 'approval_status' => 'approved', 'enforcement' => 'soft'],
        )->assertOk();
        $this->assertDatabaseHas('staff_availability_overrides', [
            'id' => $override->json('data.id'),
            'approval_status' => 'approved',
            'enforcement' => 'hard',
            'version' => 2,
        ]);

        Sanctum::actingAs($teacher);
        $this->patchJson($this->url($studio, 'availability-overrides').'/'.$override->json('data.id'), [
            'version' => 2, 'reason' => 'Updated after approval',
        ])->assertOk();
        $this->assertDatabaseHas('staff_availability_overrides', [
            'id' => $override->json('data.id'),
            'approval_status' => 'pending',
            'enforcement' => 'hard',
            'version' => 3,
        ]);
        $this->patchJson($this->url($studio, 'availability-overrides').'/'.$override->json('data.id'), [
            'version' => 3, 'starts_at' => '2026-12-10T10:00:00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['ends_at', 'timezone']);

        $this->assertNotNull($window->json('data.id'));
    }

    public function test_approved_time_off_is_hard_at_the_database_boundary(): void
    {
        [, $studio] = $this->member(MembershipRole::Owner);
        [, , $staff] = $this->catalog($studio);
        $override = $this->postJson($this->url($studio, 'availability-overrides'), [
            'staff_profile_id' => $staff, 'kind' => 'available',
            'starts_at' => '2026-12-14T09:00:00', 'ends_at' => '2026-12-14T10:00:00',
            'timezone' => 'UTC',
        ])->json('data.id');
        $this->postJson($this->url($studio, 'availability-overrides')."/{$override}/approval", [
            'version' => 1, 'approval_status' => 'approved', 'enforcement' => 'soft',
        ])->assertOk();

        try {
            DB::transaction(fn () => DB::table('staff_availability_overrides')
                ->where('id', $override)->update(['kind' => 'time_off']));
            $this->fail('The database accepted soft approved time off.');
        } catch (QueryException $exception) {
            $this->assertMatchesRegularExpression(
                '/approved time off|staff_availability_overrides_state_check/',
                mb_strtolower($exception->getMessage()),
            );
        }

        $this->patchJson($this->url($studio, 'availability-overrides')."/{$override}", [
            'version' => 2, 'kind' => 'time_off',
        ])->assertOk();
        $this->assertDatabaseHas('staff_availability_overrides', [
            'id' => $override, 'kind' => 'time_off', 'approval_status' => 'pending',
            'enforcement' => 'hard', 'version' => 3,
        ]);
    }

    public function test_dst_gaps_and_folds_are_rejected_but_valid_local_times_store_as_utc(): void
    {
        [, $studio] = $this->member(MembershipRole::Owner);
        [, , $staff] = $this->catalog($studio);
        foreach (['2026-03-08T02:30:00', '2026-11-01T01:30:00'] as $local) {
            $this->postJson($this->url($studio, 'availability-overrides'), [
                'staff_profile_id' => $staff, 'kind' => 'available',
                'starts_at' => $local, 'ends_at' => '2026-11-01T03:00:00',
                'timezone' => 'America/New_York',
            ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        }

        $override = $this->postJson($this->url($studio, 'availability-overrides'), [
            'staff_profile_id' => $staff, 'kind' => 'available',
            'starts_at' => '2026-02-10T09:00:00', 'ends_at' => '2026-02-10T10:00:00',
            'timezone' => 'America/New_York',
        ])->assertCreated();
        $this->postJson(
            $this->url($studio, 'availability-overrides').'/'.$override->json('data.id').'/approval',
            ['version' => 1, 'approval_status' => 'approved', 'enforcement' => 'soft'],
        )->assertOk();
        $this->assertDatabaseHas('staff_availability_overrides', [
            'staff_profile_id' => $staff,
            'starts_at' => '2026-02-10 14:00:00',
        ]);
    }

    /** @return array{User, Studio, StudioMembership} */
    private function member(MembershipRole $role, ?Studio $studio = null): array
    {
        $user = User::factory()->create();
        $studio ??= Studio::factory()->create();
        $membership = StudioMembership::query()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $user->getKey(), 'role' => $role,
            'status' => MembershipStatus::Active, 'joined_at' => now(), 'preferences' => [],
        ]);
        Sanctum::actingAs($user);

        return [$user, $studio, $membership];
    }

    /** @return array{Service, string, string, string} */
    private function catalog(Studio $studio): array
    {
        $category = $this->postJson($this->url($studio, 'service-categories'), ['name' => 'Programs'])->json('data.id');
        $serviceId = $this->postJson($this->url($studio, 'services'), [
            'service_category_id' => $category, 'name' => 'Ensemble',
            'default_duration_minutes' => 60, 'default_capacity' => 6,
            'default_price_minor' => 8000, 'currency' => 'USD',
        ])->json('data.id');
        $location = $this->postJson($this->url($studio, 'locations'), [
            'name' => 'Main', 'kind' => 'physical', 'timezone' => 'America/New_York',
        ])->json('data.id');
        $offering = $this->postJson($this->url($studio, 'program-offerings'), [
            'service_id' => $serviceId, 'location_id' => $location, 'name' => 'Fall Ensemble',
            'timezone' => 'America/New_York',
        ])->json('data.id');
        $person = Person::factory()->for($studio)->create();
        $staff = StaffProfile::query()->create([
            'studio_id' => $studio->getKey(), 'person_id' => $person->getKey(),
            'roles' => [StaffRole::Teacher], 'status' => StaffStatus::Active,
        ]);

        return [Service::query()->findOrFail($serviceId), $offering, (string) $staff->getKey(), $location];
    }

    private function url(Studio $studio, string $resource): string
    {
        return "/api/v1/studios/{$studio->slug}/scheduling/{$resource}";
    }
}
