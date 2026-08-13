<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const TENANT_TABLES = [
        'staff_profiles',
        'instruments',
        'person_instruments',
        'tags',
        'person_tags',
        'custom_field_definitions',
        'custom_field_values',
        'student_status_transitions',
    ];

    public function up(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
            $table->string('source', 80)->nullable();
            $table->string('external_reference', 120)->nullable();
            $table->string('preferred_locale', 16)->nullable();

            $table->unique(['studio_id', 'external_reference']);
            $table->unique(['studio_id', 'user_id']);
            $table->index(['studio_id', 'source']);

            if (DB::getDriverName() === 'pgsql') {
                $table->foreign(['studio_id', 'user_id'], 'people_studio_user_membership_fk')
                    ->references(['studio_id', 'user_id'])->on('studio_memberships')->restrictOnDelete();
            }
        });

        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->string('lead_source', 80)->nullable();
            $table->date('trial_started_on')->nullable();
            $table->date('waitlisted_on')->nullable();
            $table->timestampTz('status_changed_at')->nullable();
            $table->unique(
                ['studio_id', 'id', 'person_id'],
                'student_profiles_studio_id_person_unique',
            );
        });

        Schema::create('staff_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('person_id');
            $table->json('roles');
            $table->string('status', 24)->default('active');
            $table->string('employment_type', 24)->nullable();
            $table->text('bio')->nullable();
            $table->date('hire_on')->nullable();
            $table->date('left_on')->nullable();
            $table->boolean('can_substitute')->default(false);
            $table->timestamps();

            $table->foreign(['studio_id', 'person_id'])
                ->references(['studio_id', 'id'])->on('people')->cascadeOnDelete();
            $table->unique(['studio_id', 'person_id']);
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'status']);
        });

        Schema::create('instruments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['studio_id', 'normalized_name']);
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('person_instruments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('person_id');
            $table->foreignUlid('instrument_id');
            $table->string('relationship', 16);
            $table->string('proficiency', 24)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('years_experience')->nullable();
            $table->timestamps();

            $table->foreign(['studio_id', 'person_id'])
                ->references(['studio_id', 'id'])->on('people')->cascadeOnDelete();
            $table->foreign(['studio_id', 'instrument_id'])
                ->references(['studio_id', 'id'])->on('instruments')->cascadeOnDelete();
            $table->unique(['studio_id', 'person_id', 'instrument_id']);
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'instrument_id', 'relationship']);
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('normalized_name', 80);
            $table->string('color', 7)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['studio_id', 'normalized_name']);
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('person_tags', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('person_id');
            $table->foreignUlid('tag_id');
            $table->timestamps();

            $table->foreign(['studio_id', 'person_id'])
                ->references(['studio_id', 'id'])->on('people')->cascadeOnDelete();
            $table->foreign(['studio_id', 'tag_id'])
                ->references(['studio_id', 'id'])->on('tags')->cascadeOnDelete();
            $table->unique(['studio_id', 'person_id', 'tag_id']);
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'tag_id']);
        });

        Schema::create('custom_field_definitions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->string('name', 100);
            $table->string('type', 24);
            $table->string('applies_to', 24)->default('person');
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['studio_id', 'key']);
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'applies_to', 'active']);
        });

        Schema::create('custom_field_values', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('definition_id');
            $table->foreignUlid('person_id');
            $table->json('value');
            $table->timestamps();

            $table->foreign(['studio_id', 'definition_id'])
                ->references(['studio_id', 'id'])->on('custom_field_definitions')->cascadeOnDelete();
            $table->foreign(['studio_id', 'person_id'])
                ->references(['studio_id', 'id'])->on('people')->cascadeOnDelete();
            $table->unique(['studio_id', 'definition_id', 'person_id'], 'custom_field_person_unique');
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'person_id']);
        });

        Schema::create('student_status_transitions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('student_profile_id');
            $table->foreignUlid('person_id');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('previous_status', 24)->nullable();
            $table->string('new_status', 24);
            $table->string('reason', 500)->nullable();
            $table->timestampTz('occurred_at');

            $table->foreign(
                ['studio_id', 'student_profile_id', 'person_id'],
                'student_status_transitions_profile_person_fk',
            )->references(['studio_id', 'id', 'person_id'])
                ->on('student_profiles')->cascadeOnDelete();
            $table->foreign(['studio_id', 'person_id'])
                ->references(['studio_id', 'id'])->on('people')->cascadeOnDelete();
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'person_id', 'occurred_at']);
        });

        $this->installDomainConstraints();

        $this->installDatabaseGuards();
    }

    public function down(): void
    {
        $this->removeDatabaseGuards();
        $this->removeDomainConstraints();

        foreach (array_reverse(self::TENANT_TABLES) as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropUnique('student_profiles_studio_id_person_unique');
            $table->dropColumn(['lead_source', 'trial_started_on', 'waitlisted_on', 'status_changed_at']);
        });

        Schema::table('people', function (Blueprint $table): void {
            if (DB::getDriverName() === 'pgsql') {
                $table->dropForeign('people_studio_user_membership_fk');
            }

            $table->dropUnique(['studio_id', 'external_reference']);
            $table->dropUnique(['studio_id', 'user_id']);
            $table->dropIndex(['studio_id', 'source']);
            $table->dropColumn(['version', 'source', 'external_reference', 'preferred_locale']);
        });
    }

    private function installDomainConstraints(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE people ADD CONSTRAINT people_version_check CHECK (version >= 1);
                ALTER TABLE people ADD CONSTRAINT people_status_check CHECK (status IN ('active', 'inactive', 'archived'));
                ALTER TABLE student_profiles ADD CONSTRAINT student_profiles_status_check CHECK (status IN ('lead', 'trial', 'waiting', 'active', 'paused', 'former'));
                ALTER TABLE staff_profiles ADD CONSTRAINT staff_profiles_status_check CHECK (status IN ('active', 'on_leave', 'former'));
                ALTER TABLE staff_profiles ADD CONSTRAINT staff_profiles_employment_type_check CHECK (employment_type IS NULL OR employment_type IN ('employee', 'contractor', 'volunteer'));
                ALTER TABLE staff_profiles ADD CONSTRAINT staff_profiles_dates_check CHECK (left_on IS NULL OR hire_on IS NULL OR left_on >= hire_on);
                ALTER TABLE instruments ADD CONSTRAINT instruments_name_check CHECK (length(trim(name)) > 0 AND length(trim(normalized_name)) > 0);
                ALTER TABLE tags ADD CONSTRAINT tags_name_check CHECK (length(trim(name)) > 0 AND length(trim(normalized_name)) > 0);
                ALTER TABLE tags ADD CONSTRAINT tags_color_check CHECK (color IS NULL OR color ~ '^#[0-9A-Fa-f]{6}$');
                ALTER TABLE person_instruments ADD CONSTRAINT person_instruments_relationship_check CHECK (relationship IN ('studies', 'teaches', 'both'));
                ALTER TABLE person_instruments ADD CONSTRAINT person_instruments_proficiency_check CHECK (proficiency IS NULL OR proficiency IN ('beginner', 'intermediate', 'advanced', 'professional'));
                ALTER TABLE person_instruments ADD CONSTRAINT person_instruments_years_check CHECK (years_experience IS NULL OR years_experience <= 100);
                ALTER TABLE custom_field_definitions ADD CONSTRAINT custom_field_definitions_type_check CHECK (type IN ('text', 'long_text', 'number', 'boolean', 'date', 'select', 'multi_select'));
                ALTER TABLE custom_field_definitions ADD CONSTRAINT custom_field_definitions_applies_to_check CHECK (applies_to IN ('person', 'student', 'staff'));
                ALTER TABLE student_status_transitions ADD CONSTRAINT student_status_transition_status_check CHECK (previous_status IS NULL OR previous_status <> new_status);
                CREATE UNIQUE INDEX student_status_transitions_initial_unique
                    ON student_status_transitions (studio_id, student_profile_id)
                    WHERE previous_status IS NULL;
                SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER people_domain_insert BEFORE INSERT ON people
                WHEN NEW.version < 1 OR NEW.status NOT IN ('active', 'inactive', 'archived')
                BEGIN SELECT RAISE(ABORT, 'invalid person state'); END;
                CREATE TRIGGER people_domain_update BEFORE UPDATE ON people
                WHEN NEW.version < 1 OR NEW.status NOT IN ('active', 'inactive', 'archived')
                BEGIN SELECT RAISE(ABORT, 'invalid person state'); END;
                CREATE TRIGGER student_profiles_status_insert BEFORE INSERT ON student_profiles
                WHEN NEW.status NOT IN ('lead', 'trial', 'waiting', 'active', 'paused', 'former')
                BEGIN SELECT RAISE(ABORT, 'invalid student status'); END;
                CREATE TRIGGER student_profiles_status_update BEFORE UPDATE ON student_profiles
                WHEN NEW.status NOT IN ('lead', 'trial', 'waiting', 'active', 'paused', 'former')
                BEGIN SELECT RAISE(ABORT, 'invalid student status'); END;
                CREATE TRIGGER student_status_transitions_state_insert BEFORE INSERT ON student_status_transitions
                WHEN (NEW.previous_status IS NOT NULL AND NEW.previous_status NOT IN ('lead', 'trial', 'waiting', 'active', 'paused', 'former'))
                    OR NEW.new_status NOT IN ('lead', 'trial', 'waiting', 'active', 'paused', 'former')
                    OR NEW.previous_status = NEW.new_status
                BEGIN SELECT RAISE(ABORT, 'invalid student status transition'); END;
                CREATE UNIQUE INDEX student_status_transitions_initial_unique
                    ON student_status_transitions (studio_id, student_profile_id)
                    WHERE previous_status IS NULL;
                SQL);
        }
    }

    private function installDatabaseGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TENANT_TABLES as $table) {
                DB::unprepared(<<<SQL
                    ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                    ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
                    CREATE POLICY {$table}_studio_isolation ON {$table}
                        USING (
                            studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26)
                        )
                        WITH CHECK (
                            studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26)
                        );
                    SQL);
            }

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.app_validate_student_status_transition()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public
                AS $function$
                DECLARE
                    current_status varchar;
                BEGIN
                    SELECT status INTO current_status
                    FROM public.student_profiles
                    WHERE studio_id = NEW.studio_id
                        AND id = NEW.student_profile_id
                        AND person_id = NEW.person_id;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'student profile does not match transition subject'
                            USING ERRCODE = '23503';
                    END IF;

                    IF NEW.previous_status IS NULL THEN
                        IF current_status IS DISTINCT FROM NEW.new_status OR EXISTS (
                            SELECT 1 FROM public.student_status_transitions
                            WHERE studio_id = NEW.studio_id
                                AND student_profile_id = NEW.student_profile_id
                        ) THEN
                            RAISE EXCEPTION 'invalid initial student status event'
                                USING ERRCODE = '23514';
                        END IF;
                    ELSIF current_status IS DISTINCT FROM NEW.previous_status OR NOT (
                        (NEW.previous_status = 'lead' AND NEW.new_status IN ('trial', 'waiting', 'active', 'former'))
                        OR (NEW.previous_status = 'trial' AND NEW.new_status IN ('lead', 'waiting', 'active', 'former'))
                        OR (NEW.previous_status = 'waiting' AND NEW.new_status IN ('lead', 'trial', 'active', 'former'))
                        OR (NEW.previous_status = 'active' AND NEW.new_status IN ('paused', 'former'))
                        OR (NEW.previous_status = 'paused' AND NEW.new_status IN ('active', 'former'))
                        OR (NEW.previous_status = 'former' AND NEW.new_status IN ('lead', 'trial', 'waiting', 'active'))
                    ) THEN
                        RAISE EXCEPTION 'invalid student status transition'
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END;
                $function$;

                CREATE TRIGGER student_status_transitions_validate
                    BEFORE INSERT ON student_status_transitions
                    FOR EACH ROW EXECUTE FUNCTION public.app_validate_student_status_transition();

                CREATE OR REPLACE FUNCTION public.app_apply_student_status_transition()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public
                AS $function$
                BEGIN
                    IF NEW.previous_status IS NULL THEN
                        UPDATE public.student_profiles
                        SET status_changed_at = coalesce(status_changed_at, NEW.occurred_at)
                        WHERE studio_id = NEW.studio_id AND id = NEW.student_profile_id;

                        RETURN NEW;
                    END IF;

                    UPDATE public.student_profiles
                    SET status = NEW.new_status,
                        joined_on = CASE
                            WHEN NEW.new_status = 'active' THEN coalesce(joined_on, NEW.occurred_at::date)
                            ELSE joined_on
                        END,
                        left_on = CASE
                            WHEN NEW.new_status = 'former' THEN NEW.occurred_at::date
                            ELSE NULL
                        END,
                        trial_started_on = CASE
                            WHEN NEW.new_status = 'trial' THEN coalesce(trial_started_on, NEW.occurred_at::date)
                            ELSE trial_started_on
                        END,
                        waitlisted_on = CASE
                            WHEN NEW.new_status = 'waiting' THEN coalesce(waitlisted_on, NEW.occurred_at::date)
                            ELSE waitlisted_on
                        END,
                        status_changed_at = NEW.occurred_at,
                        updated_at = NEW.occurred_at
                    WHERE studio_id = NEW.studio_id AND id = NEW.student_profile_id;

                    UPDATE public.people
                    SET version = version + 1, updated_at = NEW.occurred_at
                    WHERE studio_id = NEW.studio_id AND id = NEW.person_id;

                    RETURN NEW;
                END;
                $function$;

                CREATE TRIGGER student_status_transitions_apply
                    AFTER INSERT ON student_status_transitions
                    FOR EACH ROW EXECUTE FUNCTION public.app_apply_student_status_transition();

                CREATE OR REPLACE FUNCTION public.app_protect_student_status_update()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public
                AS $function$
                BEGIN
                    IF NEW.status IS DISTINCT FROM OLD.status AND pg_trigger_depth() < 2 THEN
                        RAISE EXCEPTION 'student status must change through immutable history'
                            USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END;
                $function$;

                CREATE TRIGGER student_profiles_protect_status_update
                    BEFORE UPDATE ON student_profiles
                    FOR EACH ROW EXECUTE FUNCTION public.app_protect_student_status_update();

                CREATE OR REPLACE FUNCTION public.app_reject_student_status_transition_mutation()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public
                AS $function$
                BEGIN
                    RAISE EXCEPTION 'student status transitions are immutable';
                END;
                $function$;

                CREATE TRIGGER student_status_transitions_immutable
                    BEFORE UPDATE OR DELETE ON student_status_transitions
                    FOR EACH ROW EXECUTE FUNCTION public.app_reject_student_status_transition_mutation();

                CREATE OR REPLACE FUNCTION public.app_protect_custom_field_definition_shape()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public
                AS $function$
                BEGIN
                    IF (NEW.type IS DISTINCT FROM OLD.type
                        OR NEW.applies_to IS DISTINCT FROM OLD.applies_to
                        OR NEW.options IS DISTINCT FROM OLD.options)
                        AND EXISTS (
                            SELECT 1 FROM public.custom_field_values
                            WHERE studio_id = OLD.studio_id AND definition_id = OLD.id
                        ) THEN
                        RAISE EXCEPTION 'custom field shape cannot change while values exist'
                            USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END;
                $function$;

                CREATE TRIGGER custom_field_definitions_protect_shape
                    BEFORE UPDATE ON custom_field_definitions
                    FOR EACH ROW EXECUTE FUNCTION public.app_protect_custom_field_definition_shape();
                SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER people_validate_user_membership_insert
                BEFORE INSERT ON people
                WHEN NEW.user_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM studio_memberships
                    WHERE studio_id = NEW.studio_id AND user_id = NEW.user_id
                )
                BEGIN SELECT RAISE(ABORT, 'person user must belong to the same studio'); END;

                CREATE TRIGGER people_validate_user_membership_update
                BEFORE UPDATE OF studio_id, user_id ON people
                WHEN NEW.user_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM studio_memberships
                    WHERE studio_id = NEW.studio_id AND user_id = NEW.user_id
                )
                BEGIN SELECT RAISE(ABORT, 'person user must belong to the same studio'); END;

                CREATE TRIGGER student_status_transitions_validate_initial
                BEFORE INSERT ON student_status_transitions
                WHEN NEW.previous_status IS NULL AND (
                    NOT EXISTS (
                        SELECT 1 FROM student_profiles
                        WHERE studio_id = NEW.studio_id
                            AND id = NEW.student_profile_id
                            AND person_id = NEW.person_id
                            AND status = NEW.new_status
                    )
                    OR EXISTS (
                        SELECT 1 FROM student_status_transitions
                        WHERE studio_id = NEW.studio_id
                            AND student_profile_id = NEW.student_profile_id
                    )
                )
                BEGIN SELECT RAISE(ABORT, 'invalid initial student status event'); END;

                CREATE TRIGGER student_status_transitions_validate_change
                BEFORE INSERT ON student_status_transitions
                WHEN NEW.previous_status IS NOT NULL AND (
                    NOT EXISTS (
                        SELECT 1 FROM student_profiles
                        WHERE studio_id = NEW.studio_id
                            AND id = NEW.student_profile_id
                            AND person_id = NEW.person_id
                            AND status = NEW.previous_status
                    )
                    OR NOT (
                        (NEW.previous_status = 'lead' AND NEW.new_status IN ('trial', 'waiting', 'active', 'former'))
                        OR (NEW.previous_status = 'trial' AND NEW.new_status IN ('lead', 'waiting', 'active', 'former'))
                        OR (NEW.previous_status = 'waiting' AND NEW.new_status IN ('lead', 'trial', 'active', 'former'))
                        OR (NEW.previous_status = 'active' AND NEW.new_status IN ('paused', 'former'))
                        OR (NEW.previous_status = 'paused' AND NEW.new_status IN ('active', 'former'))
                        OR (NEW.previous_status = 'former' AND NEW.new_status IN ('lead', 'trial', 'waiting', 'active'))
                    )
                )
                BEGIN SELECT RAISE(ABORT, 'invalid student status transition'); END;

                CREATE TRIGGER student_status_transitions_apply_initial
                AFTER INSERT ON student_status_transitions
                WHEN NEW.previous_status IS NULL
                BEGIN
                    UPDATE student_profiles
                    SET status_changed_at = coalesce(status_changed_at, NEW.occurred_at)
                    WHERE studio_id = NEW.studio_id AND id = NEW.student_profile_id;
                END;

                CREATE TRIGGER student_status_transitions_apply_change
                AFTER INSERT ON student_status_transitions
                WHEN NEW.previous_status IS NOT NULL
                BEGIN
                    UPDATE student_profiles
                    SET status = NEW.new_status,
                        joined_on = CASE WHEN NEW.new_status = 'active' THEN coalesce(joined_on, date(NEW.occurred_at)) ELSE joined_on END,
                        left_on = CASE WHEN NEW.new_status = 'former' THEN date(NEW.occurred_at) ELSE NULL END,
                        trial_started_on = CASE WHEN NEW.new_status = 'trial' THEN coalesce(trial_started_on, date(NEW.occurred_at)) ELSE trial_started_on END,
                        waitlisted_on = CASE WHEN NEW.new_status = 'waiting' THEN coalesce(waitlisted_on, date(NEW.occurred_at)) ELSE waitlisted_on END,
                        status_changed_at = NEW.occurred_at,
                        updated_at = NEW.occurred_at
                    WHERE studio_id = NEW.studio_id AND id = NEW.student_profile_id;
                    UPDATE people
                    SET version = version + 1, updated_at = NEW.occurred_at
                    WHERE studio_id = NEW.studio_id AND id = NEW.person_id;
                END;

                CREATE TRIGGER student_profiles_protect_status_update
                BEFORE UPDATE OF status ON student_profiles
                WHEN NEW.status IS NOT OLD.status AND NOT EXISTS (
                    SELECT 1
                    FROM student_status_transitions
                    WHERE studio_id = OLD.studio_id
                        AND student_profile_id = OLD.id
                        AND person_id = OLD.person_id
                        AND previous_status = OLD.status
                        AND new_status = NEW.status
                        AND occurred_at = NEW.updated_at
                    ORDER BY occurred_at DESC, id DESC
                    LIMIT 1
                )
                BEGIN SELECT RAISE(ABORT, 'student status must change through immutable history'); END;

                CREATE TRIGGER student_status_transitions_immutable_update
                BEFORE UPDATE ON student_status_transitions
                BEGIN
                    SELECT RAISE(ABORT, 'student status transitions are immutable');
                END;

                CREATE TRIGGER student_status_transitions_immutable_delete
                BEFORE DELETE ON student_status_transitions
                BEGIN
                    SELECT RAISE(ABORT, 'student status transitions are immutable');
                END;

                CREATE TRIGGER custom_field_definitions_protect_shape
                BEFORE UPDATE ON custom_field_definitions
                WHEN (NEW.type <> OLD.type
                    OR NEW.applies_to <> OLD.applies_to
                    OR NEW.options IS NOT OLD.options)
                    AND EXISTS (
                        SELECT 1 FROM custom_field_values
                        WHERE studio_id = OLD.studio_id AND definition_id = OLD.id
                    )
                BEGIN SELECT RAISE(ABORT, 'custom field shape cannot change while values exist'); END;
                SQL);
        }
    }

    private function removeDomainConstraints(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE people DROP CONSTRAINT IF EXISTS people_version_check;
                ALTER TABLE people DROP CONSTRAINT IF EXISTS people_status_check;
                ALTER TABLE student_profiles DROP CONSTRAINT IF EXISTS student_profiles_status_check;
                SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS student_status_transitions_state_insert;
                DROP TRIGGER IF EXISTS student_profiles_status_update;
                DROP TRIGGER IF EXISTS student_profiles_status_insert;
                DROP TRIGGER IF EXISTS people_domain_update;
                DROP TRIGGER IF EXISTS people_domain_insert;
                SQL);
        }
    }

    private function removeDatabaseGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS custom_field_definitions_protect_shape ON custom_field_definitions;
                DROP FUNCTION IF EXISTS public.app_protect_custom_field_definition_shape();
                DROP TRIGGER IF EXISTS student_profiles_protect_status_update ON student_profiles;
                DROP FUNCTION IF EXISTS public.app_protect_student_status_update();
                DROP TRIGGER IF EXISTS student_status_transitions_apply ON student_status_transitions;
                DROP FUNCTION IF EXISTS public.app_apply_student_status_transition();
                DROP TRIGGER IF EXISTS student_status_transitions_validate ON student_status_transitions;
                DROP FUNCTION IF EXISTS public.app_validate_student_status_transition();
                DROP TRIGGER IF EXISTS student_status_transitions_immutable ON student_status_transitions;
                DROP FUNCTION IF EXISTS public.app_reject_student_status_transition_mutation();
                SQL);

            foreach (array_reverse(self::TENANT_TABLES) as $table) {
                DB::unprepared(<<<SQL
                    DROP POLICY IF EXISTS {$table}_studio_isolation ON {$table};
                    ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY;
                    ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;
                    SQL);
            }

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS custom_field_definitions_protect_shape;
                DROP TRIGGER IF EXISTS people_validate_user_membership_update;
                DROP TRIGGER IF EXISTS people_validate_user_membership_insert;
                DROP TRIGGER IF EXISTS student_profiles_protect_status_update;
                DROP TRIGGER IF EXISTS student_status_transitions_apply_change;
                DROP TRIGGER IF EXISTS student_status_transitions_apply_initial;
                DROP TRIGGER IF EXISTS student_status_transitions_validate_change;
                DROP TRIGGER IF EXISTS student_status_transitions_validate_initial;
                DROP TRIGGER IF EXISTS student_status_transitions_immutable_update;
                DROP TRIGGER IF EXISTS student_status_transitions_immutable_delete;
                SQL);
        }
    }
};
