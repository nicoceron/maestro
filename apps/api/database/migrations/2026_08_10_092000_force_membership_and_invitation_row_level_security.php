<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX studio_memberships_one_active_owner_per_studio
            ON studio_memberships (studio_id)
            WHERE role = 'owner' AND status = 'active';

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
                                SELECT 1
                                FROM public.studio_memberships AS existing_membership
                                WHERE existing_membership.studio_id = target_studio_id
                            )
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM public.studio_invitations AS invitation
                            INNER JOIN public.users AS invited_user
                                ON invited_user.id = target_user_id
                            WHERE invitation.studio_id = target_studio_id
                                AND invitation.email_normalized = invited_user.email
                                AND invitation.token_hash = nullif(
                                    current_setting('app.current_invitation_token_hash', true),
                                    ''
                                )
                                AND invitation.accepted_at IS NULL
                                AND invitation.revoked_at IS NULL
                                AND invitation.expires_at > CURRENT_TIMESTAMP
                        )
                    )
            $$;

            CREATE OR REPLACE FUNCTION public.app_protect_own_membership_update()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
                IF session_user = pg_get_userbyid((
                    SELECT relowner
                    FROM pg_class
                    WHERE oid = 'public.studio_memberships'::regclass
                )) OR EXISTS (
                    SELECT 1
                    FROM pg_roles
                    WHERE rolname = session_user
                        AND (rolsuper OR rolbypassrls)
                ) THEN
                    RETURN NEW;
                END IF;

                IF OLD.studio_id IS DISTINCT FROM nullif(
                    current_setting('app.current_studio_id', true),
                    ''
                )::char(26)
                AND (
                    NEW.studio_id IS DISTINCT FROM OLD.studio_id
                    OR NEW.user_id IS DISTINCT FROM OLD.user_id
                    OR NEW.role IS DISTINCT FROM OLD.role
                    OR NEW.status IS DISTINCT FROM OLD.status
                    OR NEW.joined_at IS DISTINCT FROM OLD.joined_at
                ) THEN
                    RAISE EXCEPTION 'membership authorization fields require an active studio context'
                        USING ERRCODE = '42501';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER studio_memberships_protect_own_update
            BEFORE UPDATE ON studio_memberships
            FOR EACH ROW
            EXECUTE FUNCTION public.app_protect_own_membership_update();

            CREATE OR REPLACE FUNCTION public.app_protect_invitation_token_update()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
                current_user_id bigint := nullif(
                    current_setting('app.current_user_id', true),
                    ''
                )::bigint;
                current_user_email varchar;
            BEGIN
                IF session_user = pg_get_userbyid((
                    SELECT relowner
                    FROM pg_class
                    WHERE oid = 'public.studio_invitations'::regclass
                )) OR EXISTS (
                    SELECT 1
                    FROM pg_roles
                    WHERE rolname = session_user
                        AND (rolsuper OR rolbypassrls)
                ) THEN
                    RETURN NEW;
                END IF;

                IF OLD.studio_id IS NOT DISTINCT FROM nullif(
                    current_setting('app.current_studio_id', true),
                    ''
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

            CREATE TRIGGER studio_invitations_protect_token_update
            BEFORE UPDATE ON studio_invitations
            FOR EACH ROW
            EXECUTE FUNCTION public.app_protect_invitation_token_update();

            ALTER TABLE studio_memberships ENABLE ROW LEVEL SECURITY;
            ALTER TABLE studio_memberships FORCE ROW LEVEL SECURITY;

            CREATE POLICY studio_memberships_select ON studio_memberships
                FOR SELECT
                USING (
                    user_id = nullif(
                        current_setting('app.current_user_id', true),
                        ''
                    )::bigint
                    OR studio_id = nullif(
                        current_setting('app.current_studio_id', true),
                        ''
                    )::char(26)
                );

            CREATE POLICY studio_memberships_insert ON studio_memberships
                FOR INSERT
                WITH CHECK (
                    studio_id = nullif(
                        current_setting('app.current_studio_id', true),
                        ''
                    )::char(26)
                    OR public.app_can_insert_studio_membership(studio_id, user_id, role)
                );

            CREATE POLICY studio_memberships_update ON studio_memberships
                FOR UPDATE
                USING (
                    user_id = nullif(
                        current_setting('app.current_user_id', true),
                        ''
                    )::bigint
                    OR studio_id = nullif(
                        current_setting('app.current_studio_id', true),
                        ''
                    )::char(26)
                )
                WITH CHECK (
                    user_id = nullif(
                        current_setting('app.current_user_id', true),
                        ''
                    )::bigint
                    OR studio_id = nullif(
                        current_setting('app.current_studio_id', true),
                        ''
                    )::char(26)
                );

            CREATE POLICY studio_memberships_delete ON studio_memberships
                FOR DELETE
                USING (
                    studio_id = nullif(
                        current_setting('app.current_studio_id', true),
                        ''
                    )::char(26)
                );

            ALTER TABLE studio_invitations ENABLE ROW LEVEL SECURITY;
            ALTER TABLE studio_invitations FORCE ROW LEVEL SECURITY;

            CREATE POLICY studio_invitations_select ON studio_invitations
                FOR SELECT
                USING (
                    studio_id = nullif(
                        current_setting('app.current_studio_id', true),
                        ''
                    )::char(26)
                    OR token_hash = nullif(
                        current_setting('app.current_invitation_token_hash', true),
                        ''
                    )
                );

            CREATE POLICY studio_invitations_insert ON studio_invitations
                FOR INSERT
                WITH CHECK (
                    studio_id = nullif(
                        current_setting('app.current_studio_id', true),
                        ''
                    )::char(26)
                );

            CREATE POLICY studio_invitations_tenant_update ON studio_invitations
                FOR UPDATE
                USING (
                    studio_id = nullif(
                        current_setting('app.current_studio_id', true),
                        ''
                    )::char(26)
                )
                WITH CHECK (
                    studio_id = nullif(
                        current_setting('app.current_studio_id', true),
                        ''
                    )::char(26)
                );

            CREATE POLICY studio_invitations_accept_update ON studio_invitations
                FOR UPDATE
                USING (
                    token_hash = nullif(
                        current_setting('app.current_invitation_token_hash', true),
                        ''
                    )
                    AND email_normalized = (
                        SELECT email
                        FROM users
                        WHERE id = nullif(
                            current_setting('app.current_user_id', true),
                            ''
                        )::bigint
                    )
                )
                WITH CHECK (
                    token_hash = nullif(
                        current_setting('app.current_invitation_token_hash', true),
                        ''
                    )
                    AND email_normalized = (
                        SELECT email
                        FROM users
                        WHERE id = nullif(
                            current_setting('app.current_user_id', true),
                            ''
                        )::bigint
                    )
                    AND accepted_by_id = nullif(
                        current_setting('app.current_user_id', true),
                        ''
                    )::bigint
                );

            CREATE POLICY studio_invitations_delete ON studio_invitations
                FOR DELETE
                USING (
                    studio_id = nullif(
                        current_setting('app.current_studio_id', true),
                        ''
                    )::char(26)
                );
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS studio_invitations_delete ON studio_invitations;
            DROP POLICY IF EXISTS studio_invitations_accept_update ON studio_invitations;
            DROP POLICY IF EXISTS studio_invitations_tenant_update ON studio_invitations;
            DROP POLICY IF EXISTS studio_invitations_insert ON studio_invitations;
            DROP POLICY IF EXISTS studio_invitations_select ON studio_invitations;
            ALTER TABLE studio_invitations NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE studio_invitations DISABLE ROW LEVEL SECURITY;
            DROP TRIGGER IF EXISTS studio_invitations_protect_token_update ON studio_invitations;
            DROP FUNCTION IF EXISTS public.app_protect_invitation_token_update();

            DROP POLICY IF EXISTS studio_memberships_delete ON studio_memberships;
            DROP POLICY IF EXISTS studio_memberships_update ON studio_memberships;
            DROP POLICY IF EXISTS studio_memberships_insert ON studio_memberships;
            DROP POLICY IF EXISTS studio_memberships_select ON studio_memberships;
            ALTER TABLE studio_memberships NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE studio_memberships DISABLE ROW LEVEL SECURITY;
            DROP INDEX IF EXISTS studio_memberships_one_active_owner_per_studio;

            DROP TRIGGER IF EXISTS studio_memberships_protect_own_update ON studio_memberships;
            DROP FUNCTION IF EXISTS public.app_protect_own_membership_update();
            DROP FUNCTION IF EXISTS public.app_can_insert_studio_membership(char(26), bigint, varchar);
            SQL);
    }
};
