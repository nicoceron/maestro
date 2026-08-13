<?php

namespace App\Support\Scheduling;

use App\Enums\ApprovalStatus;
use App\Enums\AvailabilityEnforcement;
use App\Enums\AvailabilityOverrideType;
use App\Enums\LocationKind;
use App\Enums\MakeupPolicy;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Equipment;
use App\Models\Location;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SchedulingRecordRegistry
{
    /** @var array<string, class-string<Model>> */
    private const MODELS = [
        'service-categories' => ServiceCategory::class,
        'services' => Service::class,
        'service-prices' => ServicePrice::class,
        'service-policies' => ServicePolicy::class,
        'locations' => Location::class,
        'rooms' => Room::class,
        'equipment' => Equipment::class,
        'room-equipment' => RoomEquipment::class,
        'program-offerings' => ProgramOffering::class,
        'program-offering-overrides' => ProgramOfferingOverride::class,
        'program-offering-staff' => ProgramOfferingStaff::class,
        'staff-account-links' => StaffAccountLink::class,
        'staff-scheduling-profiles' => StaffSchedulingProfile::class,
        'availability-windows' => StaffAvailabilityWindow::class,
        'availability-overrides' => StaffAvailabilityOverride::class,
        'travel-buffers' => StaffTravelBuffer::class,
    ];

    /** @return list<string> */
    public function types(): array
    {
        return array_keys(self::MODELS);
    }

    /** @return class-string<Model> */
    public function modelClass(string $type): string
    {
        return self::MODELS[$type] ?? throw ValidationException::withMessages([
            'resource' => 'The scheduling resource is not supported.',
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(string $type, Studio $studio, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $exists = fn (string $table) => Rule::exists($table, 'id')
            ->where('studio_id', $studio->getKey());
        $base = ['active' => ['sometimes', 'boolean']];
        $canManage = request()->user() !== null
            && app(SchedulingAccess::class)->canManage(request()->user(), $studio);
        $managementOnly = $canManage ? ['sometimes'] : ['prohibited'];

        return match ($type) {
            'service-categories' => [...$base,
                'name' => [$required, 'string', 'max:100', 'regex:/\S/u'],
                'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'color' => ['sometimes', 'nullable', 'hex_color'],
                'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            ],
            'services' => [...$base,
                'service_category_id' => [$required, 'string', 'ulid', $exists('service_categories')],
                'name' => [$required, 'string', 'max:120', 'regex:/\S/u'],
                'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'default_duration_minutes' => [$required, 'integer', 'min:5', 'max:1440'],
                'default_capacity' => [$required, 'integer', 'min:1', 'max:1000'],
                'default_price_minor' => [$required, 'integer', 'min:0', 'max:999999999'],
                'currency' => [$required, 'string', 'size:3', 'alpha:ascii'],
                'booking_lead_minutes' => ['sometimes', 'integer', 'min:0', 'max:525600'],
                'cancellation_notice_minutes' => ['sometimes', 'integer', 'min:0', 'max:525600'],
                'makeup_policy' => ['sometimes', Rule::enum(MakeupPolicy::class)],
            ],
            'service-prices' => [...$base,
                'service_id' => [$required, 'string', 'ulid', $exists('services')],
                'amount_minor' => [$required, 'integer', 'min:0', 'max:999999999'],
                'currency' => [$required, 'string', 'size:3', 'alpha:ascii'],
                'effective_from' => [$required, 'date_format:Y-m-d'],
                'effective_until' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            ],
            'service-policies' => [...$base,
                'service_id' => [$required, 'string', 'ulid', $exists('services')],
                'booking_lead_minutes' => [$required, 'integer', 'min:0', 'max:525600'],
                'cancellation_notice_minutes' => [$required, 'integer', 'min:0', 'max:525600'],
                'makeup_policy' => [$required, Rule::enum(MakeupPolicy::class)],
                'effective_from' => [$required, 'date_format:Y-m-d'],
                'effective_until' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            ],
            'locations' => [...$base,
                'name' => [$required, 'string', 'max:120', 'regex:/\S/u'],
                'kind' => [$required, Rule::enum(LocationKind::class)],
                'timezone' => [$required, 'timezone:all'],
                'address_line_1' => ['sometimes', 'nullable', 'string', 'max:160'],
                'address_line_2' => ['sometimes', 'nullable', 'string', 'max:160'],
                'city' => ['sometimes', 'nullable', 'string', 'max:100'],
                'region' => ['sometimes', 'nullable', 'string', 'max:100'],
                'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
                'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha:ascii'],
                'online_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
                'private_instructions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            ],
            'rooms' => [...$base,
                'location_id' => [$required, 'string', 'ulid', $exists('locations')],
                'name' => [$required, 'string', 'max:100', 'regex:/\S/u'],
                'capacity' => [$required, 'integer', 'min:1', 'max:1000'],
            ],
            'equipment' => [...$base,
                'location_id' => [$required, 'string', 'ulid', $exists('locations')],
                'room_id' => ['sometimes', 'nullable', 'string', 'ulid', $exists('rooms')],
                'name' => [$required, 'string', 'max:120', 'regex:/\S/u'],
                'quantity' => [$required, 'integer', 'min:1', 'max:1000'],
                'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            ],
            'room-equipment' => [...$base,
                'location_id' => [$required, 'string', 'ulid', $exists('locations')],
                'room_id' => [$required, 'string', 'ulid', $exists('rooms')],
                'equipment_id' => [$required, 'string', 'ulid', $exists('equipment')],
                'quantity' => [$required, 'integer', 'min:1', 'max:1000'],
            ],
            'program-offerings' => [...$base,
                'service_id' => [$required, 'string', 'ulid', $exists('services')],
                'location_id' => ['sometimes', 'nullable', 'string', 'ulid', $exists('locations')],
                'room_id' => ['sometimes', 'nullable', 'string', 'ulid', $exists('rooms')],
                'name' => [$required, 'string', 'max:140', 'regex:/\S/u'],
                'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'timezone' => [$required, 'timezone:all'],
                'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:1440'],
                'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
                'price_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999'],
                'currency' => ['sometimes', 'nullable', 'required_with:price_minor', 'string', 'size:3', 'alpha:ascii'],
                'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
                'enrollment_open' => ['sometimes', 'boolean'],
            ],
            'program-offering-staff' => [...$base,
                'program_offering_id' => [$required, 'string', 'ulid', $exists('program_offerings')],
                'staff_profile_id' => [$required, 'string', 'ulid', $exists('staff_profiles')],
                'is_primary' => ['sometimes', 'boolean'],
            ],
            'program-offering-overrides' => [...$base,
                'program_offering_id' => [$required, 'string', 'ulid', $exists('program_offerings')],
                'staff_profile_id' => ['sometimes', 'nullable', 'string', 'ulid', $exists('staff_profiles')],
                'location_id' => ['sometimes', 'nullable', 'string', 'ulid', $exists('locations')],
                'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:1440'],
                'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
                'price_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999'],
                'currency' => ['sometimes', 'nullable', 'required_with:price_minor', 'string', 'size:3', 'alpha:ascii'],
                'booking_lead_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:525600'],
                'cancellation_notice_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:525600'],
                'makeup_policy' => ['sometimes', 'nullable', Rule::enum(MakeupPolicy::class)],
                'effective_from' => [$required, 'date_format:Y-m-d'],
                'effective_until' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            ],
            'staff-account-links' => [...$base,
                'staff_profile_id' => [$required, 'string', 'ulid', $exists('staff_profiles')],
                'studio_membership_id' => [$required, 'string', 'ulid', $exists('studio_memberships')],
            ],
            'staff-scheduling-profiles' => [...$base,
                'staff_profile_id' => [$required, 'string', 'ulid', $exists('staff_profiles')],
                'timezone' => [$required, 'timezone:all'],
                'default_buffer_before_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
                'default_buffer_after_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
                'default_travel_buffer_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
                'max_daily_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
                'max_weekly_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],
            ],
            'availability-windows' => [...$base,
                'staff_profile_id' => [$required, 'string', 'ulid', $exists('staff_profiles')],
                'weekday' => [$required, 'integer', 'between:1,7'],
                'start_time' => [$required, 'date_format:H:i:s'],
                'end_time' => [$required, 'date_format:H:i:s', 'after:start_time'],
                'timezone' => [$required, 'timezone:all'],
                'enforcement' => [...$managementOnly, Rule::enum(AvailabilityEnforcement::class)],
            ],
            'availability-overrides' => [...$base,
                'staff_profile_id' => [$required, 'string', 'ulid', $exists('staff_profiles')],
                'kind' => [$required, Rule::enum(AvailabilityOverrideType::class)],
                'approval_status' => ['prohibited'],
                'enforcement' => ['prohibited'],
                'starts_at' => [$creating ? 'required' : 'required_with:ends_at,timezone', 'string', 'date_format:Y-m-d\TH:i:s'],
                'ends_at' => [$creating ? 'required' : 'required_with:starts_at,timezone', 'string', 'date_format:Y-m-d\TH:i:s'],
                'timezone' => [$creating ? 'required' : 'required_with:starts_at,ends_at', 'timezone:all'],
                'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            ],
            'travel-buffers' => [...$base,
                'staff_profile_id' => [$required, 'string', 'ulid', $exists('staff_profiles')],
                'from_location_id' => [$required, 'string', 'ulid', $exists('locations')],
                'to_location_id' => [$required, 'string', 'ulid', 'different:from_location_id', $exists('locations')],
                'minutes' => [$required, 'integer', 'min:0', 'max:240'],
            ],
        };
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    public function prepare(string $type, array $attributes): array
    {
        foreach (['currency', 'country_code'] as $key) {
            if (isset($attributes[$key]) && is_string($attributes[$key])) {
                $attributes[$key] = mb_strtoupper($attributes[$key]);
            }
        }

        if ($type === 'availability-overrides'
            && isset($attributes['starts_at'], $attributes['ends_at'], $attributes['timezone'])) {
            $starts = LocalDateTime::toUtc($attributes['starts_at'], $attributes['timezone'], 'starts_at');
            $ends = LocalDateTime::toUtc($attributes['ends_at'], $attributes['timezone'], 'ends_at');

            if ($ends->lessThanOrEqualTo($starts)) {
                throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
            }

            if ($ends->diffInDays($starts) > 366) {
                throw ValidationException::withMessages(['ends_at' => 'An override cannot span more than 366 days.']);
            }

            $attributes['starts_at'] = $starts;
            $attributes['ends_at'] = $ends;
        }

        if ($type === 'availability-overrides'
            && ($attributes['kind'] ?? null) === AvailabilityOverrideType::TimeOff->value
            && ($attributes['approval_status'] ?? null) === ApprovalStatus::Approved->value) {
            $attributes['enforcement'] = AvailabilityEnforcement::Hard->value;
        }

        return $attributes;
    }

    /** @param array<string, mixed> $attributes */
    public function assertDomain(string $type, Studio $studio, array $attributes, ?Model $record = null): void
    {
        $value = fn (string $key): mixed => array_key_exists($key, $attributes)
            ? $attributes[$key]
            : $record?->getAttribute($key);

        if (in_array($type, ['equipment', 'program-offerings'], true) && $value('room_id') !== null) {
            $room = Room::query()->where('studio_id', $studio->getKey())->findOrFail($value('room_id'));

            if ($room->location_id !== $value('location_id')) {
                throw ValidationException::withMessages(['room_id' => 'The room must belong to the selected location.']);
            }
        }

        if ($type === 'room-equipment') {
            $room = Room::query()->where('studio_id', $studio->getKey())->findOrFail($value('room_id'));
            $equipment = Equipment::query()->where('studio_id', $studio->getKey())->findOrFail($value('equipment_id'));

            if ($room->location_id !== $equipment->location_id) {
                throw ValidationException::withMessages(['equipment_id' => 'Equipment must belong to the room location.']);
            }

            if ($room->location_id !== $value('location_id')) {
                throw ValidationException::withMessages(['location_id' => 'The location must match the room and equipment.']);
            }

            if ((int) $value('quantity') > $equipment->quantity) {
                throw ValidationException::withMessages(['quantity' => 'Assigned room quantity exceeds available equipment.']);
            }

            $assigned = RoomEquipment::query()
                ->where('studio_id', $studio->getKey())
                ->where('equipment_id', $equipment->getKey())
                ->where('active', true)
                ->when($record, fn ($query) => $query->where('id', '!=', $record->getKey()))
                ->sum('quantity');

            if ($assigned + (int) $value('quantity') > $equipment->quantity) {
                throw ValidationException::withMessages(['quantity' => 'Room assignments exceed available equipment stock.']);
            }
        }

        if (in_array($type, [
            'program-offering-staff',
            'staff-account-links',
            'staff-scheduling-profiles',
            'availability-windows',
            'availability-overrides',
            'travel-buffers',
        ], true)) {
            $profile = StaffProfile::query()->where('studio_id', $studio->getKey())->findOrFail($value('staff_profile_id'));

            if (! in_array('teacher', $profile->roles, true)) {
                throw ValidationException::withMessages(['staff_profile_id' => 'The staff profile must include the teacher role.']);
            }
        }

        if ($type === 'program-offering-overrides' && $value('staff_profile_id') !== null) {
            $profile = StaffProfile::query()->where('studio_id', $studio->getKey())->findOrFail($value('staff_profile_id'));

            if (! in_array('teacher', $profile->roles, true)) {
                throw ValidationException::withMessages(['staff_profile_id' => 'The staff profile must include the teacher role.']);
            }
        }

        if ($type === 'program-offering-overrides'
            && $value('staff_profile_id') === null
            && $value('location_id') === null) {
            throw ValidationException::withMessages([
                'staff_profile_id' => 'An offering override requires a teacher or location scope.',
            ]);
        }

        if (in_array($type, ['program-offerings', 'program-offering-overrides'], true)
            && (($value('price_minor') === null) !== ($value('currency') === null))) {
            throw ValidationException::withMessages([
                'currency' => 'Price and currency must either both be present or both be omitted.',
            ]);
        }

        if ($type === 'locations'
            && $value('online_url') !== null
            && $value('kind') !== 'online') {
            throw ValidationException::withMessages([
                'online_url' => 'An online URL may only be assigned to an online location.',
            ]);
        }

        if ($type === 'staff-account-links') {
            $membership = StudioMembership::query()
                ->where('studio_id', $studio->getKey())
                ->findOrFail($value('studio_membership_id'));

            if ($membership->status !== MembershipStatus::Active
                || $membership->role !== MembershipRole::Teacher) {
                throw ValidationException::withMessages(['studio_membership_id' => 'The linked account must have an active studio membership.']);
            }
        }

        if (in_array($type, ['staff-scheduling-profiles'], true)
            && $value('max_daily_minutes') !== null
            && $value('max_weekly_minutes') !== null
            && (int) $value('max_weekly_minutes') < (int) $value('max_daily_minutes')) {
            throw ValidationException::withMessages(['max_weekly_minutes' => 'Weekly minutes cannot be less than daily minutes.']);
        }

        if (in_array($type, ['service-prices', 'service-policies', 'program-offering-overrides'], true)
            && ($attributes['active'] ?? $record?->active ?? true)) {
            $class = $this->modelClass($type);
            $scopeColumns = $type === 'program-offering-overrides'
                ? ['program_offering_id', 'staff_profile_id', 'location_id']
                : ['service_id'];
            $query = $class::query()->where('studio_id', $studio->getKey())
                ->where('active', true)
                ->when($record, fn ($query) => $query->where('id', '!=', $record->getKey()))
                ->where('effective_from', '<=', $value('effective_until') ?? '9999-12-31')
                ->where(fn ($query) => $query
                    ->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $value('effective_from')));

            foreach ($scopeColumns as $column) {
                $query->where($column, $value($column));
            }

            if ($query->exists()) {
                throw ValidationException::withMessages(['effective_from' => 'Effective date ranges may not overlap.']);
            }
        }
    }

    /** @param array<string, mixed> $attributes */
    public function lockDomain(string $type, Studio $studio, array $attributes, ?Model $record = null): void
    {
        $value = fn (string $key): mixed => array_key_exists($key, $attributes)
            ? $attributes[$key]
            : $record?->getAttribute($key);

        if ($type === 'room-equipment') {
            Equipment::query()->where('studio_id', $studio->getKey())->lockForUpdate()->findOrFail($value('equipment_id'));
            RoomEquipment::query()
                ->where('studio_id', $studio->getKey())
                ->where('equipment_id', $value('equipment_id'))
                ->lockForUpdate()->get();
        }

        if (in_array($type, ['service-prices', 'service-policies', 'program-offering-overrides'], true)) {
            $class = $this->modelClass($type);
            $parentColumn = $type === 'program-offering-overrides' ? 'program_offering_id' : 'service_id';
            $parentClass = $type === 'program-offering-overrides' ? ProgramOffering::class : Service::class;
            $parentClass::query()
                ->where('studio_id', $studio->getKey())
                ->lockForUpdate()
                ->findOrFail($value($parentColumn));
            $class::query()
                ->where('studio_id', $studio->getKey())
                ->where($parentColumn, $value($parentColumn))
                ->lockForUpdate()->get();
        }

        if (in_array($type, ['availability-windows', 'availability-overrides'], true)) {
            $class = $this->modelClass($type);
            StaffProfile::query()
                ->where('studio_id', $studio->getKey())
                ->lockForUpdate()
                ->findOrFail($value('staff_profile_id'));
            $class::query()
                ->where('studio_id', $studio->getKey())
                ->where('staff_profile_id', $value('staff_profile_id'))
                ->lockForUpdate()->get();
        }
    }

    /** @return list<string> */
    public function relations(string $type): array
    {
        return match ($type) {
            'services' => ['category'],
            'service-prices', 'service-policies' => ['service'],
            'rooms' => ['location'],
            'equipment' => ['location', 'room'],
            'room-equipment' => ['room', 'equipment'],
            'program-offerings' => ['service', 'location', 'room', 'staffAssignments'],
            'program-offering-staff' => ['offering', 'staffProfile.person'],
            'program-offering-overrides' => ['offering', 'staffProfile.person', 'location'],
            'staff-account-links' => ['staffProfile.person', 'membership'],
            'staff-scheduling-profiles', 'availability-windows', 'availability-overrides' => ['staffProfile.person'],
            'travel-buffers' => ['staffProfile.person', 'fromLocation', 'toLocation'],
            default => [],
        };
    }

    public function validationException(QueryException $exception): ?ValidationException
    {
        $message = mb_strtolower($exception->getMessage());

        if (str_contains($message, 'overlap')) {
            return ValidationException::withMessages(['schedule' => 'The date or time range overlaps an active record.']);
        }

        if (str_contains($message, 'unique')) {
            return ValidationException::withMessages(['record' => 'An active scheduling record with these values already exists.']);
        }

        if (str_contains($message, 'stock')) {
            return ValidationException::withMessages(['quantity' => 'Room assignments exceed available equipment stock.']);
        }

        if (str_contains($message, 'location mismatch')) {
            return ValidationException::withMessages(['location_id' => 'The location must match the room and equipment.']);
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function attributesFor(string $type, array $attributes): array
    {
        $class = $this->modelClass($type);

        return Arr::only($attributes, (new $class)->getFillable());
    }
}
