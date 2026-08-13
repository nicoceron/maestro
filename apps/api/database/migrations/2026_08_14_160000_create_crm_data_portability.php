<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = [
        'crm_import_batches',
        'crm_import_rows',
        'crm_import_commands',
        'crm_portable_ref_maps',
        'crm_portable_exports',
    ];

    public function up(): void
    {
        Schema::create('crm_import_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->unsignedInteger('version')->default(1);
            $table->ulid('active_command_id')->nullable();
            $table->string('status', 24)->default('staged');
            $table->string('schema_name', 40)->default('maestro_people_households');
            $table->string('schema_version', 16)->default('1.0');
            $table->string('encoding', 16)->default('UTF-8');
            $table->boolean('portable_formula_escaping')->default(false);
            $table->string('original_name', 255);
            $table->string('quarantine_path', 512);
            $table->char('source_sha256', 64);
            $table->unsignedBigInteger('source_size');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('conflicted_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->json('column_mapping')->nullable();
            $table->timestampTz('previewed_at')->nullable();
            $table->timestampTz('commit_started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('purged_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->char('failure_digest', 64)->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'studio_id'], 'crm_import_batches_id_studio_unique');
            $table->unique(['studio_id', 'requested_by_id', 'idempotency_key'], 'crm_import_batches_idempotency_unique');
            $table->index(['studio_id', 'status', 'expires_at'], 'crm_import_batches_state_index');
        });

        Schema::create('crm_import_rows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->ulid('import_batch_id');
            $table->unsignedInteger('row_number');
            $table->unsignedInteger('plan_version')->default(1);
            $table->char('content_hash', 64)->nullable();
            $table->json('normalized_payload')->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('decision', 16)->default('conflict');
            $table->string('match_kind', 32)->nullable();
            $table->ulid('candidate_person_id')->nullable();
            $table->unsignedInteger('candidate_person_version')->nullable();
            $table->ulid('candidate_household_id')->nullable();
            $table->unsignedInteger('candidate_household_version')->nullable();
            $table->json('match_candidates')->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->ulid('result_person_id')->nullable();
            $table->ulid('result_household_id')->nullable();
            $table->char('result_digest', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->json('error_fields')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('leased_at')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('purged_at')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'studio_id'], 'crm_import_rows_id_studio_unique');
            $table->unique(['studio_id', 'import_batch_id', 'row_number'], 'crm_import_rows_number_unique');
            $table->index(['studio_id', 'import_batch_id', 'status'], 'crm_import_rows_state_index');
            $table->foreign(['import_batch_id', 'studio_id'], 'crm_import_rows_batch_studio_fk')
                ->references(['id', 'studio_id'])->on('crm_import_batches')->cascadeOnDelete();
        });

        Schema::create('crm_import_commands', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->ulid('import_batch_id');
            $table->foreignId('requested_by_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 16);
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->unsignedInteger('expected_version');
            $table->string('status', 16)->default('queued');
            $table->json('response_snapshot')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'studio_id'], 'crm_import_commands_id_studio_unique');
            $table->unique(
                ['studio_id', 'requested_by_id', 'type', 'idempotency_key'],
                'crm_import_commands_idempotency_unique',
            );
            $table->index(['studio_id', 'import_batch_id', 'status'], 'crm_import_commands_state_index');
            $table->foreign(['import_batch_id', 'studio_id'], 'crm_import_commands_batch_studio_fk')
                ->references(['id', 'studio_id'])->on('crm_import_batches')->cascadeOnDelete();
        });

        Schema::table('crm_import_batches', function (Blueprint $table): void {
            $table->foreign(['active_command_id', 'studio_id'], 'crm_import_batches_active_command_fk')->references(['id', 'studio_id'])->on('crm_import_commands')->restrictOnDelete();
        });

        Schema::create('crm_portable_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 24)->default('queued');
            $table->string('format_version', 16)->default('1.0');
            $table->json('manifest')->nullable();
            $table->string('archive_path', 512)->nullable();
            $table->char('archive_sha256', 64)->nullable();
            $table->unsignedBigInteger('archive_size')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('purged_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->char('failure_digest', 64)->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'studio_id'], 'crm_portable_exports_id_studio_unique');
            $table->unique(['studio_id', 'requested_by_id', 'idempotency_key'], 'crm_portable_exports_idempotency_unique');
            $table->index(['studio_id', 'status', 'expires_at'], 'crm_portable_exports_state_index');
        });

        Schema::create('crm_portable_ref_maps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->ulid('import_batch_id');
            $table->string('source_type', 32);
            $table->string('source_ref', 120);
            $table->ulid('target_id');
            $table->timestampsTz();
            $table->unique(['studio_id', 'import_batch_id', 'source_type', 'source_ref'], 'crm_portable_ref_unique');
            $table->foreign(['import_batch_id', 'studio_id'], 'crm_portable_ref_batch_fk')->references(['id', 'studio_id'])->on('crm_import_batches')->cascadeOnDelete();
        });

        $this->installGuards();
    }

    public function down(): void
    {
        $this->removeGuards();

        if (DB::getDriverName() === 'sqlite') {
            Schema::disableForeignKeyConstraints();

            try {
                Schema::dropIfExists('crm_portable_exports');
                Schema::dropIfExists('crm_portable_ref_maps');
                Schema::dropIfExists('crm_import_commands');
                Schema::dropIfExists('crm_import_rows');
                Schema::dropIfExists('crm_import_batches');
            } finally {
                Schema::enableForeignKeyConstraints();
            }

            return;
        }

        Schema::dropIfExists('crm_portable_exports');
        Schema::dropIfExists('crm_portable_ref_maps');
        Schema::table('crm_import_batches', function (Blueprint $table): void {
            $table->dropForeign('crm_import_batches_active_command_fk');
        });
        Schema::dropIfExists('crm_import_commands');
        Schema::dropIfExists('crm_import_rows');
        Schema::dropIfExists('crm_import_batches');
    }

    private function installGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $table) {
                DB::unprepared(<<<SQL
                    ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                    ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
                    CREATE POLICY {$table}_studio_isolation ON {$table}
                        USING (studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26))
                        WITH CHECK (studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26));
                    SQL);
            }

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.app_reject_terminal_crm_import_row_mutation()
                RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF OLD.status IN ('created', 'updated', 'skipped', 'failed') THEN
                        IF TG_OP = 'DELETE'
                            AND OLD.purged_at IS NOT NULL
                            AND current_setting('app.crm_portability_purge', true) = '1'
                        THEN
                            RETURN OLD;
                        END IF;

                        IF TG_OP = 'UPDATE'
                            AND OLD.purged_at IS NULL
                            AND NEW.purged_at IS NOT NULL
                            AND NEW.status = OLD.status
                            AND NEW.normalized_payload IS NULL
                            AND NEW.match_candidates IS NULL
                            AND NEW.content_hash IS NULL
                            AND NEW.error_fields IS NULL
                            AND NEW.candidate_person_id IS NULL
                            AND NEW.candidate_household_id IS NULL
                            AND NEW.candidate_person_version IS NULL
                            AND NEW.candidate_household_version IS NULL
                            AND NEW.resolved_by_id IS NULL
                            AND NEW.result_person_id IS NULL
                            AND NEW.result_household_id IS NULL
                            AND NEW.result_digest IS NULL
                            AND (to_jsonb(NEW) - ARRAY['content_hash','normalized_payload','candidate_person_id','candidate_person_version','candidate_household_id','candidate_household_version','match_candidates','resolved_by_id','result_person_id','result_household_id','result_digest','error_fields','purged_at','updated_at'])
                                = (to_jsonb(OLD) - ARRAY['content_hash','normalized_payload','candidate_person_id','candidate_person_version','candidate_household_id','candidate_household_version','match_candidates','resolved_by_id','result_person_id','result_household_id','result_digest','error_fields','purged_at','updated_at'])
                            AND current_setting('app.crm_portability_purge', true) = '1'
                        THEN
                            RETURN NEW;
                        END IF;

                        RAISE EXCEPTION 'terminal CRM import row outcomes are immutable outside guarded purge' USING ERRCODE = '55000';
                    END IF;
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END;
                $$;
                CREATE TRIGGER crm_import_rows_terminal_immutable
                    BEFORE UPDATE OR DELETE ON crm_import_rows
                    FOR EACH ROW EXECUTE FUNCTION public.app_reject_terminal_crm_import_row_mutation();
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.app_validate_crm_active_command() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF NEW.active_command_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM crm_import_commands c WHERE c.id=NEW.active_command_id AND c.studio_id=NEW.studio_id AND c.import_batch_id=NEW.id
                    ) THEN RAISE EXCEPTION 'active CRM command must belong to batch' USING ERRCODE='23514'; END IF;
                    RETURN NEW;
                END; $$;
                CREATE TRIGGER crm_import_batches_active_command_guard BEFORE INSERT OR UPDATE OF active_command_id ON crm_import_batches FOR EACH ROW EXECUTE FUNCTION public.app_validate_crm_active_command();
                SQL);

            DB::unprepared(<<<'SQL'
                ALTER TABLE crm_import_batches ADD CONSTRAINT crm_import_batches_state_check CHECK (
                    version >= 1
                    AND status IN ('staged','previewing','needs_resolution','ready','committing','completed','completed_with_errors','failed','expired','purged')
                    AND source_size BETWEEN 1 AND 10485760
                    AND row_count <= 25000
                    AND processed_rows = created_rows + updated_rows + skipped_rows + failed_rows
                    AND processed_rows <= row_count
                    AND conflicted_rows <= row_count
                    AND length(trim(idempotency_key)) > 0
                    AND request_fingerprint ~ '^[a-f0-9]{64}$'
                    AND source_sha256 ~ '^[a-f0-9]{64}$'
                    AND original_name !~ '[\\/[:cntrl:]]'
                );
                ALTER TABLE crm_import_rows ADD CONSTRAINT crm_import_rows_state_check CHECK (
                    status IN ('pending','conflict','resolved','processing','created','updated','skipped','failed')
                    AND decision IN ('create','update','skip','conflict')
                    AND plan_version >= 1
                    AND attempts <= 25
                    AND ((status = 'processing') = (lease_token IS NOT NULL AND lease_expires_at IS NOT NULL))
                );
                ALTER TABLE crm_portable_ref_maps ADD CONSTRAINT crm_portable_ref_maps_state_check CHECK (
                    source_type IN ('person','household','instrument','tag','definition')
                    AND length(trim(source_ref)) BETWEEN 1 AND 120
                );
                ALTER TABLE crm_import_commands ADD CONSTRAINT crm_import_commands_state_check CHECK (
                    type IN ('commit','resume')
                    AND status IN ('queued','running','succeeded','failed')
                    AND expected_version >= 1
                    AND length(trim(idempotency_key)) > 0
                    AND request_fingerprint ~ '^[a-f0-9]{64}$'
                );
                ALTER TABLE crm_portable_exports ADD CONSTRAINT crm_portable_exports_state_check CHECK (
                    version >= 1
                    AND status IN ('queued','building','ready','failed','expired','purged')
                    AND format_version = '1.0'
                    AND download_count <= 1
                    AND length(trim(idempotency_key)) > 0
                    AND request_fingerprint ~ '^[a-f0-9]{64}$'
                );
                SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER crm_import_batches_active_command_insert BEFORE INSERT ON crm_import_batches WHEN NEW.active_command_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM crm_import_commands c WHERE c.id=NEW.active_command_id AND c.studio_id=NEW.studio_id AND c.import_batch_id=NEW.id) BEGIN SELECT RAISE(ABORT,'active CRM command must belong to batch'); END;
                CREATE TRIGGER crm_import_batches_active_command_update BEFORE UPDATE OF active_command_id ON crm_import_batches WHEN NEW.active_command_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM crm_import_commands c WHERE c.id=NEW.active_command_id AND c.studio_id=NEW.studio_id AND c.import_batch_id=NEW.id) BEGIN SELECT RAISE(ABORT,'active CRM command must belong to batch'); END;
                CREATE TRIGGER crm_import_rows_terminal_update BEFORE UPDATE ON crm_import_rows
                WHEN OLD.status IN ('created', 'updated', 'skipped', 'failed')
                    AND NOT (
                        OLD.purged_at IS NULL AND NEW.purged_at IS NOT NULL
                        AND NEW.status = OLD.status
                        AND NEW.normalized_payload IS NULL AND NEW.match_candidates IS NULL
                        AND NEW.content_hash IS NULL
                        AND NEW.error_fields IS NULL AND NEW.candidate_person_id IS NULL
                        AND NEW.candidate_household_id IS NULL AND NEW.candidate_person_version IS NULL
                        AND NEW.candidate_household_version IS NULL AND NEW.resolved_by_id IS NULL
                        AND NEW.result_person_id IS NULL AND NEW.result_household_id IS NULL
                        AND NEW.result_digest IS NULL AND NEW.row_number = OLD.row_number
                        AND NEW.plan_version = OLD.plan_version AND NEW.status = OLD.status
                        AND NEW.decision = OLD.decision AND NEW.attempts = OLD.attempts
                        AND NEW.error_code IS OLD.error_code AND NEW.processed_at IS OLD.processed_at
                    )
                BEGIN SELECT RAISE(ABORT, 'terminal CRM import row outcomes are immutable outside guarded purge'); END;
                CREATE TRIGGER crm_import_rows_terminal_delete BEFORE DELETE ON crm_import_rows
                WHEN OLD.status IN ('created', 'updated', 'skipped', 'failed') AND OLD.purged_at IS NULL
                BEGIN SELECT RAISE(ABORT, 'terminal CRM import row outcomes must be purged before delete'); END;
                SQL);
        }
    }

    private function removeGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS crm_import_rows_terminal_immutable ON crm_import_rows;
                DROP TRIGGER IF EXISTS crm_import_batches_active_command_guard ON crm_import_batches;
                DROP FUNCTION IF EXISTS public.app_validate_crm_active_command();
                DROP FUNCTION IF EXISTS public.app_reject_terminal_crm_import_row_mutation();
                SQL);
            foreach (array_reverse(self::TABLES) as $table) {
                DB::unprepared("DROP POLICY IF EXISTS {$table}_studio_isolation ON {$table}; ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY; ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;");
            }

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS crm_import_rows_terminal_delete; DROP TRIGGER IF EXISTS crm_import_rows_terminal_update; DROP TRIGGER IF EXISTS crm_import_batches_active_command_insert; DROP TRIGGER IF EXISTS crm_import_batches_active_command_update;');
        }
    }
};
