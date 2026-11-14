<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telemetry stores for the command-centre overview (090-i).
 *
 * Both are prerequisites rather than features. Without them the overview had
 * two KPIs it could not answer honestly, which is why the surface stayed
 * closed (#7745).
 *
 *  - `nvoos_health_samples` gives `uptime` a measurement. Note the inherent
 *    limit: a sample can only be written when the database is up, so a total
 *    database outage is inferred from the *absence* of samples, not from a
 *    failing row.
 *  - `nvoos_activity_events` gives `recent_events` a feed that the
 *    orchestration path — and later the chat path — write to.
 *
 * `Schema::hasTable()` guards match the runtime-tables migration, so this is
 * safe on an install that already carries the tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nvoos_health_samples')) {
            Schema::create('nvoos_health_samples', function (Blueprint $table): void {
                $table->id();
                $table->string('check', 32)->default('core');
                $table->boolean('healthy')->default(true);
                $table->timestamp('recorded_at')->index();
            });
        }

        if (! Schema::hasTable('nvoos_activity_events')) {
            Schema::create('nvoos_activity_events', function (Blueprint $table): void {
                $table->id();
                $table->string('type', 64)->index();
                $table->unsignedBigInteger('agent_id')->nullable()->index();
                $table->string('message', 500)->default('');
                $table->json('context')->nullable();
                $table->timestamp('created_at')->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nvoos_activity_events');
        Schema::dropIfExists('nvoos_health_samples');
    }
};
