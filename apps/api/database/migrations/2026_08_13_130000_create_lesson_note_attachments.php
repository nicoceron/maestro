<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const TENANT_TABLES = [
        'lesson_note_attachments',
        'lesson_note_attachment_scans',
        'lesson_note_attachment_retirements',
        'lesson_note_attachment_commands',
        'lesson_note_attachment_purge_claims',
        'lesson_note_attachment_purges',
    ];

    /** @var list<string> */
    private const IMMUTABLE_TABLES = [
        'lesson_note_attachments',
        'lesson_note_attachment_scans',
        'lesson_note_attachment_retirements',
        'lesson_note_attachment_commands',
        'lesson_note_attachment_purges',
    ];

    public function up(): void
    {
        Schema::table('lesson_note_revisions', function (Blueprint $table): void {
            $table->unique(
                ['studio_id', 'lesson_note_id', 'id'],
                'lesson_note_revision_attachment_target_unique',
            );
        });

        Schema::create('lesson_note_attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('lesson_note_id');
            $table->foreignUlid('lesson_note_revision_id');
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('quarantine_disk', 64);
            $table->string('quarantine_key', 512);
            $table->string('original_name', 255);
            $table->string('extension', 12);
            $table->string('declared_mime', 120)->nullable();
            $table->string('detected_mime', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestampTz('created_at');

            $table->foreign(['studio_id', 'lesson_note_id'])
                ->references(['studio_id', 'id'])->on('lesson_notes')->restrictOnDelete();
            $table->foreign(
                ['studio_id', 'lesson_note_id', 'lesson_note_revision_id'],
                'lesson_note_attachment_revision_fk',
            )->references(['studio_id', 'lesson_note_id', 'id'])
                ->on('lesson_note_revisions')->restrictOnDelete();
            $table->unique(['studio_id', 'id']);
            $table->unique(['studio_id', 'lesson_note_id', 'id'], 'lesson_note_attachment_note_identity_unique');
            $table->unique(
                ['studio_id', 'quarantine_disk', 'quarantine_key'],
                'lesson_note_attachment_quarantine_object_unique',
            );
            $table->index(['studio_id', 'lesson_note_id', 'lesson_note_revision_id']);
        });

        Schema::create('lesson_note_attachment_scans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('lesson_note_attachment_id');
            $table->unsignedSmallInteger('attempt');
            $table->string('status', 16);
            $table->string('engine', 64);
            $table->string('engine_version', 64)->nullable();
            $table->string('detail_code', 64);
            $table->string('clean_disk', 64)->nullable();
            $table->string('clean_key', 512)->nullable();
            $table->timestampTz('scanned_at');

            $table->foreign(['studio_id', 'lesson_note_attachment_id'])
                ->references(['studio_id', 'id'])->on('lesson_note_attachments')->restrictOnDelete();
            $table->unique(['studio_id', 'lesson_note_attachment_id', 'attempt'], 'lesson_note_attachment_scan_attempt_unique');
            $table->unique(['studio_id', 'id']);
            $table->unique(['studio_id', 'clean_disk', 'clean_key'], 'lesson_note_attachment_clean_object_unique');
        });

        Schema::create('lesson_note_attachment_retirements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('lesson_note_id');
            $table->foreignUlid('lesson_note_attachment_id');
            $table->foreignId('retired_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('retired_at');

            $table->foreign(
                ['studio_id', 'lesson_note_id', 'lesson_note_attachment_id'],
                'lesson_note_attachment_retirement_target_fk',
            )->references(['studio_id', 'lesson_note_id', 'id'])
                ->on('lesson_note_attachments')->restrictOnDelete();
            $table->unique(['studio_id', 'lesson_note_attachment_id'], 'lesson_note_attachment_retirement_unique');
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('lesson_note_attachment_commands', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('lesson_note_id');
            $table->foreignUlid('lesson_note_attachment_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->char('command_hash', 64);
            $table->timestampTz('created_at');

            $table->foreign(['studio_id', 'lesson_note_id'])
                ->references(['studio_id', 'id'])->on('lesson_notes')->restrictOnDelete();
            $table->foreign(
                ['studio_id', 'lesson_note_id', 'lesson_note_attachment_id'],
                'lesson_note_attachment_command_target_fk',
            )->references(['studio_id', 'lesson_note_id', 'id'])
                ->on('lesson_note_attachments')->restrictOnDelete();
            $table->unique(['studio_id', 'actor_id', 'idempotency_key'], 'lesson_note_attachment_command_idempotency_unique');
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('lesson_note_attachment_purges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('lesson_note_attachment_id');
            $table->string('object_kind', 16);
            $table->string('disk', 64);
            $table->string('object_key', 512);
            $table->string('reason', 64);
            $table->timestampTz('purged_at');

            $table->foreign(['studio_id', 'lesson_note_attachment_id'])
                ->references(['studio_id', 'id'])->on('lesson_note_attachments')->restrictOnDelete();
            $table->unique(
                ['studio_id', 'lesson_note_attachment_id', 'object_kind', 'disk', 'object_key'],
                'lesson_note_attachment_purge_object_unique',
            );
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('lesson_note_attachment_purge_claims', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('lesson_note_attachment_id');
            $table->string('object_kind', 16);
            $table->string('disk', 64);
            $table->string('object_key', 512);
            $table->string('reason', 64);
            $table->string('status', 16);
            $table->string('failure_code', 64)->nullable();
            $table->timestampTz('claimed_at');
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->foreign(['studio_id', 'lesson_note_attachment_id'])
                ->references(['studio_id', 'id'])->on('lesson_note_attachments')->restrictOnDelete();
            $table->unique(
                ['studio_id', 'lesson_note_attachment_id', 'object_kind', 'disk', 'object_key'],
                'lesson_note_attachment_purge_claim_object_unique',
            );
            $table->unique(['studio_id', 'id']);
        });

        $this->installConstraints();
        $this->installPurgeClaimGuards();
        $this->installImmutability();
        $this->installRowLevelSecurity();
    }

    public function down(): void
    {
        $this->removeRowLevelSecurity();
        $this->removeImmutability();
        $this->removePurgeClaimGuards();

        foreach (array_reverse(self::TENANT_TABLES) as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('lesson_note_revisions', function (Blueprint $table): void {
            $table->dropUnique('lesson_note_revision_attachment_target_unique');
        });
    }

    private function installConstraints(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE lesson_note_attachments ADD CONSTRAINT lesson_note_attachments_metadata_check CHECK (
                    size_bytes > 0
                    AND length(original_name) > 0
                    AND extension ~ '^[a-z0-9]{1,12}$'
                    AND sha256 ~ '^[a-f0-9]{64}$'
                    AND quarantine_disk ~ '^[A-Za-z0-9_-]{1,64}$'
                    AND quarantine_key ~ '^quarantine/[0-9a-hjkmnp-tv-z]{26}/[0-9a-hjkmnp-tv-z]{26}/[a-f0-9]{40}\.[a-z0-9]{1,12}$'
                );
                ALTER TABLE lesson_note_attachment_scans ADD CONSTRAINT lesson_note_attachment_scans_state_check CHECK (
                    attempt >= 1
                    AND status IN ('clean','infected','failed')
                    AND engine ~ '^[A-Za-z0-9._-]{1,64}$'
                    AND detail_code ~ '^[a-z0-9._-]{1,64}$'
                    AND (
                        (status = 'clean' AND clean_disk IS NOT NULL AND clean_key IS NOT NULL
                            AND clean_disk ~ '^[A-Za-z0-9_-]{1,64}$'
                            AND clean_key ~ '^clean/[0-9a-hjkmnp-tv-z]{26}/[0-9a-hjkmnp-tv-z]{26}/[a-f0-9]{40}\.[a-z0-9]{1,12}$')
                        OR (status <> 'clean' AND clean_disk IS NULL AND clean_key IS NULL)
                    )
                );
                ALTER TABLE lesson_note_attachment_retirements ADD CONSTRAINT lesson_note_attachment_retirements_reason_check
                    CHECK (length(trim(reason)) > 0);
                ALTER TABLE lesson_note_attachment_commands ADD CONSTRAINT lesson_note_attachment_commands_hash_check
                    CHECK (length(idempotency_key) > 0 AND command_hash ~ '^[a-f0-9]{64}$');
                ALTER TABLE lesson_note_attachment_purges ADD CONSTRAINT lesson_note_attachment_purges_state_check CHECK (
                    object_kind IN ('quarantine','clean')
                    AND disk ~ '^[A-Za-z0-9_-]{1,64}$'
                    AND object_key !~ '(^|/)\.\.(/|$)'
                    AND reason IN ('retired_retention_elapsed','quarantine_retention_elapsed','pending_retention_elapsed')
                );
                ALTER TABLE lesson_note_attachment_purge_claims ADD CONSTRAINT lesson_note_attachment_purge_claims_state_check CHECK (
                    object_kind IN ('quarantine','clean')
                    AND disk ~ '^[A-Za-z0-9_-]{1,64}$'
                    AND object_key !~ '(^|/)\.\.(/|$)'
                    AND reason IN ('retired_retention_elapsed','quarantine_retention_elapsed','pending_retention_elapsed')
                    AND status IN ('claimed','completed','failed')
                    AND ((status = 'claimed' AND lease_expires_at IS NOT NULL AND completed_at IS NULL AND failure_code IS NULL)
                        OR (status = 'completed' AND lease_expires_at IS NULL AND completed_at IS NOT NULL AND failure_code IS NULL)
                        OR (status = 'failed' AND lease_expires_at IS NULL AND completed_at IS NULL AND failure_code IS NOT NULL))
                );
                SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER lesson_note_attachments_metadata_insert BEFORE INSERT ON lesson_note_attachments
                WHEN NEW.size_bytes < 1 OR length(NEW.original_name) < 1
                    OR length(NEW.extension) < 1 OR length(NEW.extension) > 12
                    OR NEW.extension GLOB '*[^a-z0-9]*'
                    OR length(NEW.sha256) <> 64 OR lower(NEW.sha256) <> NEW.sha256
                    OR length(NEW.quarantine_disk) < 1
                    OR NEW.quarantine_key NOT LIKE 'quarantine/%'
                    OR NEW.quarantine_key LIKE '%..%'
                BEGIN SELECT RAISE(ABORT, 'invalid lesson note attachment metadata'); END;
                CREATE TRIGGER lesson_note_attachment_scans_state_insert BEFORE INSERT ON lesson_note_attachment_scans
                WHEN NEW.attempt < 1 OR NEW.status NOT IN ('clean','infected','failed')
                    OR length(NEW.engine) < 1 OR length(NEW.detail_code) < 1
                    OR NOT (
                        (NEW.status = 'clean' AND NEW.clean_disk IS NOT NULL AND NEW.clean_key LIKE 'clean/%' AND NEW.clean_key NOT LIKE '%..%')
                        OR (NEW.status <> 'clean' AND NEW.clean_disk IS NULL AND NEW.clean_key IS NULL)
                    )
                BEGIN SELECT RAISE(ABORT, 'invalid lesson note attachment scan'); END;
                CREATE TRIGGER lesson_note_attachment_retirements_reason_insert BEFORE INSERT ON lesson_note_attachment_retirements
                WHEN length(trim(NEW.reason)) < 1
                BEGIN SELECT RAISE(ABORT, 'attachment retirement reason is required'); END;
                CREATE TRIGGER lesson_note_attachment_commands_state_insert BEFORE INSERT ON lesson_note_attachment_commands
                WHEN length(NEW.idempotency_key) < 1 OR length(NEW.command_hash) <> 64 OR lower(NEW.command_hash) <> NEW.command_hash
                BEGIN SELECT RAISE(ABORT, 'invalid lesson note attachment command'); END;
                CREATE TRIGGER lesson_note_attachment_purges_state_insert BEFORE INSERT ON lesson_note_attachment_purges
                WHEN NEW.object_kind NOT IN ('quarantine','clean') OR length(NEW.disk) < 1
                    OR NEW.object_key LIKE '%..%'
                    OR NEW.reason NOT IN ('retired_retention_elapsed','quarantine_retention_elapsed','pending_retention_elapsed')
                BEGIN SELECT RAISE(ABORT, 'invalid lesson note attachment purge'); END;
                CREATE TRIGGER lesson_note_attachment_purge_claims_state_insert BEFORE INSERT ON lesson_note_attachment_purge_claims
                WHEN NEW.object_kind NOT IN ('quarantine','clean') OR length(NEW.disk) < 1
                    OR NEW.object_key LIKE '%..%'
                    OR NEW.reason NOT IN ('retired_retention_elapsed','quarantine_retention_elapsed','pending_retention_elapsed')
                    OR NEW.status NOT IN ('claimed','completed','failed')
                    OR NOT ((NEW.status = 'claimed' AND NEW.lease_expires_at IS NOT NULL AND NEW.completed_at IS NULL AND NEW.failure_code IS NULL)
                        OR (NEW.status = 'completed' AND NEW.lease_expires_at IS NULL AND NEW.completed_at IS NOT NULL AND NEW.failure_code IS NULL)
                        OR (NEW.status = 'failed' AND NEW.lease_expires_at IS NULL AND NEW.completed_at IS NULL AND NEW.failure_code IS NOT NULL))
                BEGIN SELECT RAISE(ABORT, 'invalid lesson note attachment purge claim'); END;
                CREATE TRIGGER lesson_note_attachment_purge_claims_state_update BEFORE UPDATE ON lesson_note_attachment_purge_claims
                WHEN NEW.studio_id <> OLD.studio_id OR NEW.lesson_note_attachment_id <> OLD.lesson_note_attachment_id
                    OR NEW.object_kind <> OLD.object_kind OR NEW.disk <> OLD.disk OR NEW.object_key <> OLD.object_key
                    OR NEW.reason <> OLD.reason OR NEW.id <> OLD.id
                    OR NEW.status NOT IN ('claimed','completed','failed')
                    OR NOT ((NEW.status = 'claimed' AND NEW.lease_expires_at IS NOT NULL AND NEW.completed_at IS NULL AND NEW.failure_code IS NULL)
                        OR (NEW.status = 'completed' AND NEW.lease_expires_at IS NULL AND NEW.completed_at IS NOT NULL AND NEW.failure_code IS NULL)
                        OR (NEW.status = 'failed' AND NEW.lease_expires_at IS NULL AND NEW.completed_at IS NULL AND NEW.failure_code IS NOT NULL))
                BEGIN SELECT RAISE(ABORT, 'invalid lesson note attachment purge claim transition'); END;
                SQL);
        }
    }

    private function installImmutability(): void
    {
        foreach (self::IMMUTABLE_TABLES as $table) {
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

    private function installPurgeClaimGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.app_guard_lesson_note_attachment_purge_claim() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public AS $function$
                BEGIN
                    IF NEW.id <> OLD.id OR NEW.studio_id <> OLD.studio_id
                        OR NEW.lesson_note_attachment_id <> OLD.lesson_note_attachment_id
                        OR NEW.object_kind <> OLD.object_kind OR NEW.disk <> OLD.disk
                        OR NEW.object_key <> OLD.object_key OR NEW.reason <> OLD.reason
                        OR OLD.status = 'completed'
                        OR (OLD.status = 'claimed' AND NEW.status = 'claimed' AND OLD.lease_expires_at > statement_timestamp())
                    THEN RAISE EXCEPTION 'invalid lesson note attachment purge claim transition' USING ERRCODE = '55000';
                    END IF;
                    RETURN NEW;
                END;
                $function$;
                CREATE TRIGGER lesson_note_attachment_purge_claims_transition_guard
                    BEFORE UPDATE ON lesson_note_attachment_purge_claims
                    FOR EACH ROW EXECUTE FUNCTION public.app_guard_lesson_note_attachment_purge_claim();
                SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER lesson_note_attachment_purge_claims_transition_guard BEFORE UPDATE ON lesson_note_attachment_purge_claims
                WHEN NEW.id <> OLD.id OR NEW.studio_id <> OLD.studio_id
                    OR NEW.lesson_note_attachment_id <> OLD.lesson_note_attachment_id
                    OR NEW.object_kind <> OLD.object_kind OR NEW.disk <> OLD.disk
                    OR NEW.object_key <> OLD.object_key OR NEW.reason <> OLD.reason
                    OR OLD.status = 'completed'
                    OR (OLD.status = 'claimed' AND NEW.status = 'claimed' AND datetime(OLD.lease_expires_at) > datetime('now'))
                BEGIN SELECT RAISE(ABORT, 'invalid lesson note attachment purge claim transition'); END;
                SQL);
        }
    }

    private function removePurgeClaimGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS lesson_note_attachment_purge_claims_transition_guard ON lesson_note_attachment_purge_claims;
                DROP FUNCTION IF EXISTS public.app_guard_lesson_note_attachment_purge_claim();
                SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS lesson_note_attachment_purge_claims_transition_guard;');
        }
    }

    private function removeImmutability(): void
    {
        foreach (array_reverse(self::IMMUTABLE_TABLES) as $table) {
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
