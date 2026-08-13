<?php

namespace App\Support\Scheduling;

use App\Models\ProgramOffering;
use App\Models\ProgramOfferingOverride;
use App\Models\ServicePolicy;
use App\Models\ServicePrice;
use Carbon\CarbonImmutable;

final class ResolveOfferingConfiguration
{
    /** @return array<string, mixed> */
    public function resolve(
        ProgramOffering $offering,
        CarbonImmutable $effectiveOn,
        ?string $staffProfileId = null,
        ?string $locationId = null,
    ): array {
        $date = $effectiveOn->toDateString();
        $overrides = ProgramOfferingOverride::query()
            ->where('studio_id', $offering->studio_id)
            ->where('program_offering_id', $offering->getKey())
            ->where('active', true)
            ->where('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', $date))
            ->where(function ($query) use ($staffProfileId, $locationId): void {
                if ($staffProfileId !== null && $locationId !== null) {
                    $query->orWhere(fn ($query) => $query
                        ->where('staff_profile_id', $staffProfileId)
                        ->where('location_id', $locationId));
                }

                if ($staffProfileId !== null) {
                    $query->orWhere(fn ($query) => $query
                        ->where('staff_profile_id', $staffProfileId)
                        ->whereNull('location_id'));
                }

                if ($locationId !== null) {
                    $query->orWhere(fn ($query) => $query
                        ->whereNull('staff_profile_id')
                        ->where('location_id', $locationId));
                }
            })
            ->orderByRaw('CASE WHEN staff_profile_id IS NULL THEN 1 WHEN location_id IS NULL THEN 2 ELSE 3 END ASC')
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();
        $price = ServicePrice::query()
            ->where('studio_id', $offering->studio_id)
            ->where('service_id', $offering->service_id)
            ->where('active', true)
            ->where('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', $date))
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
        $policy = ServicePolicy::query()
            ->where('studio_id', $offering->studio_id)
            ->where('service_id', $offering->service_id)
            ->where('active', true)
            ->where('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', $date))
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
        $values = [
            'duration_minutes' => $offering->duration_minutes ?? $offering->service->default_duration_minutes,
            'capacity' => $offering->capacity ?? $offering->service->default_capacity,
            'price_minor' => $offering->price_minor ?? $price?->amount_minor ?? $offering->service->default_price_minor,
            'currency' => $offering->currency ?? $price?->currency ?? $offering->service->currency,
            'booking_lead_minutes' => $policy?->booking_lead_minutes ?? $offering->service->booking_lead_minutes,
            'cancellation_notice_minutes' => $policy?->cancellation_notice_minutes ?? $offering->service->cancellation_notice_minutes,
            'makeup_policy' => ($policy?->makeup_policy ?? $offering->service->makeup_policy)->value,
        ];
        $sources = [
            'duration_minutes' => $offering->duration_minutes === null ? 'service_default' : 'program_offering',
            'capacity' => $offering->capacity === null ? 'service_default' : 'program_offering',
            'price_minor' => $offering->price_minor !== null
                ? 'program_offering'
                : ($price === null ? 'service_default' : 'service_effective_price'),
            'currency' => $offering->currency !== null
                ? 'program_offering'
                : ($price === null ? 'service_default' : 'service_effective_price'),
            'booking_lead_minutes' => $policy === null ? 'service_default' : 'service_effective_policy',
            'cancellation_notice_minutes' => $policy === null ? 'service_default' : 'service_effective_policy',
            'makeup_policy' => $policy === null ? 'service_default' : 'service_effective_policy',
        ];

        foreach ($overrides as $override) {
            $source = match (true) {
                $override->staff_profile_id !== null && $override->location_id !== null => 'teacher_location_override',
                $override->staff_profile_id !== null => 'teacher_override',
                default => 'location_override',
            };

            foreach (array_keys($values) as $field) {
                $value = $override->getAttribute($field);

                if ($value !== null) {
                    $values[$field] = $value instanceof \BackedEnum ? $value->value : $value;
                    $sources[$field] = $source;
                }
            }
        }

        return [
            'effective_on' => $date,
            ...$values,
            'sources' => $sources,
        ];
    }
}
