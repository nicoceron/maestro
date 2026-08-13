<?php

namespace Tests\Unit\Scheduling;

use App\Support\Scheduling\SchedulePreviewEnvelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchedulePreviewEnvelopeTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function envelopeMutations(): iterable
    {
        yield 'studio' => [['studioId' => '01HSTUDIO000000000000000002']];
        yield 'actor' => [['actorId' => 43]];
        yield 'type' => [['commandType' => 'cancel']];
        yield 'scope' => [['scope' => 'future']];
        yield 'versions' => [['versions' => ['series' => 8, 'occurrences' => ['01HOCCURRENCE000000000001' => 2]]]];
        yield 'command' => [['command' => ['starts_at_local' => '2027-01-01T11:00:00']]];
    }

    #[DataProvider('envelopeMutations')]
    public function test_every_server_envelope_dimension_changes_the_hash(array $mutation): void
    {
        $base = [
            'studioId' => '01HSTUDIO000000000000000001',
            'actorId' => 42,
            'commandType' => 'reschedule',
            'scope' => 'one',
            'versions' => ['series' => 7, 'occurrences' => ['01HOCCURRENCE000000000001' => 2]],
            'command' => ['starts_at_local' => '2027-01-01T10:00:00'],
        ];

        self::assertNotSame(
            SchedulePreviewEnvelope::hash(...array_values($base)),
            SchedulePreviewEnvelope::hash(...array_values([...$base, ...$mutation])),
        );
    }

    public function test_soft_warning_fingerprint_is_order_independent_but_resource_sensitive(): void
    {
        $first = ['severity' => 'soft', 'code' => 'outside_teacher_availability', 'resource_id' => 'teacher-a'];
        $second = ['severity' => 'soft', 'code' => 'travel_warning', 'resource_id' => 'teacher-b'];

        self::assertSame(
            SchedulePreviewEnvelope::softWarningFingerprint([$first, $second]),
            SchedulePreviewEnvelope::softWarningFingerprint([$second, $first]),
        );
        self::assertNotSame(
            SchedulePreviewEnvelope::softWarningFingerprint([$first]),
            SchedulePreviewEnvelope::softWarningFingerprint([[...$first, 'resource_id' => 'teacher-b']]),
        );
    }
}
