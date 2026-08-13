<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const TENANT_TABLES = [
        'attendance_records',
        'attendance_corrections',
        'lesson_note_templates',
        'lesson_note_template_revisions',
        'lesson_notes',
        'lesson_note_revisions',
        'lesson_note_delivery_previews',
        'lesson_note_delivery_intents',
        'attendance_domain_commands',
    ];

    public function up(): void
    {
        Schema::table('event_occurrence_participants', function (Blueprint $table): void {
            $table->unique(
                ['studio_id', 'id', 'event_occurrence_id', 'person_id'],
                'event_participants_attendance_subject_unique',
            );
        });

        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('event_occurrence_id');
            $table->foreignUlid('event_occurrence_participant_id');
            $table->foreignUlid('person_id');
            $table->string('outcome', 32);
            $table->string('billing_disposition', 24);
            $table->string('makeup_disposition', 24);
            $table->unsignedSmallInteger('minutes_late')->default(0);
            $table->string('reason', 500)->nullable();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->foreign(['studio_id', 'event_occurrence_participant_id', 'event_occurrence_id', 'person_id'], 'attendance_participant_subject_fk')
                ->references(['studio_id', 'id', 'event_occurrence_id', 'person_id'])->on('event_occurrence_participants')->restrictOnDelete();
            $table->unique(['studio_id', 'event_occurrence_participant_id']);
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'event_occurrence_id', 'outcome']);
        });

        Schema::create('attendance_corrections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('attendance_record_id');
            $table->unsignedInteger('previous_version');
            $table->json('previous_values');
            $table->json('new_values');
            $table->string('reason', 500);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->foreign(['studio_id', 'attendance_record_id'])->references(['studio_id', 'id'])->on('attendance_records')->restrictOnDelete();
            $table->unique(['studio_id', 'attendance_record_id', 'previous_version']);
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('lesson_note_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->string('name', 120);
            $table->string('normalized_name', 120);
            $table->string('audience', 24);
            $table->text('body_html');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['studio_id', 'normalized_name']);
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('lesson_note_template_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('lesson_note_template_id');
            $table->unsignedInteger('revision');
            $table->string('name', 120);
            $table->string('audience', 24);
            $table->text('body_html');
            $table->boolean('active');
            $table->string('reason', 500);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->foreign(['studio_id', 'lesson_note_template_id'])->references(['studio_id', 'id'])->on('lesson_note_templates')->restrictOnDelete();
            $table->unique(['studio_id', 'lesson_note_template_id', 'revision'], 'lesson_note_template_revision_unique');
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('lesson_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('event_occurrence_id');
            $table->foreignUlid('event_occurrence_participant_id')->nullable();
            $table->foreignUlid('person_id')->nullable();
            $table->foreignUlid('author_staff_profile_id')->nullable();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->string('scope', 24);
            $table->string('audience', 24);
            $table->string('title', 160)->nullable();
            $table->text('body_html');
            $table->unsignedInteger('current_revision')->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->foreign(['studio_id', 'event_occurrence_id'])->references(['studio_id', 'id'])->on('event_occurrences')->restrictOnDelete();
            $table->foreign(['studio_id', 'author_staff_profile_id'])->references(['studio_id', 'id'])->on('staff_profiles')->restrictOnDelete();
            $table->foreign(['studio_id', 'event_occurrence_participant_id', 'event_occurrence_id', 'person_id'], 'lesson_note_participant_subject_fk')
                ->references(['studio_id', 'id', 'event_occurrence_id', 'person_id'])->on('event_occurrence_participants')->restrictOnDelete();
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'event_occurrence_id', 'created_at']);
        });

        Schema::create('lesson_note_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('lesson_note_id');
            $table->unsignedInteger('revision');
            $table->string('title', 160)->nullable();
            $table->text('body_html');
            $table->string('reason', 500)->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->foreign(['studio_id', 'lesson_note_id'])->references(['studio_id', 'id'])->on('lesson_notes')->restrictOnDelete();
            $table->unique(['studio_id', 'lesson_note_id', 'revision']);
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('lesson_note_delivery_previews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('lesson_note_id');
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('note_version');
            $table->char('command_hash', 64);
            $table->char('recipient_hash', 64);
            $table->json('recipient_projection');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestamps();
            $table->foreign(['studio_id', 'lesson_note_id'])->references(['studio_id', 'id'])->on('lesson_notes')->cascadeOnDelete();
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'actor_id', 'expires_at']);
        });

        Schema::create('lesson_note_delivery_intents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('lesson_note_id');
            $table->foreignUlid('delivery_preview_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->char('recipient_hash', 64);
            $table->json('recipient_projection');
            $table->string('status', 24)->default('committed');
            $table->timestampTz('committed_at');
            $table->timestamps();
            $table->foreign(['studio_id', 'lesson_note_id'])->references(['studio_id', 'id'])->on('lesson_notes')->restrictOnDelete();
            $table->foreign(['studio_id', 'delivery_preview_id'])->references(['studio_id', 'id'])->on('lesson_note_delivery_previews')->restrictOnDelete();
            $table->unique(['studio_id', 'actor_id', 'idempotency_key'], 'lesson_note_delivery_actor_idempotency_unique');
            $table->unsignedInteger('note_version');
            $table->unique(['studio_id', 'lesson_note_id', 'note_version', 'recipient_hash'], 'lesson_note_delivery_recipient_unique');
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('attendance_domain_commands', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('operation', 64);
            $table->string('idempotency_key', 100);
            $table->char('command_hash', 64);
            $table->json('result_projection');
            $table->timestampTz('created_at');
            $table->unique(['studio_id', 'actor_id', 'idempotency_key'], 'attendance_domain_command_idempotency_unique');
            $table->unique(['studio_id', 'id']);
        });

        $this->installConstraints();
        $this->installProjectionGuards();
        $this->installImmutability();
        $this->installRowLevelSecurity();
    }

    public function down(): void
    {
        $this->removeRowLevelSecurity();
        $this->removeImmutability();
        $this->removeProjectionGuards();

        foreach (array_reverse(self::TENANT_TABLES) as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('event_occurrence_participants', function (Blueprint $table): void {
            $table->dropUnique('event_participants_attendance_subject_unique');
        });
    }

    private function installConstraints(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER attendance_records_state_insert BEFORE INSERT ON attendance_records
                WHEN NEW.version < 1
                    OR NEW.outcome NOT IN ('present','late','absent_excused','absent_unexcused','no_show','teacher_cancelled')
                    OR NEW.billing_disposition NOT IN ('bill','no_charge','credit','pending_review')
                    OR NEW.makeup_disposition NOT IN ('none','required','waived','pending_review')
                    OR NOT ((NEW.outcome = 'late' AND NEW.minutes_late BETWEEN 1 AND 1440) OR (NEW.outcome <> 'late' AND NEW.minutes_late = 0))
                    OR NOT (
                        (NEW.outcome IN ('present','late') AND NEW.billing_disposition = 'bill' AND NEW.makeup_disposition = 'none')
                        OR (NEW.outcome = 'teacher_cancelled' AND NEW.billing_disposition IN ('no_charge','credit','pending_review') AND NEW.makeup_disposition IN ('required','waived'))
                        OR (NEW.outcome IN ('absent_excused','absent_unexcused','no_show') AND NEW.billing_disposition IN ('bill','no_charge','credit','pending_review') AND NEW.makeup_disposition IN ('none','required','waived','pending_review'))
                    )
                BEGIN SELECT RAISE(ABORT, 'invalid attendance state'); END;
                CREATE TRIGGER attendance_records_state_update BEFORE UPDATE ON attendance_records
                WHEN NEW.version < 1
                    OR NEW.outcome NOT IN ('present','late','absent_excused','absent_unexcused','no_show','teacher_cancelled')
                    OR NEW.billing_disposition NOT IN ('bill','no_charge','credit','pending_review')
                    OR NEW.makeup_disposition NOT IN ('none','required','waived','pending_review')
                    OR NOT ((NEW.outcome = 'late' AND NEW.minutes_late BETWEEN 1 AND 1440) OR (NEW.outcome <> 'late' AND NEW.minutes_late = 0))
                    OR NOT (
                        (NEW.outcome IN ('present','late') AND NEW.billing_disposition = 'bill' AND NEW.makeup_disposition = 'none')
                        OR (NEW.outcome = 'teacher_cancelled' AND NEW.billing_disposition IN ('no_charge','credit','pending_review') AND NEW.makeup_disposition IN ('required','waived'))
                        OR (NEW.outcome IN ('absent_excused','absent_unexcused','no_show') AND NEW.billing_disposition IN ('bill','no_charge','credit','pending_review') AND NEW.makeup_disposition IN ('none','required','waived','pending_review'))
                    )
                BEGIN SELECT RAISE(ABORT, 'invalid attendance state'); END;
                CREATE TRIGGER attendance_corrections_snapshot_insert BEFORE INSERT ON attendance_corrections
                WHEN json_type(NEW.previous_values, '$.outcome') IS NULL
                    OR json_type(NEW.previous_values, '$.billing_disposition') IS NULL
                    OR json_type(NEW.previous_values, '$.makeup_disposition') IS NULL
                    OR json_type(NEW.previous_values, '$.minutes_late') IS NULL
                    OR json_type(NEW.previous_values, '$.recorded_by_user_id') IS NULL
                    OR json_type(NEW.previous_values, '$.recorded_at') IS NULL
                    OR json_type(NEW.new_values, '$.outcome') IS NULL
                    OR json_type(NEW.new_values, '$.billing_disposition') IS NULL
                    OR json_type(NEW.new_values, '$.makeup_disposition') IS NULL
                    OR json_type(NEW.new_values, '$.minutes_late') IS NULL
                    OR json_type(NEW.new_values, '$.recorded_by_user_id') IS NULL
                    OR json_type(NEW.new_values, '$.recorded_at') IS NULL
                BEGIN SELECT RAISE(ABORT, 'invalid attendance correction snapshot'); END;
                CREATE TRIGGER lesson_note_templates_state_insert BEFORE INSERT ON lesson_note_templates
                WHEN NEW.version < 1 OR length(trim(NEW.name)) = 0 OR NEW.audience NOT IN ('student','guardian','author_private')
                BEGIN SELECT RAISE(ABORT, 'invalid lesson note template state'); END;
                CREATE TRIGGER lesson_note_templates_state_update BEFORE UPDATE ON lesson_note_templates
                WHEN NEW.version < 1 OR length(trim(NEW.name)) = 0 OR NEW.audience NOT IN ('student','guardian','author_private')
                BEGIN SELECT RAISE(ABORT, 'invalid lesson note template state'); END;
                CREATE TRIGGER lesson_notes_state_insert BEFORE INSERT ON lesson_notes
                WHEN NEW.version < 1 OR NEW.current_revision < 1
                    OR NEW.scope NOT IN ('participant','group') OR NEW.audience NOT IN ('student','guardian','author_private')
                    OR NOT ((NEW.scope = 'participant' AND NEW.event_occurrence_participant_id IS NOT NULL AND NEW.person_id IS NOT NULL)
                        OR (NEW.scope = 'group' AND NEW.event_occurrence_participant_id IS NULL AND NEW.person_id IS NULL))
                BEGIN SELECT RAISE(ABORT, 'invalid lesson note state'); END;
                CREATE TRIGGER lesson_notes_state_update BEFORE UPDATE ON lesson_notes
                WHEN NEW.version < 1 OR NEW.current_revision < 1
                    OR NEW.scope NOT IN ('participant','group') OR NEW.audience NOT IN ('student','guardian','author_private')
                    OR NOT ((NEW.scope = 'participant' AND NEW.event_occurrence_participant_id IS NOT NULL AND NEW.person_id IS NOT NULL)
                        OR (NEW.scope = 'group' AND NEW.event_occurrence_participant_id IS NULL AND NEW.person_id IS NULL))
                BEGIN SELECT RAISE(ABORT, 'invalid lesson note state'); END;
                CREATE TRIGGER lesson_note_delivery_intents_state_insert BEFORE INSERT ON lesson_note_delivery_intents
                WHEN NEW.status NOT IN ('pending','committed','canceled')
                BEGIN SELECT RAISE(ABORT, 'invalid lesson note delivery state'); END;
                SQL);

            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE attendance_records ADD CONSTRAINT attendance_records_state_check CHECK (
                version >= 1
                AND outcome IN ('present','late','absent_excused','absent_unexcused','no_show','teacher_cancelled')
                AND billing_disposition IN ('bill','no_charge','credit','pending_review')
                AND makeup_disposition IN ('none','required','waived','pending_review')
                AND ((outcome = 'late' AND minutes_late BETWEEN 1 AND 1440) OR (outcome <> 'late' AND minutes_late = 0))
                AND (
                    (outcome IN ('present','late') AND billing_disposition = 'bill' AND makeup_disposition = 'none')
                    OR (outcome = 'teacher_cancelled' AND billing_disposition IN ('no_charge','credit','pending_review') AND makeup_disposition IN ('required','waived'))
                    OR (outcome IN ('absent_excused','absent_unexcused','no_show') AND billing_disposition IN ('bill','no_charge','credit','pending_review') AND makeup_disposition IN ('none','required','waived','pending_review'))
                )
            );
            ALTER TABLE attendance_corrections ADD CONSTRAINT attendance_corrections_snapshot_check CHECK (
                previous_values::jsonb ?& ARRAY['outcome','billing_disposition','makeup_disposition','minutes_late','recorded_by_user_id','recorded_at']
                AND new_values::jsonb ?& ARRAY['outcome','billing_disposition','makeup_disposition','minutes_late','recorded_by_user_id','recorded_at']
            );
            ALTER TABLE lesson_note_templates ADD CONSTRAINT lesson_note_templates_state_check CHECK (
                version >= 1 AND length(trim(name)) > 0 AND audience IN ('student','guardian','author_private')
            );
            ALTER TABLE lesson_notes ADD CONSTRAINT lesson_notes_state_check CHECK (
                version >= 1 AND current_revision >= 1
                AND scope IN ('participant','group') AND audience IN ('student','guardian','author_private')
                AND ((scope = 'participant' AND event_occurrence_participant_id IS NOT NULL AND person_id IS NOT NULL)
                    OR (scope = 'group' AND event_occurrence_participant_id IS NULL AND person_id IS NULL))
            );
            ALTER TABLE lesson_note_delivery_intents ADD CONSTRAINT lesson_note_delivery_intents_state_check CHECK (
                status IN ('pending','committed','canceled')
            );
            SQL);
    }

    private function installImmutability(): void
    {
        foreach (['attendance_corrections', 'lesson_note_template_revisions', 'lesson_note_revisions', 'lesson_note_delivery_intents', 'attendance_domain_commands'] as $table) {
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
    }

    private function installProjectionGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.app_reject_attendance_records_delete() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public AS $function$
                BEGIN RAISE EXCEPTION 'attendance records are immutable' USING ERRCODE = '55000'; END;
                $function$;
                CREATE OR REPLACE FUNCTION public.app_reject_lesson_notes_delete() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public AS $function$
                BEGIN RAISE EXCEPTION 'lesson notes are immutable' USING ERRCODE = '55000'; END;
                $function$;
                CREATE OR REPLACE FUNCTION public.app_reject_lesson_note_templates_delete() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public AS $function$
                BEGIN RAISE EXCEPTION 'lesson note templates are immutable; retire instead' USING ERRCODE = '55000'; END;
                $function$;
                CREATE OR REPLACE FUNCTION public.app_guard_attendance_projection() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public AS $function$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM public.attendance_corrections correction
                        WHERE correction.studio_id = OLD.studio_id
                            AND correction.attendance_record_id = OLD.id
                            AND correction.previous_version = OLD.version
                            AND correction.previous_values::jsonb @> jsonb_build_object(
                                'outcome', OLD.outcome,
                                'billing_disposition', OLD.billing_disposition,
                                'makeup_disposition', OLD.makeup_disposition,
                                'minutes_late', OLD.minutes_late,
                                'recorded_by_user_id', OLD.recorded_by_user_id
                            )
                            AND (correction.previous_values::jsonb ->> 'reason') IS NOT DISTINCT FROM OLD.reason
                            AND (correction.previous_values::jsonb ->> 'recorded_at')::timestamptz = OLD.recorded_at
                            AND correction.new_values::jsonb @> jsonb_build_object(
                                'outcome', NEW.outcome,
                                'billing_disposition', NEW.billing_disposition,
                                'makeup_disposition', NEW.makeup_disposition,
                                'minutes_late', NEW.minutes_late,
                                'recorded_by_user_id', NEW.recorded_by_user_id
                            )
                            AND (correction.new_values::jsonb ->> 'reason') IS NOT DISTINCT FROM NEW.reason
                            AND (correction.new_values::jsonb ->> 'recorded_at')::timestamptz = NEW.recorded_at
                    ) THEN RAISE EXCEPTION 'attendance records require immutable correction history' USING ERRCODE = '55000';
                    END IF;
                    IF NEW.version <> OLD.version + 1
                        OR NEW.studio_id <> OLD.studio_id
                        OR NEW.id <> OLD.id
                        OR NEW.event_occurrence_id <> OLD.event_occurrence_id
                        OR NEW.event_occurrence_participant_id <> OLD.event_occurrence_participant_id
                        OR NEW.person_id <> OLD.person_id
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    THEN RAISE EXCEPTION 'attendance record identity and version transition are immutable' USING ERRCODE = '55000';
                    END IF;
                    RETURN NEW;
                END;
                $function$;
                CREATE TRIGGER attendance_records_projection_guard BEFORE UPDATE ON attendance_records
                FOR EACH ROW EXECUTE FUNCTION public.app_guard_attendance_projection();
                CREATE TRIGGER attendance_records_delete_guard BEFORE DELETE ON attendance_records
                FOR EACH ROW EXECUTE FUNCTION public.app_reject_attendance_records_delete();

                CREATE OR REPLACE FUNCTION public.app_guard_lesson_note_projection() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public AS $function$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM public.lesson_note_revisions revision
                        WHERE revision.studio_id = OLD.studio_id
                            AND revision.lesson_note_id = OLD.id
                            AND revision.revision = NEW.current_revision
                            AND revision.body_html = NEW.body_html
                            AND revision.title IS NOT DISTINCT FROM NEW.title
                    ) THEN RAISE EXCEPTION 'lesson notes require immutable revision history' USING ERRCODE = '55000';
                    END IF;
                    IF NEW.version <> OLD.version + 1 OR NEW.current_revision <> OLD.current_revision + 1
                        OR NEW.studio_id <> OLD.studio_id OR NEW.id <> OLD.id
                        OR NEW.event_occurrence_id <> OLD.event_occurrence_id
                        OR NEW.event_occurrence_participant_id IS DISTINCT FROM OLD.event_occurrence_participant_id
                        OR NEW.person_id IS DISTINCT FROM OLD.person_id
                        OR NEW.author_staff_profile_id IS DISTINCT FROM OLD.author_staff_profile_id
                        OR NEW.author_user_id <> OLD.author_user_id
                        OR NEW.scope <> OLD.scope OR NEW.audience <> OLD.audience
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    THEN RAISE EXCEPTION 'lesson note identity and version transition are immutable' USING ERRCODE = '55000';
                    END IF;
                    RETURN NEW;
                END;
                $function$;
                CREATE TRIGGER lesson_notes_projection_guard BEFORE UPDATE ON lesson_notes
                FOR EACH ROW EXECUTE FUNCTION public.app_guard_lesson_note_projection();
                CREATE TRIGGER lesson_notes_delete_guard BEFORE DELETE ON lesson_notes
                FOR EACH ROW EXECUTE FUNCTION public.app_reject_lesson_notes_delete();
                CREATE TRIGGER lesson_note_templates_delete_guard BEFORE DELETE ON lesson_note_templates
                FOR EACH ROW EXECUTE FUNCTION public.app_reject_lesson_note_templates_delete();
                CREATE OR REPLACE FUNCTION public.app_guard_lesson_note_template_projection() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public AS $function$
                BEGIN
                    IF NEW.version <> OLD.version + 1 OR NEW.studio_id <> OLD.studio_id OR NEW.id <> OLD.id
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR NOT EXISTS (
                            SELECT 1 FROM public.lesson_note_template_revisions revision
                            WHERE revision.studio_id = OLD.studio_id
                                AND revision.lesson_note_template_id = OLD.id
                                AND revision.revision = NEW.version
                                AND revision.name = NEW.name
                                AND revision.audience = NEW.audience
                                AND revision.body_html = NEW.body_html
                                AND revision.active = NEW.active
                        )
                    THEN RAISE EXCEPTION 'lesson note templates require immutable revision history' USING ERRCODE = '55000';
                    END IF;
                    RETURN NEW;
                END;
                $function$;
                CREATE TRIGGER lesson_note_templates_projection_guard BEFORE UPDATE ON lesson_note_templates
                FOR EACH ROW EXECUTE FUNCTION public.app_guard_lesson_note_template_projection();
                SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER attendance_records_projection_guard BEFORE UPDATE ON attendance_records
                WHEN NOT EXISTS (
                    SELECT 1 FROM attendance_corrections correction
                    WHERE correction.studio_id = OLD.studio_id
                        AND correction.attendance_record_id = OLD.id
                        AND correction.previous_version = OLD.version
                        AND json_extract(correction.previous_values, '$.outcome') = OLD.outcome
                        AND json_extract(correction.previous_values, '$.billing_disposition') = OLD.billing_disposition
                        AND json_extract(correction.previous_values, '$.makeup_disposition') = OLD.makeup_disposition
                        AND json_extract(correction.previous_values, '$.minutes_late') = OLD.minutes_late
                        AND json_extract(correction.previous_values, '$.recorded_by_user_id') = OLD.recorded_by_user_id
                        AND json_extract(correction.previous_values, '$.reason') IS OLD.reason
                        AND datetime(json_extract(correction.previous_values, '$.recorded_at')) = datetime(OLD.recorded_at)
                        AND json_extract(correction.new_values, '$.outcome') = NEW.outcome
                        AND json_extract(correction.new_values, '$.billing_disposition') = NEW.billing_disposition
                        AND json_extract(correction.new_values, '$.makeup_disposition') = NEW.makeup_disposition
                        AND json_extract(correction.new_values, '$.minutes_late') = NEW.minutes_late
                        AND json_extract(correction.new_values, '$.recorded_by_user_id') = NEW.recorded_by_user_id
                        AND json_extract(correction.new_values, '$.reason') IS NEW.reason
                        AND datetime(json_extract(correction.new_values, '$.recorded_at')) = datetime(NEW.recorded_at)
                ) BEGIN SELECT RAISE(ABORT, 'attendance records require immutable correction history'); END;
                CREATE TRIGGER attendance_records_identity_guard BEFORE UPDATE ON attendance_records
                WHEN NEW.version <> OLD.version + 1
                    OR NEW.studio_id <> OLD.studio_id OR NEW.id <> OLD.id
                    OR NEW.event_occurrence_id <> OLD.event_occurrence_id
                    OR NEW.event_occurrence_participant_id <> OLD.event_occurrence_participant_id
                    OR NEW.person_id <> OLD.person_id OR NEW.created_at IS NOT OLD.created_at
                BEGIN SELECT RAISE(ABORT, 'attendance record identity and version transition are immutable'); END;
                CREATE TRIGGER attendance_records_delete_guard BEFORE DELETE ON attendance_records
                BEGIN SELECT RAISE(ABORT, 'attendance records are immutable'); END;

                CREATE TRIGGER lesson_notes_projection_guard BEFORE UPDATE ON lesson_notes
                WHEN NOT EXISTS (
                    SELECT 1 FROM lesson_note_revisions revision
                    WHERE revision.studio_id = OLD.studio_id
                        AND revision.lesson_note_id = OLD.id
                        AND revision.revision = NEW.current_revision
                        AND revision.body_html = NEW.body_html
                        AND revision.title IS NEW.title
                ) BEGIN SELECT RAISE(ABORT, 'lesson notes require immutable revision history'); END;
                CREATE TRIGGER lesson_notes_identity_guard BEFORE UPDATE ON lesson_notes
                WHEN NEW.version <> OLD.version + 1 OR NEW.current_revision <> OLD.current_revision + 1
                    OR NEW.studio_id <> OLD.studio_id OR NEW.id <> OLD.id
                    OR NEW.event_occurrence_id <> OLD.event_occurrence_id
                    OR NEW.event_occurrence_participant_id IS NOT OLD.event_occurrence_participant_id
                    OR NEW.person_id IS NOT OLD.person_id
                    OR NEW.author_staff_profile_id IS NOT OLD.author_staff_profile_id
                    OR NEW.author_user_id <> OLD.author_user_id
                    OR NEW.scope <> OLD.scope OR NEW.audience <> OLD.audience
                    OR NEW.created_at IS NOT OLD.created_at
                BEGIN SELECT RAISE(ABORT, 'lesson note identity and version transition are immutable'); END;
                CREATE TRIGGER lesson_notes_delete_guard BEFORE DELETE ON lesson_notes
                BEGIN SELECT RAISE(ABORT, 'lesson notes are immutable'); END;
                CREATE TRIGGER lesson_note_templates_delete_guard BEFORE DELETE ON lesson_note_templates
                BEGIN SELECT RAISE(ABORT, 'lesson note templates are immutable; retire instead'); END;
                CREATE TRIGGER lesson_note_templates_projection_guard BEFORE UPDATE ON lesson_note_templates
                WHEN NEW.version <> OLD.version + 1 OR NEW.studio_id <> OLD.studio_id OR NEW.id <> OLD.id
                    OR NEW.created_at IS NOT OLD.created_at
                    OR NOT EXISTS (
                        SELECT 1 FROM lesson_note_template_revisions revision
                        WHERE revision.studio_id = OLD.studio_id
                            AND revision.lesson_note_template_id = OLD.id
                            AND revision.revision = NEW.version
                            AND revision.name = NEW.name
                            AND revision.audience = NEW.audience
                            AND revision.body_html = NEW.body_html
                            AND revision.active = NEW.active
                    )
                BEGIN SELECT RAISE(ABORT, 'lesson note templates require immutable revision history'); END;
                SQL);
        }
    }

    private function removeProjectionGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS lesson_notes_projection_guard ON lesson_notes;
                DROP TRIGGER IF EXISTS lesson_notes_identity_guard ON lesson_notes;
                DROP TRIGGER IF EXISTS lesson_notes_delete_guard ON lesson_notes;
                DROP FUNCTION IF EXISTS public.app_guard_lesson_note_projection();
                DROP FUNCTION IF EXISTS public.app_reject_lesson_notes_delete();
                DROP TRIGGER IF EXISTS lesson_note_templates_delete_guard ON lesson_note_templates;
                DROP TRIGGER IF EXISTS lesson_note_templates_projection_guard ON lesson_note_templates;
                DROP FUNCTION IF EXISTS public.app_guard_lesson_note_template_projection();
                DROP FUNCTION IF EXISTS public.app_reject_lesson_note_templates_delete();
                DROP TRIGGER IF EXISTS attendance_records_projection_guard ON attendance_records;
                DROP TRIGGER IF EXISTS attendance_records_identity_guard ON attendance_records;
                DROP TRIGGER IF EXISTS attendance_records_delete_guard ON attendance_records;
                DROP FUNCTION IF EXISTS public.app_guard_attendance_projection();
                DROP FUNCTION IF EXISTS public.app_reject_attendance_records_delete();
                SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS lesson_notes_projection_guard;
                DROP TRIGGER IF EXISTS lesson_notes_identity_guard;
                DROP TRIGGER IF EXISTS lesson_notes_delete_guard;
                DROP TRIGGER IF EXISTS lesson_note_templates_delete_guard;
                DROP TRIGGER IF EXISTS lesson_note_templates_projection_guard;
                DROP TRIGGER IF EXISTS attendance_records_projection_guard;
                DROP TRIGGER IF EXISTS attendance_records_identity_guard;
                DROP TRIGGER IF EXISTS attendance_records_delete_guard;
                SQL);
        }
    }

    private function removeImmutability(): void
    {
        foreach (['attendance_corrections', 'lesson_note_template_revisions', 'lesson_note_revisions', 'lesson_note_delivery_intents', 'attendance_domain_commands'] as $table) {
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
            DB::unprepared("DROP POLICY IF EXISTS {$table}_studio_isolation ON {$table}; ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY; ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;");
        }
    }
};
