<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const TENANT_TABLES = [
        'tenant_data_exports',
        'tenant_retention_policies',
        'tenant_deletion_requests',
        'tenant_restore_drills',
        'tenant_data_lifecycle_events',
    ];

    public function up(): void
    {
        Schema::create('tenant_data_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->boolean('include_media_inventory')->default(false);
            $table->string('status', 24)->default('requested');
            $table->string('format_version', 16)->default('1.0');
            $table->unsignedInteger('next_dataset_index')->default(0);
            $table->json('completed_datasets')->nullable();
            $table->json('manifest')->nullable();
            $table->string('archive_path', 512)->nullable();
            $table->char('archive_ciphertext_sha256', 64)->nullable();
            $table->unsignedBigInteger('archive_size')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->char('failure_digest', 64)->nullable();
            $table->timestamps();

            $table->unique(['id', 'studio_id'], 'tenant_exports_id_studio_unique');
            $table->unique(
                ['studio_id', 'requested_by_id', 'idempotency_key'],
                'tenant_exports_idempotency_unique',
            );
            $table->index(['studio_id', 'status', 'expires_at'], 'tenant_exports_state_index');
        });

        Schema::create('tenant_retention_policies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('export_ttl_hours')->default(24);
            $table->unsignedSmallInteger('deletion_cooling_off_days')->default(14);
            $table->unsignedSmallInteger('deletion_quarantine_days')->default(30);
            $table->unsignedSmallInteger('operational_retention_days')->default(2555);
            $table->unsignedSmallInteger('media_retention_days')->default(2555);
            $table->unsignedSmallInteger('audit_retention_days')->default(2555);
            $table->boolean('legal_hold')->default(false);
            $table->text('legal_hold_reason')->nullable();
            $table->foreignId('legal_hold_placed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('legal_hold_placed_at')->nullable();
            $table->foreignId('legal_hold_released_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('legal_hold_released_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('tenant_deletion_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->ulid('export_id')->nullable();
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->string('status', 24)->default('requested');
            $table->text('reason');
            $table->string('confirmation_phrase_digest', 64);
            $table->timestamp('cooling_off_ends_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('quarantined_at')->nullable();
            $table->timestamp('purge_eligible_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->json('verification')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['id', 'studio_id'], 'tenant_deletions_id_studio_unique');
            $table->unique(
                ['studio_id', 'requested_by_id', 'idempotency_key'],
                'tenant_deletions_idempotency_unique',
            );
            $table->index(['status', 'cooling_off_ends_at'], 'tenant_deletions_cooling_index');
            $table->index(['status', 'purge_eligible_at'], 'tenant_deletions_purge_index');
            $table->foreign(
                ['export_id', 'studio_id'],
                'tenant_deletions_export_studio_fk',
            )->references(['id', 'studio_id'])->on('tenant_data_exports')->restrictOnDelete();
        });

        Schema::create('tenant_restore_drills', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->restrictOnDelete();
            $table->ulid('export_id');
            $table->foreignId('requested_by_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->default('requested');
            $table->boolean('dry_run')->default(true);
            $table->ulid('target_studio_id')->nullable();
            $table->json('tenant_id_remap')->nullable();
            $table->json('verification')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->char('failure_digest', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'studio_id'], 'tenant_restore_drills_id_studio_unique');
            $table->index(['studio_id', 'status']);
            $table->foreign(
                ['export_id', 'studio_id'],
                'tenant_restore_drills_export_studio_fk',
            )->references(['id', 'studio_id'])->on('tenant_data_exports')->restrictOnDelete();
            $table->foreign('target_studio_id')->references('id')->on('studios')->restrictOnDelete();
        });

        Schema::create('tenant_data_lifecycle_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->restrictOnDelete();
            $table->string('aggregate_type', 32);
            $table->ulid('aggregate_id');
            $table->unsignedInteger('sequence');
            $table->string('event_type', 96);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_id', 64)->nullable();
            $table->char('request_ip_hash', 64)->nullable();
            $table->json('metadata');
            $table->timestamp('occurred_at');

            $table->unique(
                ['studio_id', 'aggregate_type', 'aggregate_id', 'sequence'],
                'tenant_lifecycle_aggregate_sequence_unique',
            );
            $table->index(['studio_id', 'occurred_at'], 'tenant_lifecycle_occurred_index');
        });

        $this->installDatabaseGuards();
    }

    public function down(): void
    {
        $this->removeDatabaseGuards();

        Schema::dropIfExists('tenant_data_lifecycle_events');
        Schema::dropIfExists('tenant_restore_drills');
        Schema::dropIfExists('tenant_deletion_requests');
        Schema::dropIfExists('tenant_retention_policies');
        Schema::dropIfExists('tenant_data_exports');
    }

    private function installDatabaseGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TENANT_TABLES as $table) {
                DB::unprepared(<<<SQL
                    ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                    ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
                    CREATE POLICY {$table}_studio_isolation ON {$table}
                        USING (studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26))
                        WITH CHECK (studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26));
                    SQL);
            }

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.app_reject_tenant_lifecycle_event_mutation()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION 'tenant data lifecycle events are immutable'
                        USING ERRCODE = '55000';
                END;
                $$;

                CREATE TRIGGER tenant_lifecycle_events_immutable
                    BEFORE UPDATE OR DELETE ON tenant_data_lifecycle_events
                    FOR EACH ROW EXECUTE FUNCTION public.app_reject_tenant_lifecycle_event_mutation();

                CREATE OR REPLACE FUNCTION public.app_validate_tenant_deletion_transition()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    IF OLD.status = NEW.status THEN
                        RETURN NEW;
                    END IF;

                    IF NOT (
                        (OLD.status = 'requested' AND NEW.status IN ('cooling_off', 'cancelled', 'failed')) OR
                        (OLD.status = 'cooling_off' AND NEW.status IN ('approved', 'cancelled', 'failed')) OR
                        (OLD.status = 'approved' AND NEW.status IN ('suspended', 'cancelled', 'failed')) OR
                        (OLD.status = 'suspended' AND NEW.status IN ('quarantined', 'restoring', 'restored', 'failed')) OR
                        (OLD.status = 'quarantined' AND NEW.status IN ('purge_eligible', 'restoring', 'restored', 'failed')) OR
                        (OLD.status = 'purge_eligible' AND NEW.status IN ('restoring', 'restored')) OR
                        (OLD.status = 'restoring' AND NEW.status IN ('restored', 'failed')) OR
                        (OLD.status = 'failed' AND NEW.status IN ('restoring', 'cancelled'))
                    ) THEN
                        RAISE EXCEPTION 'invalid tenant deletion transition: % -> %', OLD.status, NEW.status
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END;
                $$;

                CREATE TRIGGER tenant_deletions_validate_transition
                    BEFORE UPDATE ON tenant_deletion_requests
                    FOR EACH ROW EXECUTE FUNCTION public.app_validate_tenant_deletion_transition();

                CREATE TRIGGER tenant_deletions_no_delete
                    BEFORE DELETE ON tenant_deletion_requests
                    FOR EACH ROW EXECUTE FUNCTION public.app_reject_tenant_lifecycle_event_mutation();
                SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER tenant_lifecycle_events_immutable_update
                    BEFORE UPDATE ON tenant_data_lifecycle_events
                    BEGIN SELECT RAISE(ABORT, 'tenant data lifecycle events are immutable'); END;
                CREATE TRIGGER tenant_lifecycle_events_immutable_delete
                    BEFORE DELETE ON tenant_data_lifecycle_events
                    BEGIN SELECT RAISE(ABORT, 'tenant data lifecycle events are immutable'); END;
                CREATE TRIGGER tenant_deletions_no_delete
                    BEFORE DELETE ON tenant_deletion_requests
                    BEGIN SELECT RAISE(ABORT, 'tenant deletion requests are immutable projections'); END;
                SQL);
        }
    }

    private function removeDatabaseGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS tenant_deletions_no_delete ON tenant_deletion_requests;
                DROP TRIGGER IF EXISTS tenant_deletions_validate_transition ON tenant_deletion_requests;
                DROP FUNCTION IF EXISTS public.app_validate_tenant_deletion_transition();
                DROP TRIGGER IF EXISTS tenant_lifecycle_events_immutable ON tenant_data_lifecycle_events;
                DROP FUNCTION IF EXISTS public.app_reject_tenant_lifecycle_event_mutation();
                SQL);

            foreach (array_reverse(self::TENANT_TABLES) as $table) {
                DB::unprepared("DROP POLICY IF EXISTS {$table}_studio_isolation ON {$table}; ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY; ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;");
            }

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS tenant_deletions_no_delete;
                DROP TRIGGER IF EXISTS tenant_lifecycle_events_immutable_delete;
                DROP TRIGGER IF EXISTS tenant_lifecycle_events_immutable_update;
                SQL);
        }
    }
};
