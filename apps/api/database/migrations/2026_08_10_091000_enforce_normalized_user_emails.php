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
            CREATE OR REPLACE FUNCTION public.normalize_user_email_value(value text)
            RETURNS text
            LANGUAGE sql
            IMMUTABLE
            PARALLEL SAFE
            RETURN lower(normalize(btrim(value, U&'\0009\000A\000B\000C\000D\0020\0085\00A0\1680\2000\2001\2002\2003\2004\2005\2006\2007\2008\2009\200A\2028\2029\202F\205F\3000'), NFKC))
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.normalize_user_email_before_write()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
                NEW.email := public.normalize_user_email_value(NEW.email);

                RETURN NEW;
            END;
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER users_normalize_email_before_write
            BEFORE INSERT OR UPDATE OF email ON users
            FOR EACH ROW
            EXECUTE FUNCTION public.normalize_user_email_before_write()
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX users_email_normalized_unique
            ON users (public.normalize_user_email_value(email))
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS users_email_normalized_unique');
        DB::statement('DROP TRIGGER IF EXISTS users_normalize_email_before_write ON users');
        DB::statement('DROP FUNCTION IF EXISTS public.normalize_user_email_before_write()');
        DB::statement('DROP FUNCTION IF EXISTS public.normalize_user_email_value(text)');
    }
};
