<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const TENANT_TABLES = [
        'tenant_audit_streams',
        'tenant_audit_events',
        'transactional_outbox_aggregates',
        'transactional_outbox_messages',
        'support_access_grants',
        'support_access_sessions',
    ];

    public function up(): void
    {
        Schema::create('tenant_audit_streams', function (Blueprint $table): void {
            $table->foreignUlid('studio_id')->primary()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->char('last_hash', 64)->nullable();
            $table->timestamp('updated_at');
        });

        Schema::create('tenant_audit_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('stream_sequence');
            $table->string('event_type', 120);
            $table->string('subject_type', 100);
            $table->string('subject_id', 128)->nullable();
            $table->string('actor_type', 24);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_display', 160)->nullable();
            $table->ulid('support_session_id')->nullable();
            $table->ulid('request_id')->nullable();
            $table->ulid('correlation_id');
            $table->ulid('causation_id')->nullable();
            $table->string('request_method', 12)->nullable();
            $table->char('request_ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->unsignedSmallInteger('payload_version')->default(1);
            $table->json('payload');
            $table->char('previous_hash', 64)->nullable();
            $table->char('integrity_hash', 64);
            $table->timestamp('occurred_at');

            $table->unique(['studio_id', 'stream_sequence'], 'tenant_audit_stream_sequence_unique');
            $table->unique(['studio_id', 'integrity_hash'], 'tenant_audit_integrity_hash_unique');
            $table->index(['studio_id', 'occurred_at'], 'tenant_audit_occurred_index');
            $table->index(['studio_id', 'event_type', 'occurred_at'], 'tenant_audit_event_type_index');
            $table->index(['studio_id', 'subject_type', 'subject_id'], 'tenant_audit_subject_index');
            $table->index(['studio_id', 'correlation_id'], 'tenant_audit_correlation_index');
        });

        Schema::create('transactional_outbox_aggregates', function (Blueprint $table): void {
            $table->foreignUlid('studio_id')->constrained()->restrictOnDelete();
            $table->string('aggregate_type', 100);
            $table->string('aggregate_id', 128);
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->timestamp('updated_at');
            $table->primary(['studio_id', 'aggregate_type', 'aggregate_id'], 'outbox_aggregates_primary');
        });

        Schema::create('transactional_outbox_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->restrictOnDelete();
            $table->string('topic', 120);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('aggregate_type', 100);
            $table->string('aggregate_id', 128);
            $table->unsignedBigInteger('aggregate_sequence');
            $table->string('idempotency_key', 160);
            $table->ulid('correlation_id');
            $table->text('payload_ciphertext');
            $table->char('payload_hash', 64);
            $table->string('status', 24)->default('pending');
            $table->timestamp('available_at');
            $table->timestamp('claimed_at')->nullable();
            $table->string('claimed_by', 100)->nullable();
            $table->ulid('claim_token')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(8);
            $table->string('last_error_code', 80)->nullable();
            $table->string('last_error_summary', 160)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('dead_lettered_at')->nullable();
            $table->timestamps();

            $table->unique(['studio_id', 'topic', 'idempotency_key'], 'outbox_idempotency_unique');
            $table->unique(['studio_id', 'aggregate_type', 'aggregate_id', 'aggregate_sequence'], 'outbox_aggregate_sequence_unique');
            $table->index(['studio_id', 'status', 'available_at'], 'outbox_due_index');
            $table->index(['status', 'claimed_at'], 'outbox_recovery_index');
            $table->index(['studio_id', 'aggregate_type', 'aggregate_id'], 'outbox_aggregate_index');
        });

        Schema::create('platform_support_operators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->json('capabilities');
            $table->boolean('active')->default(true);
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('support_access_grants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 160);
            $table->json('scopes');
            $table->string('reason', 500);
            $table->string('status', 24)->default('requested');
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->ulid('request_id')->nullable();
            $table->ulid('correlation_id');
            $table->timestamps();

            $table->unique(['id', 'studio_id'], 'support_grants_id_studio_unique');
            $table->unique(['requested_by_user_id', 'idempotency_key'], 'support_grants_request_idempotency_unique');
            $table->index(['studio_id', 'status', 'expires_at'], 'support_grants_studio_status_index');
            $table->index(['requested_by_user_id', 'status', 'expires_at'], 'support_grants_requester_index');
        });

        Schema::create('support_access_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->restrictOnDelete();
            $table->ulid('grant_id');
            $table->foreignId('support_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->json('scopes');
            $table->string('reason', 500);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('recent_auth_at');
            $table->timestamp('mfa_verified_at');
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 80)->nullable();
            $table->timestamps();

            $table->unique(['id', 'studio_id'], 'support_sessions_id_studio_unique');
            $table->foreign(['grant_id', 'studio_id'], 'support_sessions_grant_studio_fk')
                ->references(['id', 'studio_id'])->on('support_access_grants')->restrictOnDelete();
            $table->index(['studio_id', 'expires_at', 'ended_at'], 'support_sessions_studio_active_index');
            $table->index(['support_user_id', 'expires_at', 'ended_at'], 'support_sessions_user_active_index');
        });

        Schema::table('tenant_audit_events', function (Blueprint $table): void {
            $table->foreign(['support_session_id', 'studio_id'], 'tenant_audit_support_session_fk')
                ->references(['id', 'studio_id'])->on('support_access_sessions')->restrictOnDelete();
        });

        $this->installConstraintsAndImmutability();
        $this->installPostgresRowLevelSecurity();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (array_reverse(self::TENANT_TABLES) as $table) {
                DB::unprepared("DROP POLICY IF EXISTS {$table}_support_select ON {$table}; DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}; ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY; ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;");
            }
            DB::unprepared(<<<'SQL'
                DROP POLICY IF EXISTS platform_support_operators_self_select ON platform_support_operators;
                ALTER TABLE platform_support_operators NO FORCE ROW LEVEL SECURITY;
                ALTER TABLE platform_support_operators DISABLE ROW LEVEL SECURITY;
                DROP TRIGGER IF EXISTS tenant_audit_events_immutable ON tenant_audit_events;
                DROP TRIGGER IF EXISTS support_grants_protect_fields ON support_access_grants;
                DROP TRIGGER IF EXISTS support_sessions_protect_fields ON support_access_sessions;
                DROP TRIGGER IF EXISTS outbox_protect_fields ON transactional_outbox_messages;
                DROP FUNCTION IF EXISTS public.app_reject_tenant_audit_mutation();
                DROP FUNCTION IF EXISTS public.app_protect_support_grant_fields();
                DROP FUNCTION IF EXISTS public.app_protect_support_session_fields();
                DROP FUNCTION IF EXISTS public.app_protect_outbox_fields();
                DROP FUNCTION IF EXISTS public.app_is_active_support_operator(bigint);
                DROP FUNCTION IF EXISTS public.app_support_session_can_access(char(26), char(26), varchar);
                SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach ([
                'tenant_audit_events_immutable_update', 'tenant_audit_events_immutable_delete',
                'support_access_grants_immutable_identity', 'support_access_grants_no_delete',
                'support_access_sessions_immutable_identity', 'support_access_sessions_no_delete',
                'transactional_outbox_immutable_payload',
            ] as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger};");
            }
        }

        Schema::dropIfExists('tenant_audit_events');
        Schema::dropIfExists('support_access_sessions');
        Schema::dropIfExists('support_access_grants');
        Schema::dropIfExists('platform_support_operators');
        Schema::dropIfExists('transactional_outbox_messages');
        Schema::dropIfExists('transactional_outbox_aggregates');
        Schema::dropIfExists('tenant_audit_streams');
    }

    private function installConstraintsAndImmutability(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER tenant_audit_events_immutable_update BEFORE UPDATE ON tenant_audit_events
                BEGIN SELECT RAISE(ABORT, 'tenant audit events are immutable'); END;
                CREATE TRIGGER tenant_audit_events_immutable_delete BEFORE DELETE ON tenant_audit_events
                BEGIN SELECT RAISE(ABORT, 'tenant audit events are immutable'); END;

                CREATE TRIGGER support_access_grants_immutable_identity BEFORE UPDATE ON support_access_grants
                WHEN NEW.studio_id <> OLD.studio_id
                    OR NEW.requested_by_user_id <> OLD.requested_by_user_id
                    OR NEW.idempotency_key <> OLD.idempotency_key
                    OR NEW.scopes <> OLD.scopes OR NEW.reason <> OLD.reason
                    OR NEW.starts_at <> OLD.starts_at OR NEW.expires_at <> OLD.expires_at
                    OR NEW.correlation_id <> OLD.correlation_id OR NEW.request_id IS NOT OLD.request_id
                BEGIN SELECT RAISE(ABORT, 'support access grant identity is immutable'); END;
                CREATE TRIGGER support_access_grants_no_delete BEFORE DELETE ON support_access_grants
                BEGIN SELECT RAISE(ABORT, 'support access grants cannot be deleted'); END;

                CREATE TRIGGER support_access_sessions_immutable_identity BEFORE UPDATE ON support_access_sessions
                WHEN NEW.studio_id <> OLD.studio_id OR NEW.grant_id <> OLD.grant_id
                    OR NEW.support_user_id <> OLD.support_user_id OR NEW.approved_by_user_id <> OLD.approved_by_user_id
                    OR NEW.scopes <> OLD.scopes OR NEW.reason <> OLD.reason OR NEW.token_hash <> OLD.token_hash
                    OR NEW.recent_auth_at <> OLD.recent_auth_at OR NEW.mfa_verified_at <> OLD.mfa_verified_at
                    OR NEW.started_at <> OLD.started_at OR NEW.expires_at <> OLD.expires_at
                BEGIN SELECT RAISE(ABORT, 'support access session identity is immutable'); END;
                CREATE TRIGGER support_access_sessions_no_delete BEFORE DELETE ON support_access_sessions
                BEGIN SELECT RAISE(ABORT, 'support access sessions cannot be deleted'); END;

                CREATE TRIGGER transactional_outbox_immutable_payload BEFORE UPDATE ON transactional_outbox_messages
                WHEN NEW.studio_id <> OLD.studio_id OR NEW.topic <> OLD.topic
                    OR NEW.schema_version <> OLD.schema_version OR NEW.aggregate_type <> OLD.aggregate_type
                    OR NEW.aggregate_id <> OLD.aggregate_id OR NEW.aggregate_sequence <> OLD.aggregate_sequence
                    OR NEW.idempotency_key <> OLD.idempotency_key
                    OR NEW.correlation_id <> OLD.correlation_id
                    OR NEW.payload_ciphertext <> OLD.payload_ciphertext OR NEW.payload_hash <> OLD.payload_hash
                    OR NEW.max_attempts <> OLD.max_attempts
                BEGIN SELECT RAISE(ABORT, 'outbox message payload is immutable'); END;
                SQL);

            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE tenant_audit_events ADD CONSTRAINT tenant_audit_actor_type_check
                CHECK (actor_type IN ('user', 'support', 'system'));
            ALTER TABLE tenant_audit_events ADD CONSTRAINT tenant_audit_payload_object_check
                CHECK (jsonb_typeof(payload::jsonb) = 'object');
            ALTER TABLE tenant_audit_events ADD CONSTRAINT tenant_audit_hash_format_check
                CHECK (integrity_hash ~ '^[0-9a-f]{64}$' AND (previous_hash IS NULL OR previous_hash ~ '^[0-9a-f]{64}$'));
            ALTER TABLE transactional_outbox_messages ADD CONSTRAINT transactional_outbox_state_check
                CHECK (status IN ('pending', 'processing', 'retry', 'processed', 'dead_letter'));
            ALTER TABLE transactional_outbox_messages ADD CONSTRAINT transactional_outbox_attempts_check
                CHECK (max_attempts BETWEEN 1 AND 25 AND attempts <= max_attempts);
            ALTER TABLE support_access_grants ADD CONSTRAINT support_access_grant_status_check
                CHECK (status IN ('requested', 'approved', 'rejected', 'revoked', 'expired'));
            ALTER TABLE support_access_grants ADD CONSTRAINT support_access_grant_window_check
                CHECK (expires_at > starts_at AND expires_at <= starts_at + INTERVAL '4 hours');
            ALTER TABLE support_access_grants ADD CONSTRAINT support_access_grant_scopes_check
                CHECK (jsonb_typeof(scopes::jsonb) = 'array' AND jsonb_array_length(scopes::jsonb) BETWEEN 1 AND 10);
            ALTER TABLE support_access_sessions ADD CONSTRAINT support_access_session_window_check
                CHECK (expires_at > started_at AND expires_at <= started_at + INTERVAL '2 hours');
            ALTER TABLE support_access_sessions ADD CONSTRAINT support_access_session_terminal_check
                CHECK ((ended_at IS NULL AND end_reason IS NULL) OR (ended_at IS NOT NULL AND end_reason IS NOT NULL));

            CREATE OR REPLACE FUNCTION public.app_reject_tenant_audit_mutation() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN RAISE EXCEPTION 'tenant audit events are immutable' USING ERRCODE = '55000'; END;
            $$;
            CREATE TRIGGER tenant_audit_events_immutable BEFORE UPDATE OR DELETE ON tenant_audit_events
                FOR EACH ROW EXECUTE FUNCTION public.app_reject_tenant_audit_mutation();

            CREATE OR REPLACE FUNCTION public.app_protect_support_grant_fields() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'support access grants cannot be deleted' USING ERRCODE = '55000'; END IF;
                IF NEW.studio_id IS DISTINCT FROM OLD.studio_id
                    OR NEW.requested_by_user_id IS DISTINCT FROM OLD.requested_by_user_id
                    OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                    OR NEW.scopes::jsonb IS DISTINCT FROM OLD.scopes::jsonb OR NEW.reason IS DISTINCT FROM OLD.reason
                    OR NEW.starts_at IS DISTINCT FROM OLD.starts_at OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                    OR NEW.correlation_id IS DISTINCT FROM OLD.correlation_id OR NEW.request_id IS DISTINCT FROM OLD.request_id
                THEN RAISE EXCEPTION 'support access grant identity is immutable' USING ERRCODE = '55000'; END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER support_grants_protect_fields BEFORE UPDATE OR DELETE ON support_access_grants
                FOR EACH ROW EXECUTE FUNCTION public.app_protect_support_grant_fields();

            CREATE OR REPLACE FUNCTION public.app_protect_support_session_fields() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'support access sessions cannot be deleted' USING ERRCODE = '55000'; END IF;
                IF NEW.studio_id IS DISTINCT FROM OLD.studio_id OR NEW.grant_id IS DISTINCT FROM OLD.grant_id
                    OR NEW.support_user_id IS DISTINCT FROM OLD.support_user_id OR NEW.approved_by_user_id IS DISTINCT FROM OLD.approved_by_user_id
                    OR NEW.scopes::jsonb IS DISTINCT FROM OLD.scopes::jsonb OR NEW.reason IS DISTINCT FROM OLD.reason
                    OR NEW.token_hash IS DISTINCT FROM OLD.token_hash OR NEW.recent_auth_at IS DISTINCT FROM OLD.recent_auth_at
                    OR NEW.mfa_verified_at IS DISTINCT FROM OLD.mfa_verified_at OR NEW.started_at IS DISTINCT FROM OLD.started_at
                    OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                THEN RAISE EXCEPTION 'support access session identity is immutable' USING ERRCODE = '55000'; END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER support_sessions_protect_fields BEFORE UPDATE OR DELETE ON support_access_sessions
                FOR EACH ROW EXECUTE FUNCTION public.app_protect_support_session_fields();

            CREATE OR REPLACE FUNCTION public.app_protect_outbox_fields() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF NEW.studio_id IS DISTINCT FROM OLD.studio_id OR NEW.topic IS DISTINCT FROM OLD.topic
                    OR NEW.schema_version IS DISTINCT FROM OLD.schema_version OR NEW.aggregate_type IS DISTINCT FROM OLD.aggregate_type
                    OR NEW.aggregate_id IS DISTINCT FROM OLD.aggregate_id OR NEW.aggregate_sequence IS DISTINCT FROM OLD.aggregate_sequence
                    OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                    OR NEW.correlation_id IS DISTINCT FROM OLD.correlation_id
                    OR NEW.payload_ciphertext IS DISTINCT FROM OLD.payload_ciphertext OR NEW.payload_hash IS DISTINCT FROM OLD.payload_hash
                    OR NEW.max_attempts IS DISTINCT FROM OLD.max_attempts
                THEN RAISE EXCEPTION 'outbox message payload is immutable' USING ERRCODE = '55000'; END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER outbox_protect_fields BEFORE UPDATE ON transactional_outbox_messages
                FOR EACH ROW EXECUTE FUNCTION public.app_protect_outbox_fields();
            SQL);
    }

    private function installPostgresRowLevelSecurity(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_is_active_support_operator(target_user_id bigint) RETURNS boolean
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
                SELECT EXISTS (
                    SELECT 1 FROM public.platform_support_operators
                    WHERE user_id = target_user_id AND active = true
                )
            $$;

            CREATE OR REPLACE FUNCTION public.app_support_session_can_access(
                target_studio_id char(26), target_session_id char(26), required_scope varchar
            ) RETURNS boolean
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
                SELECT EXISTS (
                    SELECT 1 FROM public.support_access_sessions AS session
                    INNER JOIN public.support_access_grants AS access_grant ON access_grant.id = session.grant_id AND access_grant.studio_id = session.studio_id
                    WHERE session.id = target_session_id AND session.studio_id = target_studio_id
                        AND session.support_user_id = nullif(current_setting('app.current_user_id', true), '')::bigint
                        AND session.ended_at IS NULL AND session.expires_at > CURRENT_TIMESTAMP
                        AND access_grant.status = 'approved' AND access_grant.revoked_at IS NULL AND access_grant.expires_at > CURRENT_TIMESTAMP
                        AND session.scopes::jsonb ? required_scope
                )
            $$;

            ALTER TABLE platform_support_operators ENABLE ROW LEVEL SECURITY;
            ALTER TABLE platform_support_operators FORCE ROW LEVEL SECURITY;
            CREATE POLICY platform_support_operators_self_select ON platform_support_operators FOR SELECT USING (
                user_id = nullif(current_setting('app.current_user_id', true), '')::bigint
            );
            SQL);

        foreach (self::TENANT_TABLES as $table) {
            $supportSelectClause = match ($table) {
                'tenant_audit_events' => "public.app_support_session_can_access(studio_id, nullif(current_setting('app.current_support_session_id', true), '')::char(26), 'audit.read')",
                'support_access_grants' => "requested_by_user_id = nullif(current_setting('app.current_user_id', true), '')::bigint AND public.app_is_active_support_operator(requested_by_user_id)",
                'support_access_sessions' => "support_user_id = nullif(current_setting('app.current_user_id', true), '')::bigint AND public.app_is_active_support_operator(support_user_id)",
                default => '',
            };

            DB::unprepared(<<<SQL
                ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                    USING (studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26))
                    WITH CHECK (studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26));
                SQL);

            if ($supportSelectClause !== '') {
                DB::unprepared(<<<SQL
                    CREATE POLICY {$table}_support_select ON {$table} FOR SELECT
                        USING ({$supportSelectClause});
                    SQL);
            }
        }
    }
};
