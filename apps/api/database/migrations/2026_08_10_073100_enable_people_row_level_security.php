<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = [
        'households',
        'people',
        'household_members',
        'student_profiles',
        'guardian_relationships',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            DB::unprepared(<<<SQL
                ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
                CREATE POLICY {$table}_studio_isolation ON {$table}
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
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_reverse(self::TABLES) as $table) {
            DB::unprepared(<<<SQL
                DROP POLICY IF EXISTS {$table}_studio_isolation ON {$table};
                ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY;
                ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;
                SQL);
        }
    }
};
