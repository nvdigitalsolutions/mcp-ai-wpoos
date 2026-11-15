<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 090-h — record what started a workflow run.
 *
 * The run row already carries status/duration/tool calls; without `source`
 * and `trigger_id` there is no way to answer "did a person run this, or did a
 * trigger?" once triggers exist. Guarded per column, and one `Schema::table`
 * per column, so the migration replays on SQLite as well as MySQL.
 *
 * `after()` is deliberately omitted: it is MySQL-only and would be ignored on
 * the test database, which makes the two environments drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nvoos_workflow_runs')) {
            return;
        }

        if (! Schema::hasColumn('nvoos_workflow_runs', 'source')) {
            Schema::table('nvoos_workflow_runs', function (Blueprint $table): void {
                $table->string('source', 20)->default('manual');
            });
        }

        if (! Schema::hasColumn('nvoos_workflow_runs', 'trigger_id')) {
            Schema::table('nvoos_workflow_runs', function (Blueprint $table): void {
                $table->unsignedBigInteger('trigger_id')->nullable();
                $table->index('trigger_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('nvoos_workflow_runs')) {
            return;
        }

        if (Schema::hasColumn('nvoos_workflow_runs', 'trigger_id')) {
            Schema::table('nvoos_workflow_runs', function (Blueprint $table): void {
                $table->dropIndex(['trigger_id']);
                $table->dropColumn('trigger_id');
            });
        }

        if (Schema::hasColumn('nvoos_workflow_runs', 'source')) {
            Schema::table('nvoos_workflow_runs', function (Blueprint $table): void {
                $table->dropColumn('source');
            });
        }
    }
};
