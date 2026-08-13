<?php

namespace Tests\Unit\Scheduling;

use App\Contracts\Scheduling\RecurrenceEngine;
use App\Enums\LocalTimeResolution;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class RecurrenceEngineTest extends TestCase
{
    public function test_it_canonicalizes_the_safe_rfc_subset_and_rejects_pathological_rules(): void
    {
        $engine = app(RecurrenceEngine::class);

        self::assertSame('FREQ=WEEKLY;INTERVAL=2;COUNT=3;BYDAY=MO,WE', $engine->canonicalize(
            'RRULE:BYDAY=MO,WE;COUNT=3;INTERVAL=2;FREQ=WEEKLY',
        ));

        foreach (['FREQ=HOURLY', 'FREQ=DAILY;COUNT=5001', 'FREQ=DAILY;COUNT=2;UNTIL=20261231T235959Z', 'FREQ=DAILY;UNTIL=2026-12-31'] as $rule) {
            try {
                $engine->canonicalize($rule);
                self::fail("Expected {$rule} to fail.");
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('rrule', $exception->errors());
            }
        }
    }

    public function test_generated_spring_gap_preserves_requested_identity_and_normalizes_forward(): void
    {
        $seeds = app(RecurrenceEngine::class)->expand(
            '2026-03-01T02:30:00', 'America/New_York', 'FREQ=WEEKLY;COUNT=3',
            CarbonImmutable::parse('2026-02-28T00:00:00Z'), CarbonImmutable::parse('2026-03-20T00:00:00Z'),
        );

        self::assertSame('2026-03-08T02:30:00', $seeds[1]->recurrenceIdLocal);
        self::assertSame('2026-03-08T07:30:00+00:00', $seeds[1]->startsAt->toAtomString());
    }

    public function test_generated_fold_honors_the_frozen_series_resolution(): void
    {
        $engine = app(RecurrenceEngine::class);
        $args = [
            '2026-10-25T01:30:00', 'America/New_York', 'FREQ=WEEKLY;COUNT=2',
            CarbonImmutable::parse('2026-10-24T00:00:00Z'), CarbonImmutable::parse('2026-11-03T00:00:00Z'), 2000,
        ];
        $earlier = $engine->expand(...$args, startResolution: LocalTimeResolution::Earlier);
        $later = $engine->expand(...$args, startResolution: LocalTimeResolution::Later);

        self::assertSame('2026-11-01T05:30:00+00:00', $earlier[1]->startsAt->toAtomString());
        self::assertSame('2026-11-01T06:30:00+00:00', $later[1]->startsAt->toAtomString());
        self::assertSame($earlier[1]->recurrenceIdLocal, $later[1]->recurrenceIdLocal);
    }

    public function test_explicit_gap_and_unresolved_fold_are_rejected(): void
    {
        foreach ([
            ['2026-03-08T02:30:00', LocalTimeResolution::Reject],
            ['2026-03-08T02:30:00', LocalTimeResolution::NormalizeForward],
            ['2026-11-01T01:30:00', LocalTimeResolution::Reject],
        ] as [$start, $resolution]) {
            try {
                app(RecurrenceEngine::class)->expand(
                    $start, 'America/New_York', null,
                    CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'),
                    startResolution: $resolution,
                );
                self::fail('Expected explicit invalid local time to fail.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('dtstart_local', $exception->errors());
            }
        }
    }

    public function test_rdate_and_exdate_modify_the_generated_set_by_local_identity(): void
    {
        $seeds = app(RecurrenceEngine::class)->expand(
            '2026-01-05T10:00:00', 'America/Bogota', 'FREQ=WEEKLY;COUNT=3',
            CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-02-01'),
            rdates: [['local' => '2026-01-08T10:00:00', 'resolution' => 'reject']],
            exdates: ['2026-01-12T10:00:00'],
        );

        self::assertSame([
            '2026-01-05T10:00:00', '2026-01-08T10:00:00', '2026-01-19T10:00:00',
        ], array_column($seeds, 'recurrenceIdLocal'));
    }
}
