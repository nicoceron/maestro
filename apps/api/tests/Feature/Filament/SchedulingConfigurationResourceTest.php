<?php

namespace Tests\Feature\Filament;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\StaffRole;
use App\Enums\StaffStatus;
use App\Filament\Resources\Scheduling\Availability\Pages\ManageAvailabilityWindows;
use App\Filament\Resources\Scheduling\Equipment\Pages\ManageEquipment;
use App\Filament\Resources\Scheduling\Locations\LocationResource;
use App\Filament\Resources\Scheduling\Locations\Pages\ManageLocations;
use App\Filament\Resources\Scheduling\ProgramOverrides\Pages\ManageProgramOfferingOverrides;
use App\Filament\Resources\Scheduling\Programs\Pages\ManageProgramOfferings;
use App\Filament\Resources\Scheduling\ProgramStaff\Pages\ManageProgramOfferingStaff;
use App\Filament\Resources\Scheduling\Rooms\Pages\ManageRooms;
use App\Filament\Resources\Scheduling\ServiceCategories\Pages\ManageServiceCategories;
use App\Filament\Resources\Scheduling\ServicePolicies\Pages\ManageServicePolicies;
use App\Filament\Resources\Scheduling\ServicePrices\Pages\ManageServicePrices;
use App\Filament\Resources\Scheduling\Services\Pages\ManageServices;
use App\Filament\Resources\Scheduling\Services\ServiceResource;
use App\Filament\Resources\Scheduling\TeacherProfiles\Pages\ManageTeacherSchedulingProfiles;
use App\Filament\Resources\Scheduling\TimeOff\Pages\ManageTimeOff;
use App\Filament\Resources\Scheduling\TravelBuffers\Pages\ManageTravelBuffers;
use App\Models\Equipment;
use App\Models\Location;
use App\Models\Person;
use App\Models\ProgramOffering;
use App\Models\ProgramOfferingOverride;
use App\Models\ProgramOfferingStaff;
use App\Models\Room;
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
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class SchedulingConfigurationResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_builds_service_and_program_catalog_through_domain_actions(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $this->filamentAs($owner, $studio);

        Livewire::test(ManageServiceCategories::class)
            ->callAction('create', data: [
                'name' => '  Private Lessons ',
                'color' => '#4F46E5',
                'sort_order' => 10,
                'active' => true,
            ])->assertHasNoActionErrors();
        $category = ServiceCategory::query()->where('studio_id', $studio->getKey())->sole();
        $this->assertSame('Private Lessons', $category->name);

        Livewire::test(ManageServices::class)
            ->callAction('create', data: [
                'service_category_id' => $category->getKey(),
                'name' => 'Piano 45',
                'default_duration_minutes' => 45,
                'default_capacity' => 1,
                'default_price_minor' => 12500,
                'currency' => 'usd',
                'booking_lead_minutes' => 120,
                'cancellation_notice_minutes' => 1440,
                'makeup_policy' => 'reschedule',
                'active' => true,
            ])->assertHasNoActionErrors();
        $service = Service::query()->where('studio_id', $studio->getKey())->sole();
        $this->assertSame('USD', $service->currency);
        $this->assertSame(12500, $service->default_price_minor);

        Livewire::test(ManageProgramOfferings::class)
            ->callAction('create', data: [
                'service_id' => $service->getKey(),
                'name' => 'Fall Piano',
                'timezone' => 'America/Bogota',
                'enrollment_open' => true,
                'active' => true,
            ])->assertHasNoActionErrors();

        $offering = ProgramOffering::query()->where('studio_id', $studio->getKey())->sole();
        $this->assertNull($offering->duration_minutes);
        $this->assertNull($offering->price_minor);
    }

    public function test_manager_builds_same_location_rooms_and_equipment(): void
    {
        [$administrator, $studio] = $this->member(MembershipRole::Administrator);
        $this->filamentAs($administrator, $studio);

        Livewire::test(ManageLocations::class)
            ->callAction('create', data: [
                'name' => 'Downtown',
                'kind' => 'physical',
                'timezone' => 'America/Bogota',
                'city' => 'Bogotá',
                'active' => true,
            ])->assertHasNoActionErrors();
        $location = Location::query()->where('studio_id', $studio->getKey())->sole();

        Livewire::test(ManageRooms::class)
            ->callAction('create', data: [
                'location_id' => $location->getKey(),
                'name' => 'Studio A',
                'capacity' => 4,
                'active' => true,
            ])->assertHasNoActionErrors();
        $room = Room::query()->where('studio_id', $studio->getKey())->sole();

        Livewire::test(ManageEquipment::class)
            ->callAction('create', data: [
                'location_id' => $location->getKey(),
                'room_id' => $room->getKey(),
                'name' => 'Digital piano',
                'quantity' => 1,
                'active' => true,
            ])->assertHasNoActionErrors();

        $equipment = Equipment::query()->where('studio_id', $studio->getKey())->sole();
        $this->assertSame($room->getKey(), $equipment->room_id);
        $this->assertSame($location->getKey(), $equipment->location_id);
    }

    public function test_teacher_has_read_only_catalog_and_billing_has_no_configuration_access(): void
    {
        [$teacher, $studio] = $this->member(MembershipRole::Teacher);
        $this->filamentAs($teacher, $studio);
        $this->assertTrue(ServiceResource::canViewAny());
        $this->assertFalse(ServiceResource::canCreate());
        $this->assertTrue(LocationResource::canViewAny());
        $this->assertFalse(LocationResource::canCreate());

        [$billing, $billingStudio] = $this->member(MembershipRole::Billing);
        $this->filamentAs($billing, $billingStudio);
        $this->assertFalse(ServiceResource::canViewAny());
        $this->assertFalse(LocationResource::canViewAny());
    }

    public function test_teacher_can_manage_only_their_linked_availability_and_not_soften_it(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        [$teacher, , $teacherMembership] = $this->member(MembershipRole::Teacher, $studio);
        $person = Person::factory()->for($studio)->create();
        $profile = StaffProfile::query()->create([
            'studio_id' => $studio->getKey(),
            'person_id' => $person->getKey(),
            'roles' => [StaffRole::Teacher],
            'status' => StaffStatus::Active,
        ]);
        StaffAccountLink::query()->create([
            'studio_id' => $studio->getKey(),
            'staff_profile_id' => $profile->getKey(),
            'studio_membership_id' => $teacherMembership->getKey(),
            'active' => true,
        ]);
        $this->filamentAs($teacher, $studio);

        Livewire::test(ManageAvailabilityWindows::class)
            ->callAction('create', data: [
                'staff_profile_id' => $profile->getKey(),
                'weekday' => 1,
                'start_time' => '09:00:00',
                'end_time' => '12:00:00',
                'timezone' => 'America/Bogota',
                'active' => true,
            ])->assertHasNoActionErrors();

        $window = StaffAvailabilityWindow::query()->where('studio_id', $studio->getKey())->sole();
        $this->assertSame('hard', $window->enforcement->value);

        $this->filamentAs($owner, $studio);
        Livewire::test(ManageAvailabilityWindows::class)
            ->assertCanSeeTableRecords([$window]);
    }

    public function test_manager_configures_effective_prices_policies_staff_and_overrides(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $this->filamentAs($owner, $studio);
        $category = ServiceCategory::query()->create([
            'studio_id' => $studio->getKey(), 'name' => 'Lessons', 'sort_order' => 0, 'active' => true,
        ]);
        $service = Service::query()->create([
            'studio_id' => $studio->getKey(), 'service_category_id' => $category->getKey(),
            'name' => 'Piano', 'default_duration_minutes' => 45, 'default_capacity' => 1,
            'default_price_minor' => 10000, 'currency' => 'USD', 'booking_lead_minutes' => 0,
            'cancellation_notice_minutes' => 1440, 'makeup_policy' => 'reschedule', 'active' => true,
        ]);
        $location = Location::query()->create([
            'studio_id' => $studio->getKey(), 'name' => 'North', 'kind' => 'physical',
            'timezone' => 'America/Bogota', 'active' => true,
        ]);
        $offering = ProgramOffering::query()->create([
            'studio_id' => $studio->getKey(), 'service_id' => $service->getKey(),
            'location_id' => $location->getKey(), 'name' => 'Fall piano', 'timezone' => 'America/Bogota',
            'enrollment_open' => true, 'active' => true,
        ]);
        $person = Person::factory()->for($studio)->create();
        $teacher = StaffProfile::query()->create([
            'studio_id' => $studio->getKey(), 'person_id' => $person->getKey(),
            'roles' => [StaffRole::Teacher], 'status' => StaffStatus::Active,
        ]);

        Livewire::test(ManageServicePrices::class)->callAction('create', data: [
            'service_id' => $service->getKey(), 'amount_minor' => 12500, 'currency' => 'usd',
            'effective_from' => '2026-09-01', 'active' => true,
        ])->assertHasNoActionErrors();
        Livewire::test(ManageServicePolicies::class)->callAction('create', data: [
            'service_id' => $service->getKey(), 'booking_lead_minutes' => 120,
            'cancellation_notice_minutes' => 2880, 'makeup_policy' => 'studio_credit',
            'effective_from' => '2026-09-01', 'active' => true,
        ])->assertHasNoActionErrors();
        Livewire::test(ManageProgramOfferingStaff::class)->callAction('create', data: [
            'program_offering_id' => $offering->getKey(), 'staff_profile_id' => $teacher->getKey(),
            'is_primary' => true, 'active' => true,
        ])->assertHasNoActionErrors();
        Livewire::test(ManageProgramOfferingOverrides::class)->callAction('create', data: [
            'program_offering_id' => $offering->getKey(), 'staff_profile_id' => $teacher->getKey(),
            'location_id' => $location->getKey(), 'price_minor' => 14000, 'currency' => 'usd',
            'capacity' => 2, 'effective_from' => '2026-09-01', 'active' => true,
        ])->assertHasNoActionErrors();

        $this->assertSame('USD', ServicePrice::query()->sole()->currency);
        $this->assertSame('studio_credit', ServicePolicy::query()->sole()->makeup_policy->value);
        $this->assertTrue(ProgramOfferingStaff::query()->sole()->is_primary);
        $this->assertSame(14000, ProgramOfferingOverride::query()->sole()->price_minor);
    }

    public function test_teacher_constraints_travel_and_time_off_use_guarded_domain_actions(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        [$teacherUser, , $teacherMembership] = $this->member(MembershipRole::Teacher, $studio);
        $person = Person::factory()->for($studio)->create();
        $teacher = StaffProfile::query()->create([
            'studio_id' => $studio->getKey(), 'person_id' => $person->getKey(),
            'roles' => [StaffRole::Teacher], 'status' => StaffStatus::Active,
        ]);
        StaffAccountLink::query()->create([
            'studio_id' => $studio->getKey(), 'staff_profile_id' => $teacher->getKey(),
            'studio_membership_id' => $teacherMembership->getKey(), 'active' => true,
        ]);
        $from = Location::query()->create([
            'studio_id' => $studio->getKey(), 'name' => 'North', 'kind' => 'physical',
            'timezone' => 'America/Bogota', 'active' => true,
        ]);
        $to = Location::query()->create([
            'studio_id' => $studio->getKey(), 'name' => 'South', 'kind' => 'physical',
            'timezone' => 'America/Bogota', 'active' => true,
        ]);
        $this->filamentAs($teacherUser, $studio);

        Livewire::test(ManageTeacherSchedulingProfiles::class)->callAction('create', data: [
            'staff_profile_id' => $teacher->getKey(), 'timezone' => 'America/Bogota',
            'default_buffer_before_minutes' => 10, 'default_buffer_after_minutes' => 15,
            'default_travel_buffer_minutes' => 20, 'max_daily_minutes' => 360,
            'max_weekly_minutes' => 1800, 'active' => true,
        ])->assertHasNoActionErrors();
        Livewire::test(ManageTravelBuffers::class)->callAction('create', data: [
            'staff_profile_id' => $teacher->getKey(), 'from_location_id' => $from->getKey(),
            'to_location_id' => $to->getKey(), 'minutes' => 35, 'active' => true,
        ])->assertHasNoActionErrors();
        Livewire::test(ManageTimeOff::class)->callAction('create', data: [
            'staff_profile_id' => $teacher->getKey(), 'kind' => 'time_off',
            'timezone' => 'America/Bogota', 'starts_at' => '2026-09-14T09:00:00',
            'ends_at' => '2026-09-14T12:00:00', 'reason' => 'Medical appointment', 'active' => true,
        ])->assertHasNoActionErrors();

        $request = StaffAvailabilityOverride::query()->sole();
        $this->assertSame('pending', $request->approval_status->value);
        $this->assertSame('hard', $request->enforcement->value);
        $this->assertSame(360, StaffSchedulingProfile::query()->sole()->max_daily_minutes);
        $this->assertSame(35, StaffTravelBuffer::query()->sole()->minutes);

        $this->filamentAs($owner, $studio);
        Livewire::test(ManageTimeOff::class)
            ->callAction(TestAction::make('approve')->table($request))
            ->assertHasNoActionErrors();
        $this->assertSame('approved', $request->refresh()->approval_status->value);
        $this->assertSame('hard', $request->enforcement->value);
    }

    private function filamentAs(User $user, Studio $studio): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($studio);
        $membership = StudioMembership::query()
            ->where('studio_id', $studio->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active)
            ->sole();
        app(TenantContext::class)->activate($studio, $membership);
    }

    /** @return array{User, Studio} */
    private function member(MembershipRole $role, ?Studio $studio = null): array
    {
        $user = User::factory()->create();
        $studio ??= Studio::factory()->create();
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
