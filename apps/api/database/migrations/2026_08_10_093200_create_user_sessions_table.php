<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('session_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('last_seen_at');

            $table->index(['user_id', 'last_seen_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE user_sessions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE user_sessions FORCE ROW LEVEL SECURITY;

            CREATE POLICY user_sessions_select ON user_sessions
                FOR SELECT
                USING (
                    user_id = nullif(current_setting('app.current_user_id', true), '')::bigint
                );

            CREATE POLICY user_sessions_insert ON user_sessions
                FOR INSERT
                WITH CHECK (
                    user_id = nullif(current_setting('app.current_user_id', true), '')::bigint
                );

            CREATE POLICY user_sessions_update ON user_sessions
                FOR UPDATE
                USING (
                    user_id = nullif(current_setting('app.current_user_id', true), '')::bigint
                )
                WITH CHECK (
                    user_id = nullif(current_setting('app.current_user_id', true), '')::bigint
                );

            CREATE POLICY user_sessions_delete ON user_sessions
                FOR DELETE
                USING (
                    user_id = nullif(current_setting('app.current_user_id', true), '')::bigint
                );
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
