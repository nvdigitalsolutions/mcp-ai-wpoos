<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime tables for the ported Pro surfaces (090-f Phase 5 / 090-b follow-up).
 *
 * These tables existed only in the dev database: every `DB::table(...)` call
 * below referenced a table that no migration created, so a fresh `migrate` on
 * an empty database produced an app whose Paper Store, schedules, triggers,
 * approvals, media, content and toolkit surfaces all failed with "table not
 * found". The definitions here were read back from the live schema
 * (`SHOW CREATE TABLE`) so nothing changes behaviourally.
 *
 * `Schema::hasTable()` guards make this safe on an install where the tables
 * already exist: the migration records itself as run without touching data.
 * Fresh installs get the same shape built from Blueprint.
 *
 * Indexes: `nvoos_posts` carried both a unique index and a plain index on
 * `slug`; only the unique one is recreated, because the second adds write cost
 * for no lookup the unique index cannot serve.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nvoos_schedules')) {
            Schema::create('nvoos_schedules', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('name', 255)->nullable();
                $table->text('description')->nullable();
                $table->string('schedule_type', 32)->default('cron');
                $table->boolean('enabled')->default(true);
                $table->timestamp('next_run_at')->nullable();
                $table->timestamp('last_run_at')->nullable();
                $table->unsignedTinyInteger('priority')->default(10);
                $table->json('tags')->nullable();
                $table->unsignedInteger('run_count')->default(0);
                $table->string('handler', 255);
                $table->text('payload')->nullable();
                $table->string('cron_expression', 64);
                $table->timestamps();

                $table->index('enabled');
                $table->index('next_run_at');
            });
        }

        if (! Schema::hasTable('nvoos_schedule_runs')) {
            Schema::create('nvoos_schedule_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('schedule_id', 64);
                $table->string('status', 20)->default('running');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->double('duration')->nullable();
                $table->text('error')->nullable();
                $table->json('log')->nullable();
                $table->timestamps();

                $table->index('schedule_id');
                $table->index('status');
            });
        }

        if (! Schema::hasTable('nvoos_triggers')) {
            Schema::create('nvoos_triggers', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 255);
                $table->string('type', 40);
                $table->json('config')->nullable();
                $table->unsignedBigInteger('workflow_id')->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamp('last_fired_at')->nullable();
                $table->string('webhook_url', 255)->nullable();
                $table->timestamps();

                $table->index('type');
                $table->index('enabled');
            });
        }

        if (! Schema::hasTable('nvoos_approvals')) {
            Schema::create('nvoos_approvals', function (Blueprint $table): void {
                $table->id();
                $table->string('status', 20)->default('pending');
                $table->string('tool', 120);
                $table->json('arguments')->nullable();
                $table->unsignedBigInteger('assistant_id')->nullable();
                $table->unsignedBigInteger('requester_id')->nullable();
                $table->string('session_id', 128)->nullable();
                $table->text('reason')->nullable();
                $table->text('resolution_note')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index('status');
                $table->index('expires_at');
            });
        }

        if (! Schema::hasTable('nvoos_files')) {
            Schema::create('nvoos_files', function (Blueprint $table): void {
                $table->id();
                $table->string('path', 255);
                $table->string('disk', 50)->default('public');
                $table->string('filename', 255);
                $table->string('mime_type', 100)->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->unsignedBigInteger('owner_id')->nullable();
                $table->timestamps();

                $table->index('path');
                $table->index('owner_id');
            });
        }

        if (! Schema::hasTable('nvoos_posts')) {
            Schema::create('nvoos_posts', function (Blueprint $table): void {
                $table->id();
                $table->string('title', 255);
                $table->longText('content')->nullable();
                $table->string('status', 20)->default('draft');
                $table->string('type', 50)->default('post');
                $table->unsignedBigInteger('author_id')->nullable();
                $table->text('excerpt')->nullable();
                $table->string('slug', 255)->nullable()->unique();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('status');
                $table->index('type');
                $table->index('author_id');
            });
        }

        if (! Schema::hasTable('nvoos_security_snapshots')) {
            Schema::create('nvoos_security_snapshots', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('label', 255)->nullable();
                $table->json('payload');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('nvoos_toolkit_records')) {
            Schema::create('nvoos_toolkit_records', function (Blueprint $table): void {
                $table->id();
                $table->string('toolkit', 64);
                $table->string('entity', 64);
                $table->string('external_id', 128)->nullable();
                $table->json('data');
                $table->timestamps();

                $table->unique(['toolkit', 'entity', 'external_id']);
                $table->index(['toolkit', 'entity']);
            });
        }
    }

    public function down(): void
    {
        // Deliberately a no-op: these tables carry user data on installs that
        // already had them, and this migration cannot tell those rows apart
        // from anything created afterwards.
    }
};
