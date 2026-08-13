<?php

namespace Tests\Feature\Security;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\StaffRole;
use App\Enums\StaffStatus;
use App\Models\Equipment;
use App\Models\Location;
use App\Models\Person;
use App\Models\ProgramOffering;
use App\Models\ProgramOfferingOverride;
use App\Models\ProgramOfferingStaff;
use App\Models\Room;
use App\Models\RoomEquipment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePolicy;
use App\Models\ServicePrice;
use App\Models\StaffAccountLink;
use App\Models\StaffAvailabilityOverride;
use App\Models\StaffAvailabilityWindow;
use App\Models\StaffProfile;
use App\Models\StaffSchedulingProfile;
use App\Models\StaffTravelBuffer;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SchedulingFoundationRowLevelSecurityTest extends TestCase
{
    public function test_every_foundation_table_is_default_deny_and_tenant_scoped_at_restricted_runtime(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required for the scheduling RLS integration test.');
        }

        [$runtime, $runtimeName] = $this->runtimeConnection();
        $records = [];
        $studios = [Studio::factory()->create(), Studio::factory()->create()];

        foreach ($studios as $index => $studio) {
            $user = User::factory()->create();
            $membership = StudioMembership::query()->create([
                'studio_id' => $studio->getKey(),
                'user_id' => $user->getKey(),
                'role' => MembershipRole::Teacher,
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
                'preferences' => [],
            ]);
            $person = Person::factory()->for($studio)->create();
            $staff = StaffProfile::query()->create([
                'studio_id' => $studio->getKey(),
                'person_id' => $person->getKey(),
                'roles' => [StaffRole::Teacher],
                'status' => StaffStatus::Active,
            ]);
            $category = ServiceCategory::query()->create([
                'studio_id' => $studio->getKey(), 'name' => "Programs {$index}",
            ]);
            $service = Service::query()->create([
                'studio_id' => $studio->getKey(), 'service_category_id' => $category->getKey(),
                'name' => "Piano {$index}", 'default_duration_minutes' => 45,
                'default_capacity' => 1, 'default_price_minor' => 10000,
                'currency' => 'USD', 'makeup_policy' => 'none',
            ]);
            $price = ServicePrice::query()->create([
                'studio_id' => $studio->getKey(), 'service_id' => $service->getKey(),
                'amount_minor' => 10000, 'currency' => 'USD', 'effective_from' => '2026-01-01',
            ]);
            $policy = ServicePolicy::query()->create([
                'studio_id' => $studio->getKey(), 'service_id' => $service->getKey(),
                'booking_lead_minutes' => 60, 'cancellation_notice_minutes' => 1440,
                'makeup_policy' => 'none', 'effective_from' => '2026-01-01',
            ]);
            $location = Location::query()->create([
                'studio_id' => $studio->getKey(), 'name' => "Main {$index}",
                'kind' => 'physical', 'timezone' => 'UTC',
            ]);
            $destination = Location::query()->create([
                'studio_id' => $studio->getKey(), 'name' => "Annex {$index}",
                'kind' => 'physical', 'timezone' => 'UTC',
            ]);
            $room = Room::query()->create([
                'studio_id' => $studio->getKey(), 'location_id' => $location->getKey(),
                'name' => "Room {$index}", 'capacity' => 4,
            ]);
            $equipment = Equipment::query()->create([
                'studio_id' => $studio->getKey(), 'location_id' => $location->getKey(),
                'name' => "Piano {$index}", 'quantity' => 2,
            ]);
            $roomEquipment = RoomEquipment::query()->create([
                'studio_id' => $studio->getKey(), 'location_id' => $location->getKey(),
                'room_id' => $room->getKey(), 'equipment_id' => $equipment->getKey(), 'quantity' => 1,
            ]);
            $offering = ProgramOffering::query()->create([
                'studio_id' => $studio->getKey(), 'service_id' => $service->getKey(),
                'location_id' => $location->getKey(), 'room_id' => $room->getKey(),
                'name' => "Fall {$index}", 'timezone' => 'UTC',
            ]);
            $offeringOverride = ProgramOfferingOverride::query()->create([
                'studio_id' => $studio->getKey(), 'program_offering_id' => $offering->getKey(),
                'staff_profile_id' => $staff->getKey(), 'duration_minutes' => 60,
                'effective_from' => '2026-01-01',
            ]);
            $offeringStaff = ProgramOfferingStaff::query()->create([
                'studio_id' => $studio->getKey(), 'program_offering_id' => $offering->getKey(),
                'staff_profile_id' => $staff->getKey(), 'is_primary' => true,
            ]);
            $accountLink = StaffAccountLink::query()->create([
                'studio_id' => $studio->getKey(), 'staff_profile_id' => $staff->getKey(),
                'studio_membership_id' => $membership->getKey(),
            ]);
            $schedulingProfile = StaffSchedulingProfile::query()->create([
                'studio_id' => $studio->getKey(), 'staff_profile_id' => $staff->getKey(), 'timezone' => 'UTC',
            ]);
            $window = StaffAvailabilityWindow::query()->create([
                'studio_id' => $studio->getKey(), 'staff_profile_id' => $staff->getKey(),
                'weekday' => 1, 'start_time' => '09:00:00', 'end_time' => '12:00:00',
                'timezone' => 'UTC', 'enforcement' => 'hard',
            ]);
            $override = StaffAvailabilityOverride::query()->create([
                'studio_id' => $studio->getKey(), 'staff_profile_id' => $staff->getKey(),
                'kind' => 'available', 'approval_status' => 'pending', 'enforcement' => 'hard',
                'starts_at' => '2026-10-01 09:00:00+00', 'ends_at' => '2026-10-01 12:00:00+00',
                'timezone' => 'UTC',
            ]);
            $travel = StaffTravelBuffer::query()->create([
                'studio_id' => $studio->getKey(), 'staff_profile_id' => $staff->getKey(),
                'from_location_id' => $location->getKey(), 'to_location_id' => $destination->getKey(),
                'minutes' => 15,
            ]);

            $records[(string) $studio->getKey()] = compact(
                'category', 'service', 'price', 'policy', 'location', 'destination', 'room', 'equipment',
                'roomEquipment', 'offering', 'offeringOverride', 'offeringStaff', 'accountLink',
                'schedulingProfile', 'window', 'override', 'travel',
            );
        }

        $tables = [
            'service_categories', 'services', 'service_prices', 'service_policies', 'locations',
            'rooms', 'equipment', 'room_equipment', 'program_offerings', 'program_offering_overrides',
            'program_offering_staff', 'staff_account_links', 'staff_scheduling_profiles',
            'staff_availability_windows', 'staff_availability_overrides', 'staff_travel_buffers',
        ];

        try {
            foreach ($tables as $table) {
                $this->assertSame(0, $runtime->table($table)->count(), "{$table} must default deny.");
            }

            $firstStudio = $studios[0];
            $secondStudio = $studios[1];
            $this->setContext($runtime, 'app.current_studio_id', (string) $firstStudio->getKey());

            foreach ($tables as $table) {
                $expected = $table === 'locations' ? 2 : 1;
                $this->assertSame($expected, $runtime->table($table)->count(), "{$table} leaked another tenant.");
            }

            $crossTenantInsertDenied = false;
            try {
                $runtime->table('service_categories')->insert([
                    'id' => (string) Str::ulid(), 'studio_id' => $secondStudio->getKey(),
                    'name' => 'Cross tenant', 'normalized_name' => 'cross tenant',
                    'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (QueryException) {
                $crossTenantInsertDenied = true;
            }
            $this->assertTrue($crossTenantInsertDenied);

            $crossLocationAssignmentDenied = false;
            $first = $records[(string) $firstStudio->getKey()];
            try {
                $runtime->table('room_equipment')->insert([
                    'id' => (string) Str::ulid(), 'studio_id' => $firstStudio->getKey(),
                    'location_id' => $first['destination']->getKey(), 'room_id' => $first['room']->getKey(),
                    'equipment_id' => $first['equipment']->getKey(), 'quantity' => 1,
                    'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (QueryException) {
                $crossLocationAssignmentDenied = true;
            }
            $this->assertTrue($crossLocationAssignmentDenied);
        } finally {
            DB::purge($runtimeName);
            Studio::query()->whereKey(array_map(
                static fn (Studio $studio): string => (string) $studio->getKey(),
                $studios,
            ))->delete();
        }
    }

    /** @return array{ConnectionInterface, string} */
    private function runtimeConnection(): array
    {
        $username = (string) env('DB_RUNTIME_USERNAME');
        $password = (string) env('DB_RUNTIME_PASSWORD');

        if ($username === '' || $password === '') {
            $this->markTestSkipped('A restricted PostgreSQL runtime role was not configured.');
        }

        $name = 'pgsql_runtime_scheduling_'.Str::lower((string) Str::ulid());
        config([
            "database.connections.{$name}" => array_replace(
                config('database.connections.pgsql'),
                ['username' => $username, 'password' => $password],
            ),
        ]);
        DB::purge($name);

        return [DB::connection($name), $name];
    }

    private function setContext(ConnectionInterface $connection, string $key, string $value): void
    {
        $connection->statement('select set_config(?, ?, false)', [$key, $value]);
    }
}
