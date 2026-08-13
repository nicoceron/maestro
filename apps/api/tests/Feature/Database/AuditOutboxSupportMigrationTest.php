<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AuditOutboxSupportMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_foundation_migration_rolls_back_and_remigrates_with_exact_table_ownership(): void
    {
        $migration = require database_path('migrations/2026_08_13_140000_create_audit_outbox_and_support_access_foundation.php');
        $tables = [
            'tenant_audit_streams', 'tenant_audit_events', 'transactional_outbox_aggregates',
            'transactional_outbox_messages', 'platform_support_operators',
            'support_access_grants', 'support_access_sessions',
        ];
        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $migration->down();
        foreach ($tables as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertTrue(Schema::hasTable('studios'));
        $this->assertTrue(Schema::hasTable('studio_audit_events'));

        $migration->up();
        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
    }
}
