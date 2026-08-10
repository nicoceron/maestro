-- Local-development runtime role. Migrations continue to run as the database
-- owner; the application can use this restricted role to make RLS effective.
DO $do$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'maestro_runtime') THEN
        CREATE ROLE maestro_runtime
            LOGIN
            PASSWORD 'maestro_runtime'
            NOSUPERUSER
            NOCREATEDB
            NOCREATEROLE
            NOINHERIT
            NOBYPASSRLS;
    END IF;
END
$do$;

SELECT format('GRANT CONNECT ON DATABASE %I TO maestro_runtime', current_database()) \gexec
GRANT USAGE ON SCHEMA public TO maestro_runtime;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO maestro_runtime;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO maestro_runtime;

ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO maestro_runtime;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO maestro_runtime;
