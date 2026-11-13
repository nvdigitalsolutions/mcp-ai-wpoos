<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every table the app queries must come from a migration (090-f Phase 5).
 *
 * Eight of these previously existed only in the dev database, so a fresh
 * `php artisan migrate` produced an app whose Pro surfaces failed at runtime
 * with "table not found". This test is the regression guard: `RefreshDatabase`
 * builds the schema from migrations alone.
 */
class RuntimeSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tables referenced through `DB::table(...)` by the application.
     *
     * @return array<int, string>
     */
    public static function tables(): array
    {
        return [
            'nvoos_meta',
            'nvoos_schedules',
            'nvoos_schedule_runs',
            'nvoos_triggers',
            'nvoos_approvals',
            'nvoos_files',
            'nvoos_posts',
            'nvoos_security_snapshots',
            'nvoos_toolkit_records',
        ];
    }

    public function test_every_runtime_table_is_migrated(): void
    {
        foreach (self::tables() as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Table '{$table}' is queried by the app but no migration creates it."
            );
        }
    }

    public function test_key_columns_and_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('nvoos_schedules', ['id', 'cron_expression', 'enabled', 'next_run_at']));
        $this->assertTrue(Schema::hasColumns('nvoos_schedule_runs', ['schedule_id', 'status', 'duration']));
        $this->assertTrue(Schema::hasColumns('nvoos_approvals', ['status', 'tool', 'expires_at']));
        $this->assertTrue(Schema::hasColumns('nvoos_posts', ['title', 'slug', 'meta', 'deleted_at']));
        $this->assertTrue(Schema::hasColumns('nvoos_toolkit_records', ['toolkit', 'entity', 'external_id', 'data']));
    }

    public function test_the_toolkit_record_identity_is_unique(): void
    {
        $indexes = collect(Schema::getIndexes('nvoos_toolkit_records'))
            ->firstWhere('name', 'nvoos_toolkit_records_toolkit_entity_external_id_unique');

        $this->assertNotNull($indexes, 'The toolkit/entity/external_id unique index is missing.');
    }
}
