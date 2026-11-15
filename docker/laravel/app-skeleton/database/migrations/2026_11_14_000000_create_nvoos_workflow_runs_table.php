<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 090-h H3 — append-only workflow run records.
 *
 * Runs are not content records: they are high-volume and never edited, so
 * they get their own narrow table rather than bloating `nvoos_posts` meta
 * (090-h §4). `events` holds the per-node result map from the executor.
 *
 * The guard keeps the migration replayable on a database that already has the
 * table, matching the runtime-table migration next to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nvoos_workflow_runs')) {
            return;
        }

        Schema::create('nvoos_workflow_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workflow_id');
            $table->string('status', 20)->default('running');
            $table->unsignedInteger('tool_calls')->default(0);
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->json('events')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->double('duration')->nullable();
            $table->timestamps();

            $table->index('workflow_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nvoos_workflow_runs');
    }
};
