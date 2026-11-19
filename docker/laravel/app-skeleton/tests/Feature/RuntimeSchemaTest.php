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
            // 090-i telemetry and spend: the command-centre sources.
            'nvoos_health_samples',
            'nvoos_activity_events',
            'nvoos_token_usage',
            'nvoos_token_usage_daily',
            // Proposal 154: estate spend limits, read by `BudgetGuard` before
            // every spending entry point.
            'nvoos_budgets',
            // Created by the adapter package's own migration, loaded through
            // package discovery rather than an app migration.
            'nvoos_jobs',
            'nvoos_slash_command_audit',
            'nvoos_media_derivatives',
            'nvoos_remote_sites',
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
        $this->assertTrue(Schema::hasColumns('nvoos_toolkit_records', ['tenant_id', 'toolkit', 'entity', 'external_id', 'data']));
    }

    public function test_the_toolkit_record_identity_is_unique(): void
    {
        // Estate-scoped since 149 §47: the old global key refused two estates the
        // same business key, so the test names the composite it must now be. If
        // someone re-adds `tenant_id` without moving this key, inserts collide.
        $indexes = collect(Schema::getIndexes('nvoos_toolkit_records'));

        $this->assertNotNull(
            $indexes->firstWhere('name', 'nvoos_toolkit_records_tenant_toolkit_entity_external_id_unique'),
            'The tenant/toolkit/entity/external_id unique index is missing.'
        );

        $this->assertNull(
            $indexes->firstWhere('name', 'nvoos_toolkit_records_toolkit_entity_external_id_unique'),
            'The unscoped toolkit/entity/external_id unique index still exists; two estates cannot share an id.'
        );
    }

    public function test_security_snapshots_are_estate_scoped(): void
    {
        // §51: the payload is the estate's settings blob, so the column and the
        // list index are the difference between a history and a disclosure.
        $this->assertTrue(
            Schema::hasColumns('nvoos_security_snapshots', ['tenant_id', 'payload', 'created_at'])
        );

        $indexes = collect(Schema::getIndexes('nvoos_security_snapshots'));

        $this->assertNotNull(
            $indexes->firstWhere('name', 'nvoos_security_snapshots_tenant_created_index'),
            'The tenant/created_at index is missing; the snapshot list is not estate-scoped.'
        );
    }

    public function test_token_usage_is_estate_scoped(): void
    {
        // Proposal 152: `/api/v1/cost/*` is an estate-administrator surface, so
        // the column and its index are the difference between a cost report and
        // another estate's spend.
        $this->assertTrue(Schema::hasColumns('nvoos_token_usage', ['tenant_id', 'user_id', 'cost']));

        $indexes = collect(Schema::getIndexes('nvoos_token_usage'));

        $this->assertNotNull(
            $indexes->firstWhere('name', 'nvoos_token_usage_tenant_id_index'),
            'The tenant index is missing; scoped reads fall back to a full scan.'
        );
    }

    public function test_the_token_rollup_key_includes_the_estate(): void
    {
        // Proposal 152 D6: the old `(day, provider, model_id)` key would make
        // two estates' same-model day collide and the second upsert overwrite
        // the first. The test names the composite it must be.
        $indexes = collect(Schema::getIndexes('nvoos_token_usage_daily'));

        $this->assertNotNull(
            $indexes->firstWhere('name', 'nvoos_token_usage_daily_tenant_key'),
            'The tenant/day/provider/model unique index is missing.'
        );

        $this->assertNull(
            $indexes->firstWhere('name', 'nvoos_token_usage_daily_key'),
            'The unscoped day/provider/model unique index still exists; two estates collide.'
        );
    }

    public function test_trace_records_are_estate_scoped(): void
    {
        // Proposal 152 D1, Phase 2: the column lands before any estate-facing
        // trace surface, so the next one to be built cannot ship a leak.
        $this->assertTrue(
            Schema::hasColumns('nvoos_harness_trace_records', ['tenant_id', 'assistant_id', 'run_id'])
        );

        $indexes = collect(Schema::getIndexes('nvoos_harness_trace_records'));

        $this->assertNotNull(
            $indexes->firstWhere('name', 'nvoos_harness_trace_records_tenant_id_index'),
            'The tenant index is missing; a scoped trace read falls back to a full scan.'
        );
    }
}
