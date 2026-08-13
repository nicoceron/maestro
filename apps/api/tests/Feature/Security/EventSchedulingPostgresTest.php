<?php

namespace Tests\Feature\Security;

use App\Models\Equipment;
use App\Models\EventOccurrence;
use App\Models\EventOccurrenceEquipment;
use App\Models\EventOccurrenceOverride;
use App\Models\EventOccurrenceTeacher;
use App\Models\EventSeries;
use App\Models\Location;
use App\Models\Person;
use App\Models\ScheduleChangeEvent;
use App\Models\SchedulingCommandClaim;
use App\Models\SchedulingOutboxMessage;
use App\Models\StaffProfile;
use App\Models\Studio;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

final class EventSchedulingPostgresTest extends TestCase
{
    public function test_system_hold_expiry_runs_under_explicit_tenant_context_with_no_human_actor(): void
    {
        $this->requirePostgres();
        $studio = Studio::factory()->create();
        $expired = $this->series($studio);
        $future = $this->series($studio);
        $expired->update(['status' => 'draft', 'hold_expires_at' => now()->subMinute()]);
        $future->update(['status' => 'draft', 'hold_expires_at' => now()->addHour()]);

        Carbon::setTestNow(now());

        try {
            $this->artisan('scheduling:expire-holds')->assertSuccessful();
            $this->artisan('scheduling:expire-holds')->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        self::assertSame('canceled', $expired->refresh()->status->value);
        self::assertSame('draft', $future->refresh()->status->value);
        $event = ScheduleChangeEvent::query()->where('event_type', 'schedule.hold_expired')->firstOrFail();
        self::assertNull($event->actor_id);
        self::assertSame(1, ScheduleChangeEvent::query()->where('event_type', 'schedule.hold_expired')->count());
        self::assertSame(1, SchedulingOutboxMessage::query()->where('topic', 'schedule.hold_expired')->count());
    }

    public function test_scheduling_tables_are_forced_rls_default_deny_and_cross_tenant_safe(): void
    {
        $this->requirePostgres();
        [$runtime, $name] = $this->runtimeConnection();
        $first = Studio::factory()->create();
        $second = Studio::factory()->create();
        $series = $this->series($first);
        $other = $this->series($second);

        try {
            foreach ($this->tenantTables() as $table) {
                self::assertSame(0, $runtime->table($table)->count(), "{$table} must default deny.");
            }

            $runtime->statement("select set_config('app.current_studio_id', ?, false)", [$first->getKey()]);
            self::assertSame([$series->getKey()], $runtime->table('event_series')->pluck('id')->all());

            try {
                $runtime->table('event_occurrences')->insert([
                    'id' => (string) Str::ulid(), 'studio_id' => $first->getKey(),
                    'event_series_id' => $other->getKey(), 'public_uid' => (string) Str::uuid(),
                    'recurrence_id_local' => '2027-01-01T10:00:00', 'starts_at' => '2027-01-01 15:00:00+00',
                    'ends_at' => '2027-01-01 16:00:00+00', 'utc_offset_minutes' => 0, 'timezone' => 'UTC',
                    'status' => 'scheduled', 'source' => 'one_off', 'title' => 'Rejected', 'kind' => 'general',
                    'capacity' => 1, 'price_minor' => 0, 'currency' => 'USD', 'version' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                self::fail('Cross-tenant series lineage should be rejected.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        } finally {
            DB::purge($name);
        }
    }

    public function test_postgres_partial_gist_rejects_overlapping_active_teacher_assignments_but_allows_adjacency(): void
    {
        $this->requirePostgres();
        $studio = Studio::factory()->create();
        $person = Person::factory()->create(['studio_id' => $studio->getKey()]);
        $staff = StaffProfile::query()->create([
            'studio_id' => $studio->getKey(), 'person_id' => $person->getKey(),
            'roles' => ['teacher'], 'status' => 'active',
        ]);
        $series = $this->series($studio);
        $first = $this->occurrence($series, '2027-01-01T10:00:00', '2027-01-01 10:00:00+00', '2027-01-01 11:00:00+00');
        $overlap = $this->occurrence($series, '2027-01-01T10:30:00', '2027-01-01 10:30:00+00', '2027-01-01 11:30:00+00');
        $adjacent = $this->occurrence($series, '2027-01-01T11:00:00', '2027-01-01 11:00:00+00', '2027-01-01 12:00:00+00');
        $this->teacher($studio, $first, $staff);

        try {
            $this->teacher($studio, $overlap, $staff);
            self::fail('PostgreSQL should reject the overlapping teacher reservation.');
        } catch (QueryException $exception) {
            self::assertSame('23P01', $exception->errorInfo[0]);
        }

        $this->teacher($studio, $adjacent, $staff);
        self::assertSame(2, EventOccurrenceTeacher::query()->where('staff_profile_id', $staff->getKey())->count());
    }

    public function test_postgres_rejects_cross_series_override_lineage_and_immutable_audit_mutation(): void
    {
        $this->requirePostgres();
        $studio = Studio::factory()->create();
        $firstSeries = $this->series($studio);
        $secondSeries = $this->series($studio);
        $secondOccurrence = $this->occurrence(
            $secondSeries, '2027-03-01T10:00:00', '2027-03-01 10:00:00+00', '2027-03-01 11:00:00+00',
        );

        try {
            EventOccurrenceOverride::query()->create([
                'studio_id' => $studio->getKey(), 'event_series_id' => $firstSeries->getKey(),
                'event_occurrence_id' => $secondOccurrence->getKey(), 'recurrence_id_local' => '2027-03-01T10:00:00',
                'type' => 'modified', 'patch' => ['title' => 'Rejected lineage'],
            ]);
            self::fail('PostgreSQL should reject an override bound to an occurrence from another series.');
        } catch (QueryException $exception) {
            self::assertSame('23503', $exception->errorInfo[0]);
        }

        $event = ScheduleChangeEvent::query()->create([
            'studio_id' => $studio->getKey(), 'event_series_id' => $firstSeries->getKey(),
            'event_type' => 'schedule.test', 'payload' => ['reason' => 'immutability proof'], 'occurred_at' => now(),
        ]);

        try {
            $event->update(['payload' => ['reason' => 'tampered']]);
            self::fail('PostgreSQL should reject schedule audit mutation.');
        } catch (QueryException $exception) {
            self::assertSame('55000', $exception->errorInfo[0]);
        }
    }

    public function test_postgres_equipment_stock_guard_rejects_the_last_unit_overbooking(): void
    {
        $this->requirePostgres();
        $studio = Studio::factory()->create();
        $location = Location::query()->create(['studio_id' => $studio->getKey(), 'name' => 'Main', 'kind' => 'physical', 'timezone' => 'UTC']);
        $equipment = Equipment::query()->create([
            'studio_id' => $studio->getKey(), 'location_id' => $location->getKey(), 'name' => 'Keyboard', 'quantity' => 2,
        ]);
        $series = $this->series($studio);
        $first = $this->occurrence($series, '2027-01-01T10:00:00', '2027-01-01 10:00:00+00', '2027-01-01 11:00:00+00');
        $second = $this->occurrence($series, '2027-01-01T10:30:00', '2027-01-01 10:30:00+00', '2027-01-01 11:30:00+00');
        $first->update(['location_id' => $location->getKey()]);
        $second->update(['location_id' => $location->getKey()]);
        $this->equipment($studio, $first, $location, $equipment, 1);

        try {
            $this->equipment($studio, $second, $location, $equipment, 2);
            self::fail('PostgreSQL should reject aggregate overlapping equipment above stock.');
        } catch (QueryException $exception) {
            self::assertSame('23P01', $exception->errorInfo[0]);
        }
    }

    public function test_postgres_equipment_stock_guard_serializes_concurrent_last_unit_claims(): void
    {
        $this->requirePostgres();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the PostgreSQL concurrency proof.');
        }

        $studio = Studio::factory()->create();
        $location = Location::query()->create(['studio_id' => $studio->getKey(), 'name' => 'Main', 'kind' => 'physical', 'timezone' => 'UTC']);
        $equipment = Equipment::query()->create([
            'studio_id' => $studio->getKey(), 'location_id' => $location->getKey(), 'name' => 'Keyboard', 'quantity' => 2,
        ]);
        $series = $this->series($studio);
        $first = $this->occurrence($series, '2027-02-01T10:00:00', '2027-02-01 10:00:00+00', '2027-02-01 11:00:00+00');
        $second = $this->occurrence($series, '2027-02-01T10:30:00', '2027-02-01 10:30:00+00', '2027-02-01 11:30:00+00');
        $first->update(['location_id' => $location->getKey()]);
        $second->update(['location_id' => $location->getKey()]);
        [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        DB::beginTransaction();
        $this->equipment($studio, $first, $location, $equipment, 1);
        $pid = pcntl_fork();

        if ($pid === 0) {
            fclose($parentSocket);
            fwrite($childSocket, "ready\n");
            $connection = config('database.connections.pgsql');
            $pdo = new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'], $connection['database']),
                $connection['username'],
                $connection['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );

            try {
                $statement = $pdo->prepare(<<<'SQL'
                    INSERT INTO event_occurrence_equipment
                        (id, studio_id, event_occurrence_id, location_id, equipment_id, quantity, status, busy_starts_at, busy_ends_at, version, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, 2, 'assigned', ?, ?, 1, now(), now())
                    SQL);
                $statement->execute([
                    (string) Str::ulid(), $studio->getKey(), $second->getKey(), $location->getKey(), $equipment->getKey(),
                    $second->starts_at->toAtomString(), $second->ends_at->toAtomString(),
                ]);
                fwrite($childSocket, 'unexpected-success');
            } catch (\PDOException $exception) {
                fwrite($childSocket, (string) ($exception->errorInfo[0] ?? $exception->getCode()));
            }

            fclose($childSocket);
            exit(0);
        }

        fclose($childSocket);
        self::assertSame("ready\n", fgets($parentSocket));
        usleep(200_000);
        self::assertSame(0, pcntl_waitpid($pid, $status, WNOHANG), 'The concurrent claim must wait on the equipment row lock.');
        DB::commit();
        pcntl_waitpid($pid, $status);
        self::assertSame('23P01', stream_get_contents($parentSocket));
        self::assertSame(0, pcntl_wexitstatus($status));
        fclose($parentSocket);
    }

    public function test_postgres_command_claim_serializes_concurrent_idempotency_and_returns_one_completed_result(): void
    {
        $this->requirePostgres();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the PostgreSQL concurrency proof.');
        }

        $studio = Studio::factory()->create();
        $actor = User::factory()->create();
        $series = $this->series($studio);
        $key = 'concurrent-claim';
        $hash = hash('sha256', 'same-operation');
        $claim = SchedulingCommandClaim::query()->create([
            'studio_id' => $studio->getKey(), 'actor_id' => $actor->getKey(),
            'idempotency_key' => $key, 'operation_hash' => $hash, 'status' => 'pending',
        ]);
        [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        DB::beginTransaction();
        $locked = SchedulingCommandClaim::query()->lockForUpdate()->findOrFail($claim->getKey());
        $pid = pcntl_fork();

        if ($pid === 0) {
            fclose($parentSocket);
            fwrite($childSocket, "ready\n");
            $connection = config('database.connections.pgsql');
            $pdo = new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'], $connection['database']),
                $connection['username'],
                $connection['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $pdo->beginTransaction();
            $statement = $pdo->prepare('SELECT status, result_id FROM scheduling_command_claims WHERE id = ? FOR UPDATE');
            $statement->execute([$claim->getKey()]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $pdo->commit();
            fwrite($childSocket, json_encode($row, JSON_THROW_ON_ERROR));
            fclose($childSocket);
            exit(0);
        }

        fclose($childSocket);
        self::assertSame("ready\n", fgets($parentSocket));
        usleep(200_000);
        self::assertSame(0, pcntl_waitpid($pid, $status, WNOHANG), 'The replay must wait on the idempotency claim row.');
        $locked->forceFill([
            'status' => 'completed', 'result_type' => EventSeries::class, 'result_id' => $series->getKey(),
            'result_projection' => $series->getAttributes(), 'completed_at' => now(),
        ])->save();
        DB::commit();
        pcntl_waitpid($pid, $status);
        $childResult = json_decode(stream_get_contents($parentSocket), true, flags: JSON_THROW_ON_ERROR);
        fclose($parentSocket);

        self::assertSame('completed', $childResult['status']);
        self::assertSame($series->getKey(), $childResult['result_id']);
        self::assertSame(0, pcntl_wexitstatus($status));
        self::assertSame(1, SchedulingCommandClaim::query()->where('idempotency_key', $key)->count());
    }

    public function test_postgres_command_claim_is_forced_rls_and_only_allows_pending_to_completed_transition(): void
    {
        $this->requirePostgres();
        [$runtime, $name] = $this->runtimeConnection();
        $studio = Studio::factory()->create();
        $other = Studio::factory()->create();
        $actor = User::factory()->create();
        $claim = SchedulingCommandClaim::query()->create([
            'studio_id' => $studio->getKey(), 'actor_id' => $actor->getKey(),
            'idempotency_key' => 'rls-claim', 'operation_hash' => hash('sha256', 'rls'), 'status' => 'pending',
        ]);

        try {
            self::assertSame(0, $runtime->table('scheduling_command_claims')->count());
            $runtime->statement("select set_config('app.current_studio_id', ?, false)", [$studio->getKey()]);
            self::assertSame([$claim->getKey()], $runtime->table('scheduling_command_claims')->pluck('id')->all());
            $runtime->statement("select set_config('app.current_studio_id', ?, false)", [$other->getKey()]);
            self::assertSame(0, $runtime->table('scheduling_command_claims')->count());
        } finally {
            DB::purge($name);
        }

        try {
            $claim->update(['operation_hash' => hash('sha256', 'tampered')]);
            self::fail('The PostgreSQL claim transition guard should reject binding mutation.');
        } catch (QueryException $exception) {
            self::assertSame('55000', $exception->errorInfo[0]);
        }
    }

    private function series(Studio $studio): EventSeries
    {
        return EventSeries::query()->create([
            'studio_id' => $studio->getKey(), 'kind' => 'general', 'status' => 'active', 'visibility' => 'private',
            'title' => 'Security fixture', 'timezone' => 'UTC', 'dtstart_local' => '2027-01-01T10:00:00',
            'dtstart_resolution' => 'reject', 'duration_minutes' => 60, 'capacity' => 1,
        ]);
    }

    private function occurrence(EventSeries $series, string $identity, string $starts, string $ends): EventOccurrence
    {
        return EventOccurrence::query()->create([
            'studio_id' => $series->studio_id, 'event_series_id' => $series->getKey(), 'public_uid' => (string) Str::uuid(),
            'recurrence_id_local' => $identity, 'starts_at' => $starts, 'ends_at' => $ends,
            'utc_offset_minutes' => 0, 'timezone' => 'UTC', 'source' => 'generated', 'status' => 'scheduled',
            'title' => 'Security fixture', 'kind' => 'general', 'capacity' => 1, 'price_minor' => 0, 'currency' => 'USD',
        ]);
    }

    private function teacher(Studio $studio, EventOccurrence $occurrence, StaffProfile $staff): EventOccurrenceTeacher
    {
        return EventOccurrenceTeacher::query()->create([
            'studio_id' => $studio->getKey(), 'event_occurrence_id' => $occurrence->getKey(),
            'staff_profile_id' => $staff->getKey(), 'role' => 'lead', 'status' => 'assigned',
            'busy_starts_at' => $occurrence->starts_at, 'busy_ends_at' => $occurrence->ends_at,
        ]);
    }

    private function equipment(
        Studio $studio,
        EventOccurrence $occurrence,
        Location $location,
        Equipment $equipment,
        int $quantity,
    ): EventOccurrenceEquipment {
        return EventOccurrenceEquipment::query()->create([
            'studio_id' => $studio->getKey(), 'event_occurrence_id' => $occurrence->getKey(),
            'location_id' => $location->getKey(), 'equipment_id' => $equipment->getKey(),
            'quantity' => $quantity, 'status' => 'assigned',
            'busy_starts_at' => $occurrence->starts_at, 'busy_ends_at' => $occurrence->ends_at,
        ]);
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required.');
        }
    }

    private function runtimeConnection(): array
    {
        $username = (string) env('DB_RUNTIME_USERNAME');
        $password = (string) env('DB_RUNTIME_PASSWORD');

        if ($username === '' || $password === '') {
            $this->markTestSkipped('A restricted PostgreSQL role is required.');
        }

        $name = 'event_runtime_'.Str::lower((string) Str::ulid());
        config(["database.connections.{$name}" => array_replace(
            config('database.connections.pgsql'), ['username' => $username, 'password' => $password],
        )]);

        return [DB::connection($name), $name];
    }

    private function tenantTables(): array
    {
        return [
            'event_series', 'event_series_teachers', 'event_series_rooms', 'event_series_equipment',
            'event_occurrences', 'event_occurrence_overrides', 'event_series_splits', 'event_enrollments',
            'event_occurrence_participants', 'event_occurrence_teachers', 'event_occurrence_rooms',
            'event_occurrence_equipment', 'schedule_change_previews', 'schedule_change_events', 'scheduling_outbox_messages',
            'scheduling_command_claims',
        ];
    }
}
