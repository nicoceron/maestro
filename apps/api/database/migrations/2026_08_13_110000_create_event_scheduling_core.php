<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const TENANT_TABLES = [
        'event_series',
        'event_series_teachers',
        'event_series_rooms',
        'event_series_equipment',
        'event_occurrences',
        'event_occurrence_overrides',
        'event_series_splits',
        'event_enrollments',
        'event_occurrence_participants',
        'event_occurrence_teachers',
        'event_occurrence_rooms',
        'event_occurrence_equipment',
        'scheduling_command_claims',
        'schedule_change_previews',
        'schedule_change_events',
        'scheduling_outbox_messages',
    ];

    public function up(): void
    {
        Schema::create('event_series', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->nullable();
            $table->foreignUlid('program_offering_id')->nullable();
            $table->foreignUlid('location_id')->nullable();
            $table->foreignUlid('pricing_staff_profile_id')->nullable();
            $table->foreignUlid('parent_series_id')->nullable();
            $table->foreignUlid('cloned_from_series_id')->nullable();
            $table->string('split_from_recurrence_id_local', 19)->nullable();
            $table->string('kind', 32);
            $table->string('status', 24)->default('draft');
            $table->string('visibility', 24)->default('private');
            $table->string('title', 160);
            $table->text('shared_description')->nullable();
            $table->text('internal_description')->nullable();
            $table->string('timezone', 64);
            $table->string('dtstart_local', 19);
            $table->string('dtstart_resolution', 24)->default('reject');
            $table->unsignedSmallInteger('duration_minutes');
            $table->text('rrule')->nullable();
            $table->json('rdates')->nullable();
            $table->json('exdates')->nullable();
            $table->string('recurrence_ends_before_local', 19)->nullable();
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->timestampTz('hold_expires_at')->nullable();
            $table->timestampTz('materialized_through')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign(['studio_id', 'service_id'])
                ->references(['studio_id', 'id'])->on('services')->restrictOnDelete();
            $table->foreign(['studio_id', 'program_offering_id', 'service_id'], 'event_series_offering_service_fk')
                ->references(['studio_id', 'id', 'service_id'])->on('program_offerings')->restrictOnDelete();
            $table->foreign(['studio_id', 'location_id'])
                ->references(['studio_id', 'id'])->on('locations')->restrictOnDelete();
            $table->foreign(['studio_id', 'pricing_staff_profile_id'])
                ->references(['studio_id', 'id'])->on('staff_profiles')->restrictOnDelete();
            $table->unique(['studio_id', 'id']);
            $table->unique(['studio_id', 'id', 'location_id'], 'event_series_studio_id_location_unique');
            $table->index(['studio_id', 'status', 'materialized_through']);
            $table->index(['studio_id', 'program_offering_id']);
        });

        Schema::table('event_series', function (Blueprint $table): void {
            $table->foreign(['studio_id', 'parent_series_id'])
                ->references(['studio_id', 'id'])->on('event_series')->restrictOnDelete();
            $table->foreign(['studio_id', 'cloned_from_series_id'])
                ->references(['studio_id', 'id'])->on('event_series')->restrictOnDelete();
        });

        Schema::create('event_series_teachers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('event_series_id');
            $table->foreignUlid('staff_profile_id');
            $table->string('role', 24)->default('lead');
            $table->timestamps();
            $table->foreign(['studio_id', 'event_series_id'])->references(['studio_id', 'id'])->on('event_series')->cascadeOnDelete();
            $table->foreign(['studio_id', 'staff_profile_id'])->references(['studio_id', 'id'])->on('staff_profiles')->restrictOnDelete();
            $table->unique(['studio_id', 'event_series_id', 'staff_profile_id']);
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('event_series_rooms', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('event_series_id');
            $table->foreignUlid('location_id');
            $table->foreignUlid('room_id');
            $table->timestamps();
            $table->foreign(['studio_id', 'event_series_id', 'location_id'], 'event_series_rooms_series_location_fk')
                ->references(['studio_id', 'id', 'location_id'])->on('event_series')->cascadeOnDelete();
            $table->foreign(['studio_id', 'room_id', 'location_id'], 'event_series_rooms_room_location_fk')
                ->references(['studio_id', 'id', 'location_id'])->on('rooms')->restrictOnDelete();
            $table->unique(['studio_id', 'event_series_id', 'room_id']);
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('event_series_equipment', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('event_series_id');
            $table->foreignUlid('location_id');
            $table->foreignUlid('equipment_id');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps();
            $table->foreign(['studio_id', 'event_series_id', 'location_id'], 'event_series_equipment_series_location_fk')
                ->references(['studio_id', 'id', 'location_id'])->on('event_series')->cascadeOnDelete();
            $table->foreign(['studio_id', 'equipment_id', 'location_id'], 'event_series_equipment_resource_location_fk')
                ->references(['studio_id', 'id', 'location_id'])->on('equipment')->restrictOnDelete();
            $table->unique(['studio_id', 'event_series_id', 'equipment_id']);
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('event_occurrences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('event_series_id');
            $table->foreignUlid('location_id')->nullable();
            $table->uuid('public_uid')->unique();
            $table->string('recurrence_id_local', 19);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->smallInteger('utc_offset_minutes');
            $table->string('timezone', 64);
            $table->string('source', 24)->default('generated');
            $table->string('status', 24)->default('scheduled');
            $table->string('title', 160);
            $table->string('kind', 32);
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->char('currency', 3);
            $table->json('policy_snapshot')->nullable();
            $table->json('source_snapshot')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('makeup_required')->default(false);
            $table->string('makeup_reference', 120)->nullable();
            $table->timestampTz('hold_expires_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->foreignId('canceled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();
            $table->foreign(['studio_id', 'event_series_id'])->references(['studio_id', 'id'])->on('event_series')->cascadeOnDelete();
            $table->foreign(['studio_id', 'location_id'])->references(['studio_id', 'id'])->on('locations')->restrictOnDelete();
            $table->unique(['studio_id', 'event_series_id', 'recurrence_id_local'], 'event_occurrences_recurrence_unique');
            $table->unique(['studio_id', 'id']);
            $table->unique(['studio_id', 'event_series_id', 'id'], 'event_occurrences_series_id_unique');
            $table->unique(['studio_id', 'id', 'location_id'], 'event_occurrences_studio_id_location_unique');
            $table->index(['studio_id', 'starts_at', 'ends_at']);
        });

        Schema::create('event_occurrence_overrides', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('event_series_id');
            $table->foreignUlid('event_occurrence_id')->nullable();
            $table->string('recurrence_id_local', 19);
            $table->string('type', 24);
            $table->json('patch')->nullable();
            $table->string('reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->foreign(['studio_id', 'event_series_id'])->references(['studio_id', 'id'])->on('event_series')->cascadeOnDelete();
            $table->foreign(['studio_id', 'event_series_id', 'event_occurrence_id'], 'event_override_occurrence_series_fk')
                ->references(['studio_id', 'event_series_id', 'id'])->on('event_occurrences')->restrictOnDelete();
            $table->unique(['studio_id', 'event_series_id', 'recurrence_id_local'], 'event_override_recurrence_unique');
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('event_series_splits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('old_series_id');
            $table->foreignUlid('new_series_id');
            $table->string('cutover_recurrence_id_local', 19);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->foreign(['studio_id', 'old_series_id'])->references(['studio_id', 'id'])->on('event_series')->restrictOnDelete();
            $table->foreign(['studio_id', 'new_series_id'])->references(['studio_id', 'id'])->on('event_series')->restrictOnDelete();
            $table->unique(['studio_id', 'old_series_id', 'cutover_recurrence_id_local'], 'event_series_split_unique');
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('event_enrollments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('event_series_id');
            $table->foreignUlid('person_id');
            $table->string('role', 24)->default('student');
            $table->string('status', 24)->default('confirmed');
            $table->string('begins_recurrence_id_local', 19)->nullable();
            $table->string('ends_recurrence_id_local', 19)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->foreign(['studio_id', 'event_series_id'])->references(['studio_id', 'id'])->on('event_series')->cascadeOnDelete();
            $table->foreign(['studio_id', 'person_id'])->references(['studio_id', 'id'])->on('people')->restrictOnDelete();
            $table->unique(['studio_id', 'event_series_id', 'person_id']);
            $table->unique(['studio_id', 'id']);
            $table->unique(['studio_id', 'event_series_id', 'person_id', 'id'], 'event_enrollments_series_person_id_unique');
        });

        Schema::create('event_occurrence_participants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('event_occurrence_id');
            $table->foreignUlid('event_series_id');
            $table->foreignUlid('event_enrollment_id')->nullable();
            $table->foreignUlid('person_id');
            $table->string('role', 24)->default('student');
            $table->string('status', 24)->default('confirmed');
            $table->string('previous_status', 24)->nullable();
            $table->boolean('blocks_conflicts')->default(true);
            $table->timestampTz('busy_starts_at');
            $table->timestampTz('busy_ends_at');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->foreign(['studio_id', 'event_series_id', 'event_occurrence_id'], 'event_participant_occurrence_series_fk')
                ->references(['studio_id', 'event_series_id', 'id'])->on('event_occurrences')->cascadeOnDelete();
            $table->foreign(['studio_id', 'event_series_id', 'person_id', 'event_enrollment_id'], 'event_participant_enrollment_subject_fk')
                ->references(['studio_id', 'event_series_id', 'person_id', 'id'])->on('event_enrollments')->restrictOnDelete();
            $table->unique(['studio_id', 'event_occurrence_id', 'person_id']);
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'person_id', 'busy_starts_at', 'busy_ends_at'], 'event_participant_busy_index');
        });

        Schema::create('event_occurrence_teachers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('event_occurrence_id');
            $table->foreignUlid('staff_profile_id');
            $table->string('role', 24)->default('lead');
            $table->string('status', 24)->default('assigned');
            $table->string('previous_status', 24)->nullable();
            $table->timestampTz('busy_starts_at');
            $table->timestampTz('busy_ends_at');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->foreign(['studio_id', 'event_occurrence_id'])->references(['studio_id', 'id'])->on('event_occurrences')->cascadeOnDelete();
            $table->foreign(['studio_id', 'staff_profile_id'])->references(['studio_id', 'id'])->on('staff_profiles')->restrictOnDelete();
            $table->unique(['studio_id', 'event_occurrence_id', 'staff_profile_id']);
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'staff_profile_id', 'busy_starts_at', 'busy_ends_at'], 'event_teacher_busy_index');
        });

        Schema::create('event_occurrence_rooms', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('event_occurrence_id');
            $table->foreignUlid('location_id');
            $table->foreignUlid('room_id');
            $table->string('status', 24)->default('assigned');
            $table->string('previous_status', 24)->nullable();
            $table->timestampTz('busy_starts_at');
            $table->timestampTz('busy_ends_at');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->foreign(['studio_id', 'event_occurrence_id', 'location_id'], 'event_occurrence_rooms_occurrence_location_fk')
                ->references(['studio_id', 'id', 'location_id'])->on('event_occurrences')->cascadeOnDelete();
            $table->foreign(['studio_id', 'room_id', 'location_id'], 'event_occurrence_rooms_room_location_fk')
                ->references(['studio_id', 'id', 'location_id'])->on('rooms')->restrictOnDelete();
            $table->unique(['studio_id', 'event_occurrence_id', 'room_id']);
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'room_id', 'busy_starts_at', 'busy_ends_at'], 'event_room_busy_index');
        });

        Schema::create('event_occurrence_equipment', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('event_occurrence_id');
            $table->foreignUlid('location_id');
            $table->foreignUlid('equipment_id');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('status', 24)->default('assigned');
            $table->string('previous_status', 24)->nullable();
            $table->timestampTz('busy_starts_at');
            $table->timestampTz('busy_ends_at');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->foreign(['studio_id', 'event_occurrence_id', 'location_id'], 'event_occurrence_equipment_occurrence_location_fk')
                ->references(['studio_id', 'id', 'location_id'])->on('event_occurrences')->cascadeOnDelete();
            $table->foreign(['studio_id', 'equipment_id', 'location_id'], 'event_occurrence_equipment_resource_location_fk')
                ->references(['studio_id', 'id', 'location_id'])->on('equipment')->restrictOnDelete();
            $table->unique(['studio_id', 'event_occurrence_id', 'equipment_id']);
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'equipment_id', 'busy_starts_at', 'busy_ends_at'], 'event_equipment_busy_index');
        });

        Schema::create('scheduling_command_claims', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->char('operation_hash', 64);
            $table->string('status', 16)->default('pending');
            $table->string('result_type', 64)->nullable();
            $table->ulid('result_id')->nullable();
            $table->json('result_projection')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['studio_id', 'actor_id', 'idempotency_key'], 'scheduling_command_claim_actor_key_unique');
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'status', 'created_at']);
        });

        Schema::create('schedule_change_previews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('event_series_id')->nullable();
            $table->foreignUlid('event_occurrence_id')->nullable();
            $table->string('command_type', 64);
            $table->string('scope', 16);
            $table->char('command_hash', 64);
            $table->char('soft_warning_fingerprint', 64);
            $table->json('command');
            $table->json('aggregate_versions');
            $table->json('impact');
            $table->json('conflicts');
            $table->string('status', 16);
            $table->boolean('soft_warnings_acknowledged')->default(false);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestamps();
            $table->foreign(['studio_id', 'event_series_id'])->references(['studio_id', 'id'])->on('event_series')->cascadeOnDelete();
            $table->foreign(['studio_id', 'event_occurrence_id'])->references(['studio_id', 'id'])->on('event_occurrences')->cascadeOnDelete();
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'actor_id', 'expires_at']);
        });

        Schema::create('schedule_change_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('event_series_id')->nullable();
            $table->foreignUlid('event_occurrence_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event_type', 64);
            $table->string('idempotency_key', 100)->nullable();
            $table->json('payload');
            $table->timestampTz('occurred_at');
            $table->foreign(['studio_id', 'event_series_id'])->references(['studio_id', 'id'])->on('event_series')->restrictOnDelete();
            $table->foreign(['studio_id', 'event_occurrence_id'])->references(['studio_id', 'id'])->on('event_occurrences')->restrictOnDelete();
            $table->unique(['studio_id', 'id']);
            $table->unique(['studio_id', 'actor_id', 'idempotency_key'], 'schedule_change_event_idempotency_unique');
            $table->index(['studio_id', 'event_series_id', 'occurred_at']);
        });

        Schema::create('scheduling_outbox_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->string('topic', 100);
            $table->string('aggregate_type', 64);
            $table->ulid('aggregate_id');
            $table->unsignedInteger('aggregate_version');
            $table->string('dedupe_key', 160);
            $table->json('payload');
            $table->timestampTz('available_at');
            $table->timestampTz('processed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();
            $table->unique(['studio_id', 'dedupe_key']);
            $table->unique(['studio_id', 'id']);
            $table->index(['processed_at', 'available_at']);
        });

        $this->installConstraints();
        $this->installOverlapGuards();
        $this->installImmutabilityGuards();
        $this->installRowLevelSecurity();
    }

    public function down(): void
    {
        $this->removeRowLevelSecurity();
        $this->removeImmutabilityGuards();
        $this->removeOverlapGuards();

        foreach (array_reverse(self::TENANT_TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function installConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE event_series ADD CONSTRAINT event_series_state_check CHECK (
                version >= 1 AND duration_minutes BETWEEN 5 AND 1440 AND capacity BETWEEN 1 AND 1000
                AND length(trim(title)) > 0
                AND kind IN ('general','private_lesson','group_class','open_class','workshop','camp','recital','closure')
                AND status IN ('draft','active','paused','ended','canceled')
                AND visibility IN ('private','studio','portal','public')
                AND dtstart_resolution IN ('reject','earlier','later')
                AND (kind IN ('general','closure') OR service_id IS NOT NULL)
                AND (hold_expires_at IS NULL OR status = 'draft')
            );
            ALTER TABLE event_occurrences ADD CONSTRAINT event_occurrences_state_check CHECK (
                version >= 1 AND starts_at < ends_at AND capacity BETWEEN 1 AND 1000
                AND price_minor BETWEEN 0 AND 999999999 AND currency ~ '^[A-Z]{3}$'
                AND source IN ('generated','override','one_off')
                AND status IN ('tentative','scheduled','completed','canceled')
                AND (hold_expires_at IS NULL OR status = 'tentative')
            );
            ALTER TABLE event_occurrence_overrides ADD CONSTRAINT event_override_type_check CHECK (type IN ('modified','canceled','restored'));
            ALTER TABLE schedule_change_previews ADD CONSTRAINT schedule_preview_state_check CHECK (
                scope IN ('one','future','series') AND status IN ('ready','blocked') AND expires_at > created_at
            );
            ALTER TABLE scheduling_command_claims ADD CONSTRAINT scheduling_command_claim_state_check CHECK (
                status IN ('pending','completed')
                AND ((status = 'pending' AND result_type IS NULL AND result_id IS NULL AND result_projection IS NULL AND completed_at IS NULL)
                    OR (status = 'completed' AND result_type IS NOT NULL AND result_id IS NOT NULL AND result_projection IS NOT NULL AND completed_at IS NOT NULL))
            );
            SQL);
    }

    private function installOverlapGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE EXTENSION IF NOT EXISTS btree_gist;
                ALTER TABLE event_occurrence_teachers
                    ADD COLUMN busy_during tstzrange GENERATED ALWAYS AS (tstzrange(busy_starts_at, busy_ends_at, '[)')) STORED;
                ALTER TABLE event_occurrence_rooms
                    ADD COLUMN busy_during tstzrange GENERATED ALWAYS AS (tstzrange(busy_starts_at, busy_ends_at, '[)')) STORED;
                ALTER TABLE event_occurrence_participants
                    ADD COLUMN busy_during tstzrange GENERATED ALWAYS AS (tstzrange(busy_starts_at, busy_ends_at, '[)')) STORED;
                ALTER TABLE event_occurrence_teachers ADD CONSTRAINT event_teacher_no_overlap
                    EXCLUDE USING gist (studio_id WITH =, staff_profile_id WITH =, busy_during WITH &&)
                    WHERE (status = 'assigned');
                ALTER TABLE event_occurrence_rooms ADD CONSTRAINT event_room_no_overlap
                    EXCLUDE USING gist (studio_id WITH =, room_id WITH =, busy_during WITH &&)
                    WHERE (status = 'assigned');
                ALTER TABLE event_occurrence_participants ADD CONSTRAINT event_participant_no_overlap
                    EXCLUDE USING gist (studio_id WITH =, person_id WITH =, busy_during WITH &&)
                    WHERE (blocks_conflicts AND status IN ('reserved','confirmed'));

                CREATE OR REPLACE FUNCTION public.app_enforce_event_equipment_stock()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public
                AS $function$
                DECLARE
                    available integer;
                    reserved integer;
                BEGIN
                    IF NEW.status <> 'assigned' THEN
                        RETURN NEW;
                    END IF;

                    SELECT quantity INTO available
                    FROM public.equipment
                    WHERE studio_id = NEW.studio_id AND id = NEW.equipment_id
                    FOR UPDATE;

                    SELECT coalesce(sum(quantity), 0) INTO reserved
                    FROM public.event_occurrence_equipment existing
                    WHERE existing.studio_id = NEW.studio_id
                        AND existing.equipment_id = NEW.equipment_id
                        AND existing.status = 'assigned'
                        AND existing.id <> NEW.id
                        AND NEW.busy_starts_at < existing.busy_ends_at
                        AND NEW.busy_ends_at > existing.busy_starts_at;

                    IF available IS NULL OR reserved + NEW.quantity > available THEN
                        RAISE EXCEPTION 'event equipment reservations exceed stock' USING ERRCODE = '23P01';
                    END IF;

                    RETURN NEW;
                END;
                $function$;

                CREATE TRIGGER event_equipment_stock_guard
                BEFORE INSERT OR UPDATE ON event_occurrence_equipment
                FOR EACH ROW EXECUTE FUNCTION public.app_enforce_event_equipment_stock();
                SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            foreach ([
                ['event_occurrence_teachers', 'staff_profile_id', "NEW.status = 'assigned'", "existing.status = 'assigned'"],
                ['event_occurrence_rooms', 'room_id', "NEW.status = 'assigned'", "existing.status = 'assigned'"],
                ['event_occurrence_participants', 'person_id', "NEW.blocks_conflicts = 1 AND NEW.status IN ('reserved','confirmed')", "existing.blocks_conflicts = 1 AND existing.status IN ('reserved','confirmed')"],
            ] as [$table, $resource, $newActive, $existingActive]) {
                foreach (['insert' => '', 'update' => 'AND existing.id <> NEW.id'] as $operation => $excludeSelf) {
                    DB::unprepared(<<<SQL
                        CREATE TRIGGER {$table}_no_overlap_{$operation}
                        BEFORE {$operation} ON {$table}
                        WHEN {$newActive} AND EXISTS (
                            SELECT 1 FROM {$table} existing
                            WHERE existing.studio_id = NEW.studio_id
                                AND existing.{$resource} = NEW.{$resource}
                                AND {$existingActive} {$excludeSelf}
                                AND datetime(NEW.busy_starts_at) < datetime(existing.busy_ends_at)
                                AND datetime(NEW.busy_ends_at) > datetime(existing.busy_starts_at)
                        )
                        BEGIN SELECT RAISE(ABORT, 'scheduling resource overlap'); END;
                        SQL);
                }
            }

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER event_equipment_stock_guard_insert
                BEFORE INSERT ON event_occurrence_equipment
                WHEN NEW.status = 'assigned' AND (
                    SELECT coalesce(sum(existing.quantity), 0)
                    FROM event_occurrence_equipment existing
                    WHERE existing.studio_id = NEW.studio_id
                        AND existing.equipment_id = NEW.equipment_id
                        AND existing.status = 'assigned'
                        AND datetime(NEW.busy_starts_at) < datetime(existing.busy_ends_at)
                        AND datetime(NEW.busy_ends_at) > datetime(existing.busy_starts_at)
                ) + NEW.quantity > (
                    SELECT quantity FROM equipment WHERE studio_id = NEW.studio_id AND id = NEW.equipment_id
                )
                BEGIN SELECT RAISE(ABORT, 'event equipment reservations exceed stock'); END;

                CREATE TRIGGER event_equipment_stock_guard_update
                BEFORE UPDATE ON event_occurrence_equipment
                WHEN NEW.status = 'assigned' AND (
                    SELECT coalesce(sum(existing.quantity), 0)
                    FROM event_occurrence_equipment existing
                    WHERE existing.studio_id = NEW.studio_id
                        AND existing.equipment_id = NEW.equipment_id
                        AND existing.status = 'assigned'
                        AND existing.id <> NEW.id
                        AND datetime(NEW.busy_starts_at) < datetime(existing.busy_ends_at)
                        AND datetime(NEW.busy_ends_at) > datetime(existing.busy_starts_at)
                ) + NEW.quantity > (
                    SELECT quantity FROM equipment WHERE studio_id = NEW.studio_id AND id = NEW.equipment_id
                )
                BEGIN SELECT RAISE(ABORT, 'event equipment reservations exceed stock'); END;
                SQL);
        }
    }

    private function removeOverlapGuards(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['event_occurrence_teachers', 'event_occurrence_rooms', 'event_occurrence_participants'] as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_overlap_insert; DROP TRIGGER IF EXISTS {$table}_no_overlap_update;");
            }

            DB::unprepared('DROP TRIGGER IF EXISTS event_equipment_stock_guard_insert; DROP TRIGGER IF EXISTS event_equipment_stock_guard_update;');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS event_equipment_stock_guard ON event_occurrence_equipment; DROP FUNCTION IF EXISTS public.app_enforce_event_equipment_stock();');
        }
    }

    private function installImmutabilityGuards(): void
    {
        foreach (['schedule_change_events', 'event_series_splits'] as $table) {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared(<<<SQL
                    CREATE OR REPLACE FUNCTION public.app_reject_{$table}_mutation() RETURNS trigger
                    LANGUAGE plpgsql SET search_path = pg_catalog, public AS \$function\$
                    BEGIN RAISE EXCEPTION '{$table} is immutable' USING ERRCODE = '55000'; END;
                    \$function\$;
                    CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION public.app_reject_{$table}_mutation();
                    SQL);
            } elseif (DB::getDriverName() === 'sqlite') {
                DB::unprepared(<<<SQL
                    CREATE TRIGGER {$table}_immutable_update BEFORE UPDATE ON {$table}
                    BEGIN SELECT RAISE(ABORT, '{$table} is immutable'); END;
                    CREATE TRIGGER {$table}_immutable_delete BEFORE DELETE ON {$table}
                    BEGIN SELECT RAISE(ABORT, '{$table} is immutable'); END;
                    SQL);
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.app_guard_scheduling_command_claim_transition() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public AS $function$
                BEGIN
                    IF TG_OP = 'DELETE' OR OLD.id <> NEW.id OR OLD.studio_id <> NEW.studio_id OR OLD.actor_id <> NEW.actor_id
                        OR OLD.idempotency_key <> NEW.idempotency_key OR OLD.operation_hash <> NEW.operation_hash
                        OR OLD.created_at <> NEW.created_at OR OLD.status = 'completed' OR NEW.status <> 'completed'
                    THEN
                        RAISE EXCEPTION 'scheduling command claims permit only pending-to-completed transitions' USING ERRCODE = '55000';
                    END IF;
                    RETURN NEW;
                END;
                $function$;
                CREATE TRIGGER scheduling_command_claim_transition_guard
                BEFORE UPDATE OR DELETE ON scheduling_command_claims
                FOR EACH ROW EXECUTE FUNCTION public.app_guard_scheduling_command_claim_transition();
                SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER scheduling_command_claim_transition_guard_update
                BEFORE UPDATE ON scheduling_command_claims
                WHEN OLD.id <> NEW.id OR OLD.studio_id <> NEW.studio_id OR OLD.actor_id <> NEW.actor_id
                    OR OLD.idempotency_key <> NEW.idempotency_key OR OLD.operation_hash <> NEW.operation_hash
                    OR OLD.created_at <> NEW.created_at OR OLD.status = 'completed' OR NEW.status <> 'completed'
                BEGIN SELECT RAISE(ABORT, 'scheduling command claims permit only pending-to-completed transitions'); END;
                CREATE TRIGGER scheduling_command_claim_transition_guard_delete
                BEFORE DELETE ON scheduling_command_claims
                BEGIN SELECT RAISE(ABORT, 'scheduling command claims are not deletable'); END;
                SQL);
        }
    }

    private function removeImmutabilityGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS scheduling_command_claim_transition_guard ON scheduling_command_claims; DROP FUNCTION IF EXISTS public.app_guard_scheduling_command_claim_transition();');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS scheduling_command_claim_transition_guard_update; DROP TRIGGER IF EXISTS scheduling_command_claim_transition_guard_delete;');
        }

        foreach (['schedule_change_events', 'event_series_splits'] as $table) {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable ON {$table}; DROP FUNCTION IF EXISTS public.app_reject_{$table}_mutation();");
            } elseif (DB::getDriverName() === 'sqlite') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_update; DROP TRIGGER IF EXISTS {$table}_immutable_delete;");
            }
        }
    }

    private function installRowLevelSecurity(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TENANT_TABLES as $table) {
            DB::unprepared(<<<SQL
                ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
                CREATE POLICY {$table}_studio_isolation ON {$table}
                    USING (studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26))
                    WITH CHECK (studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26));
                SQL);
        }
    }

    private function removeRowLevelSecurity(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_reverse(self::TENANT_TABLES) as $table) {
            DB::unprepared("DROP POLICY IF EXISTS {$table}_studio_isolation ON {$table}; ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;");
        }
    }
};
