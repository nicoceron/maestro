<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE studio_invitations ALTER COLUMN token_hash DROP NOT NULL');
        } else {
            Schema::table('studio_invitations', function (Blueprint $table): void {
                $table->char('token_hash', 64)->nullable()->change();
            });
        }

        Schema::table('studio_invitations', function (Blueprint $table): void {
            $table->ulid('lineage_id')->nullable()->after('studio_id');
            $table->unsignedInteger('delivery_version')->default(1)->after('lineage_id');
            $table->ulid('previous_invitation_id')->nullable()->after('delivery_version');
            $table->ulid('superseded_by_id')->nullable()->after('previous_invitation_id');
            $table->timestamp('superseded_at')->nullable()->after('revoked_at');
            $table->timestamp('last_sent_at')->nullable()->after('superseded_at');
            $table->unsignedInteger('send_count')->default(0)->after('last_sent_at');
            $table->timestamp('token_redacted_at')->nullable()->after('send_count');
            $table->unique(['id', 'studio_id'], 'studio_invitations_id_studio_unique');
        });

        DB::table('studio_invitations')->orderBy('id')->eachById(function (object $invitation): void {
            DB::table('studio_invitations')
                ->where('id', $invitation->id)
                ->update(['lineage_id' => $invitation->id]);
        }, column: 'id');

        Schema::table('studio_invitations', function (Blueprint $table): void {
            $table->ulid('lineage_id')->nullable(false)->change();
            $table->unique(
                ['studio_id', 'lineage_id', 'delivery_version'],
                'studio_invitations_lineage_version_unique',
            );
            $table->foreign(
                ['lineage_id', 'studio_id'],
                'studio_invitations_lineage_studio_fk',
            )->references(['id', 'studio_id'])->on('studio_invitations')->cascadeOnDelete();
            $table->foreign(
                ['previous_invitation_id', 'studio_id'],
                'studio_invitations_previous_studio_fk',
            )->references(['id', 'studio_id'])->on('studio_invitations')->cascadeOnDelete();
            $table->foreign(
                ['superseded_by_id', 'studio_id'],
                'studio_invitations_superseded_studio_fk',
            )->references(['id', 'studio_id'])->on('studio_invitations')->cascadeOnDelete();
        });

        Schema::create('studio_invitation_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->cascadeOnDelete();
            $table->ulid('invitation_id');
            $table->unsignedInteger('delivery_version');
            $table->enum('status', ['pending', 'sent', 'suppressed'])->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->string('suppression_reason', 32)->nullable();
            $table->timestamp('redacted_at')->nullable();
            $table->timestamps();

            $table->unique(['invitation_id', 'delivery_version'], 'invitation_delivery_version_unique');
            $table->index(['studio_id', 'status']);
            $table->foreign(
                ['invitation_id', 'studio_id'],
                'invitation_deliveries_invitation_studio_fk',
            )->references(['id', 'studio_id'])->on('studio_invitations')->cascadeOnDelete();
        });

        Schema::create('studio_audit_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->restrictOnDelete();
            $table->enum('event_type', [
                'invitation.created',
                'invitation.delivery_queued',
                'invitation.resent',
                'invitation.superseded',
                'invitation.delivered',
                'invitation.delivery_suppressed',
                'invitation.revoked',
                'invitation.accepted',
                'invitation.digest_redacted',
            ]);
            $table->enum('subject_type', ['studio_invitation'])->default('studio_invitation');
            $table->ulid('subject_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->char('request_ip_hash', 64)->nullable();
            $table->json('metadata');
            $table->timestamp('occurred_at');

            $table->index(['studio_id', 'occurred_at']);
            $table->index(['studio_id', 'event_type', 'occurred_at'], 'studio_audit_event_type_index');
            $table->unique(
                ['studio_id', 'subject_id', 'event_type'],
                'studio_audit_subject_event_unique',
            );
            $table->foreign(
                ['subject_id', 'studio_id'],
                'studio_audit_subject_invitation_fk',
            )->references(['id', 'studio_id'])->on('studio_invitations')->restrictOnDelete();
        });

        $this->installImmutabilityAndRowLevelSecurity();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP POLICY IF EXISTS studio_audit_events_insert ON studio_audit_events;
                DROP POLICY IF EXISTS studio_audit_events_select ON studio_audit_events;
                ALTER TABLE studio_audit_events NO FORCE ROW LEVEL SECURITY;
                ALTER TABLE studio_audit_events DISABLE ROW LEVEL SECURITY;
                DROP TRIGGER IF EXISTS studio_audit_events_immutable ON studio_audit_events;
                DROP FUNCTION IF EXISTS public.app_reject_studio_audit_mutation();
                DROP TRIGGER IF EXISTS studio_invitations_protect_lifecycle_update ON studio_invitations;
                DROP FUNCTION IF EXISTS public.app_protect_invitation_lifecycle_update();
                DROP FUNCTION IF EXISTS public.app_finalize_invitation_acceptance(char(26), char(26), char(26), varchar, char(64));

                DROP POLICY IF EXISTS studio_invitation_deliveries_delete ON studio_invitation_deliveries;
                DROP POLICY IF EXISTS studio_invitation_deliveries_update ON studio_invitation_deliveries;
                DROP POLICY IF EXISTS studio_invitation_deliveries_insert ON studio_invitation_deliveries;
                DROP POLICY IF EXISTS studio_invitation_deliveries_select ON studio_invitation_deliveries;
                ALTER TABLE studio_invitation_deliveries NO FORCE ROW LEVEL SECURITY;
                ALTER TABLE studio_invitation_deliveries DISABLE ROW LEVEL SECURITY;
                DROP FUNCTION IF EXISTS public.app_resolve_invitation_delivery_studio(char(26), integer);

                CREATE OR REPLACE FUNCTION public.app_can_insert_studio_membership(
                    target_studio_id char(26),
                    target_user_id bigint,
                    target_role varchar
                )
                RETURNS boolean
                LANGUAGE sql
                STABLE
                SECURITY DEFINER
                SET search_path = pg_catalog, public
                AS $$
                    SELECT
                        target_user_id = nullif(current_setting('app.current_user_id', true), '')::bigint
                        AND (
                            (
                                target_role = 'owner'
                                AND NOT EXISTS (
                                    SELECT 1 FROM public.studio_memberships AS existing_membership
                                    WHERE existing_membership.studio_id = target_studio_id
                                )
                            )
                            OR EXISTS (
                                SELECT 1
                                FROM public.studio_invitations AS invitation
                                INNER JOIN public.users AS invited_user ON invited_user.id = target_user_id
                                WHERE invitation.studio_id = target_studio_id
                                    AND invitation.email_normalized = invited_user.email
                                    AND invitation.role = target_role
                                    AND invitation.token_hash = nullif(
                                        current_setting('app.current_invitation_token_hash', true), ''
                                    )
                                    AND invitation.accepted_at IS NULL
                                    AND invitation.revoked_at IS NULL
                                    AND invitation.expires_at > CURRENT_TIMESTAMP
                            )
                        )
                $$;

                CREATE OR REPLACE FUNCTION public.app_protect_invitation_token_update()
                RETURNS trigger
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = pg_catalog, public
                AS $$
                DECLARE
                    current_user_id bigint := nullif(
                        current_setting('app.current_user_id', true), ''
                    )::bigint;
                    current_user_email varchar;
                BEGIN
                    IF session_user = pg_get_userbyid((
                        SELECT relowner FROM pg_class
                        WHERE oid = 'public.studio_invitations'::regclass
                    )) OR EXISTS (
                        SELECT 1 FROM pg_roles
                        WHERE rolname = session_user AND (rolsuper OR rolbypassrls)
                    ) THEN
                        RETURN NEW;
                    END IF;

                    IF OLD.studio_id IS NOT DISTINCT FROM nullif(
                        current_setting('app.current_studio_id', true), ''
                    )::char(26) THEN
                        RETURN NEW;
                    END IF;

                    SELECT email INTO current_user_email
                    FROM public.users
                    WHERE id = current_user_id;

                    IF current_user_email IS NULL
                        OR current_user_email IS DISTINCT FROM OLD.email_normalized
                        OR NEW.studio_id IS DISTINCT FROM OLD.studio_id
                        OR NEW.email_normalized IS DISTINCT FROM OLD.email_normalized
                        OR NEW.role IS DISTINCT FROM OLD.role
                        OR NEW.token_hash IS DISTINCT FROM OLD.token_hash
                        OR NEW.invited_by_id IS DISTINCT FROM OLD.invited_by_id
                        OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                        OR NEW.revoked_at IS DISTINCT FROM OLD.revoked_at
                        OR NEW.accepted_by_id IS DISTINCT FROM current_user_id
                        OR NEW.accepted_at IS NULL
                        OR NEW.pending_key IS NOT NULL THEN
                        RAISE EXCEPTION 'invitation bearer cannot modify authorization fields'
                            USING ERRCODE = '42501';
                    END IF;

                    RETURN NEW;
                END;
                $$;
                SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS studio_audit_events_immutable_update;');
            DB::unprepared('DROP TRIGGER IF EXISTS studio_audit_events_immutable_delete;');
            DB::unprepared('DROP TRIGGER IF EXISTS studio_invitation_deliveries_state_insert;');
            DB::unprepared('DROP TRIGGER IF EXISTS studio_invitation_deliveries_state_update;');
        }

        Schema::dropIfExists('studio_audit_events');
        Schema::dropIfExists('studio_invitation_deliveries');

        Schema::table('studio_invitations', function (Blueprint $table): void {
            $table->dropForeign('studio_invitations_lineage_studio_fk');
            $table->dropForeign('studio_invitations_previous_studio_fk');
            $table->dropForeign('studio_invitations_superseded_studio_fk');
            $table->dropUnique('studio_invitations_lineage_version_unique');
            $table->dropUnique('studio_invitations_id_studio_unique');
            $table->dropColumn([
                'lineage_id',
                'delivery_version',
                'previous_invitation_id',
                'superseded_by_id',
                'superseded_at',
                'last_sent_at',
                'send_count',
                'token_redacted_at',
            ]);
        });
    }

    private function installImmutabilityAndRowLevelSecurity(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER studio_audit_events_immutable_update
                BEFORE UPDATE ON studio_audit_events
                BEGIN
                    SELECT RAISE(ABORT, 'studio audit events are immutable');
                END;

                CREATE TRIGGER studio_audit_events_immutable_delete
                BEFORE DELETE ON studio_audit_events
                BEGIN
                    SELECT RAISE(ABORT, 'studio audit events are immutable');
                END;

                CREATE TRIGGER studio_invitation_deliveries_state_insert
                BEFORE INSERT ON studio_invitation_deliveries
                WHEN NOT (
                    (NEW.status = 'pending' AND NEW.sent_at IS NULL AND NEW.suppressed_at IS NULL AND NEW.suppression_reason IS NULL)
                    OR (NEW.status = 'sent' AND NEW.sent_at IS NOT NULL AND NEW.suppressed_at IS NULL AND NEW.suppression_reason IS NULL)
                    OR (NEW.status = 'suppressed' AND NEW.sent_at IS NULL AND NEW.suppressed_at IS NOT NULL AND NEW.suppression_reason IS NOT NULL)
                )
                BEGIN
                    SELECT RAISE(ABORT, 'invalid invitation delivery state');
                END;

                CREATE TRIGGER studio_invitation_deliveries_state_update
                BEFORE UPDATE ON studio_invitation_deliveries
                WHEN NOT (
                    (NEW.status = 'pending' AND NEW.sent_at IS NULL AND NEW.suppressed_at IS NULL AND NEW.suppression_reason IS NULL)
                    OR (NEW.status = 'sent' AND NEW.sent_at IS NOT NULL AND NEW.suppressed_at IS NULL AND NEW.suppression_reason IS NULL)
                    OR (NEW.status = 'suppressed' AND NEW.sent_at IS NULL AND NEW.suppressed_at IS NOT NULL AND NEW.suppression_reason IS NOT NULL)
                )
                BEGIN
                    SELECT RAISE(ABORT, 'invalid invitation delivery state');
                END;
                SQL);

            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_can_insert_studio_membership(
                target_studio_id char(26),
                target_user_id bigint,
                target_role varchar
            )
            RETURNS boolean
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
                SELECT
                    target_user_id = nullif(current_setting('app.current_user_id', true), '')::bigint
                    AND (
                        (
                            target_role = 'owner'
                            AND NOT EXISTS (
                                SELECT 1 FROM public.studio_memberships AS existing_membership
                                WHERE existing_membership.studio_id = target_studio_id
                            )
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM public.studio_invitations AS invitation
                            INNER JOIN public.users AS invited_user ON invited_user.id = target_user_id
                            WHERE invitation.studio_id = target_studio_id
                                AND invitation.email_normalized = invited_user.email
                                AND invitation.role = target_role
                                AND invitation.token_hash IS NOT NULL
                                AND invitation.token_hash = nullif(
                                    current_setting('app.current_invitation_token_hash', true), ''
                                )
                                AND invitation.accepted_at IS NULL
                                AND invitation.revoked_at IS NULL
                                AND invitation.superseded_at IS NULL
                                AND invitation.expires_at > CURRENT_TIMESTAMP
                        )
                    )
            $$;

            CREATE OR REPLACE FUNCTION public.app_protect_invitation_token_update()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
                current_user_id bigint := nullif(
                    current_setting('app.current_user_id', true), ''
                )::bigint;
                current_user_email varchar;
            BEGIN
                IF session_user = pg_get_userbyid((
                    SELECT relowner FROM pg_class
                    WHERE oid = 'public.studio_invitations'::regclass
                )) OR EXISTS (
                    SELECT 1 FROM pg_roles
                    WHERE rolname = session_user AND (rolsuper OR rolbypassrls)
                ) THEN
                    RETURN NEW;
                END IF;

                IF OLD.studio_id IS NOT DISTINCT FROM nullif(
                    current_setting('app.current_studio_id', true), ''
                )::char(26) THEN
                    RETURN NEW;
                END IF;

                SELECT email INTO current_user_email
                FROM public.users
                WHERE id = current_user_id;

                IF OLD.token_hash IS NULL
                    OR OLD.token_hash IS DISTINCT FROM nullif(
                        current_setting('app.current_invitation_token_hash', true), ''
                    )
                    OR OLD.accepted_at IS NOT NULL
                    OR OLD.revoked_at IS NOT NULL
                    OR OLD.superseded_at IS NOT NULL
                    OR OLD.expires_at <= CURRENT_TIMESTAMP
                    OR OLD.pending_key IS NULL
                    OR current_user_email IS NULL
                    OR current_user_email IS DISTINCT FROM OLD.email_normalized
                    OR NOT EXISTS (
                        SELECT 1
                        FROM public.studio_memberships AS membership
                        WHERE membership.studio_id = OLD.studio_id
                            AND membership.user_id = current_user_id
                            AND membership.role = OLD.role
                            AND membership.status = 'active'
                    )
                    OR NEW.studio_id IS DISTINCT FROM OLD.studio_id
                    OR NEW.email_normalized IS DISTINCT FROM OLD.email_normalized
                    OR NEW.role IS DISTINCT FROM OLD.role
                    OR NEW.token_hash IS DISTINCT FROM OLD.token_hash
                    OR NEW.invited_by_id IS DISTINCT FROM OLD.invited_by_id
                    OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                    OR NEW.revoked_at IS DISTINCT FROM OLD.revoked_at
                    OR NEW.accepted_by_id IS DISTINCT FROM current_user_id
                    OR NEW.accepted_at IS NULL
                    OR NEW.pending_key IS NOT NULL THEN
                    RAISE EXCEPTION 'invitation bearer cannot modify authorization fields'
                        USING ERRCODE = '42501';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.app_reject_studio_audit_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
                RAISE EXCEPTION 'studio audit events are immutable' USING ERRCODE = '55000';
            END;
            $$;

            CREATE TRIGGER studio_audit_events_immutable
            BEFORE UPDATE OR DELETE ON studio_audit_events
            FOR EACH ROW EXECUTE FUNCTION public.app_reject_studio_audit_mutation();

            CREATE OR REPLACE FUNCTION public.app_protect_invitation_lifecycle_update()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
                IF session_user = pg_get_userbyid((
                    SELECT relowner FROM pg_class
                    WHERE oid = 'public.studio_invitations'::regclass
                )) OR EXISTS (
                    SELECT 1 FROM pg_roles
                    WHERE rolname = session_user AND (rolsuper OR rolbypassrls)
                ) OR OLD.studio_id IS NOT DISTINCT FROM nullif(
                    current_setting('app.current_studio_id', true), ''
                )::char(26) THEN
                    RETURN NEW;
                END IF;

                IF NEW.lineage_id IS DISTINCT FROM OLD.lineage_id
                    OR NEW.delivery_version IS DISTINCT FROM OLD.delivery_version
                    OR NEW.previous_invitation_id IS DISTINCT FROM OLD.previous_invitation_id
                    OR NEW.superseded_by_id IS DISTINCT FROM OLD.superseded_by_id
                    OR NEW.superseded_at IS DISTINCT FROM OLD.superseded_at
                    OR NEW.last_sent_at IS DISTINCT FROM OLD.last_sent_at
                    OR NEW.send_count IS DISTINCT FROM OLD.send_count
                    OR NEW.token_redacted_at IS DISTINCT FROM OLD.token_redacted_at THEN
                    RAISE EXCEPTION 'invitation bearer cannot modify lifecycle fields'
                        USING ERRCODE = '42501';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER studio_invitations_protect_lifecycle_update
            BEFORE UPDATE ON studio_invitations
            FOR EACH ROW EXECUTE FUNCTION public.app_protect_invitation_lifecycle_update();

            ALTER TABLE studio_invitation_deliveries ENABLE ROW LEVEL SECURITY;
            ALTER TABLE studio_invitation_deliveries FORCE ROW LEVEL SECURITY;

            CREATE OR REPLACE FUNCTION public.app_resolve_invitation_delivery_studio(
                target_invitation_id char(26),
                target_delivery_version integer
            )
            RETURNS char(26)
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
                SELECT delivery.studio_id
                FROM public.studio_invitation_deliveries AS delivery
                WHERE delivery.invitation_id = target_invitation_id
                    AND delivery.delivery_version = target_delivery_version
                LIMIT 1
            $$;

            CREATE POLICY studio_invitation_deliveries_select ON studio_invitation_deliveries
                FOR SELECT USING (
                    studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26)
                );
            CREATE POLICY studio_invitation_deliveries_insert ON studio_invitation_deliveries
                FOR INSERT WITH CHECK (
                    studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26)
                );
            CREATE POLICY studio_invitation_deliveries_update ON studio_invitation_deliveries
                FOR UPDATE
                USING (
                    studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26)
                )
                WITH CHECK (
                    studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26)
                );
            CREATE POLICY studio_invitation_deliveries_delete ON studio_invitation_deliveries
                FOR DELETE USING (
                    studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26)
                );

            ALTER TABLE studio_audit_events ENABLE ROW LEVEL SECURITY;
            ALTER TABLE studio_audit_events FORCE ROW LEVEL SECURITY;
            CREATE POLICY studio_audit_events_select ON studio_audit_events
                FOR SELECT USING (
                    studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26)
                );
            CREATE POLICY studio_audit_events_insert ON studio_audit_events
                FOR INSERT WITH CHECK (
                    studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26)
                );

            CREATE OR REPLACE FUNCTION public.app_finalize_invitation_acceptance(
                target_invitation_id char(26),
                acceptance_event_id char(26),
                suppression_event_id char(26),
                safe_request_id varchar,
                safe_request_ip_hash char(64)
            )
            RETURNS void
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
                accepted_invitation public.studio_invitations%ROWTYPE;
                current_user_id bigint := nullif(current_setting('app.current_user_id', true), '')::bigint;
                previous_studio_id text := coalesce(current_setting('app.current_studio_id', true), '');
                suppressed_version integer;
            BEGIN
                IF safe_request_id <> ''
                    AND safe_request_id !~ '^[0-9A-HJKMNP-TV-Z]{26}$' THEN
                    RAISE EXCEPTION 'invalid audit request id' USING ERRCODE = '22023';
                END IF;

                IF safe_request_ip_hash <> ''
                    AND safe_request_ip_hash !~ '^[0-9a-f]{64}$' THEN
                    RAISE EXCEPTION 'invalid audit request IP hash' USING ERRCODE = '22023';
                END IF;

                SELECT invitation.* INTO accepted_invitation
                FROM public.studio_invitations AS invitation
                WHERE invitation.id = target_invitation_id
                    AND invitation.token_hash = nullif(
                        current_setting('app.current_invitation_token_hash', true), ''
                    )
                    AND invitation.accepted_by_id = current_user_id
                    AND invitation.accepted_at IS NOT NULL
                    AND invitation.email_normalized = (
                        SELECT email FROM public.users WHERE id = current_user_id
                    )
                    AND EXISTS (
                        SELECT 1
                        FROM public.studio_memberships AS membership
                        WHERE membership.studio_id = invitation.studio_id
                            AND membership.user_id = current_user_id
                            AND membership.role = invitation.role
                            AND membership.status = 'active'
                    );

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'invitation acceptance context is invalid' USING ERRCODE = '42501';
                END IF;

                PERFORM set_config('app.current_studio_id', accepted_invitation.studio_id, true);

                UPDATE public.studio_invitation_deliveries
                SET status = 'suppressed',
                    suppressed_at = CURRENT_TIMESTAMP,
                    suppression_reason = 'accepted',
                    updated_at = CURRENT_TIMESTAMP
                WHERE invitation_id = accepted_invitation.id
                    AND delivery_version = accepted_invitation.delivery_version
                    AND status = 'pending'
                RETURNING delivery_version INTO suppressed_version;

                IF suppressed_version IS NOT NULL THEN
                    INSERT INTO public.studio_audit_events (
                        id, studio_id, event_type, subject_type, subject_id, actor_id,
                        request_id, request_ip_hash, metadata, occurred_at
                    ) VALUES (
                        suppression_event_id, accepted_invitation.studio_id,
                        'invitation.delivery_suppressed', 'studio_invitation',
                        accepted_invitation.id, current_user_id, nullif(safe_request_id, ''),
                        nullif(safe_request_ip_hash, ''),
                        json_build_object(
                            'delivery_version', suppressed_version,
                            'reason', 'accepted'
                        ), CURRENT_TIMESTAMP
                    );
                END IF;

                INSERT INTO public.studio_audit_events (
                    id, studio_id, event_type, subject_type, subject_id, actor_id,
                    request_id, request_ip_hash, metadata, occurred_at
                ) VALUES (
                    acceptance_event_id, accepted_invitation.studio_id,
                    'invitation.accepted', 'studio_invitation', accepted_invitation.id,
                    current_user_id, nullif(safe_request_id, ''),
                    nullif(safe_request_ip_hash, ''),
                    json_build_object(
                        'role', accepted_invitation.role,
                        'delivery_version', accepted_invitation.delivery_version
                    ), CURRENT_TIMESTAMP
                );

                PERFORM set_config('app.current_studio_id', previous_studio_id, true);
            END;
            $$;

            ALTER TABLE studio_invitation_deliveries
                ADD CONSTRAINT studio_invitation_delivery_state_check CHECK (
                    (status = 'pending' AND sent_at IS NULL AND suppressed_at IS NULL AND suppression_reason IS NULL)
                    OR (status = 'sent' AND sent_at IS NOT NULL AND suppressed_at IS NULL AND suppression_reason IS NULL)
                    OR (status = 'suppressed' AND sent_at IS NULL AND suppressed_at IS NOT NULL AND suppression_reason IS NOT NULL)
                );
            ALTER TABLE studio_audit_events
                ADD CONSTRAINT studio_audit_metadata_object_check CHECK (
                    jsonb_typeof(metadata::jsonb) = 'object'
                );
            SQL);
    }
};
