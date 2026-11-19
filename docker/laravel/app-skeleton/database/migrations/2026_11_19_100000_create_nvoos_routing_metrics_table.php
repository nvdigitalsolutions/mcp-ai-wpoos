<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Model-fallback counters — Proposal 149 §71.
 *
 * One row per fallback attempt, not per chat: a fallback is rare, so the table
 * stays small without bucketing, and the pair (`from_model`, `to_model`) is
 * what an operator actually reads. Aggregation happens on read over the last
 * N days, matching the token-usage table's pattern.
 *
 * No `tenant_id`: routing decisions are made per provider, before any estate
 * dimension is known, and this is infrastructure telemetry — the same rule
 * §64/§66 applied to endpoints and security posture.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nvoos_routing_metrics')) {
            return;
        }

        Schema::create('nvoos_routing_metrics', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->string('from_model', 191);
            $table->string('to_model', 191);
            $table->boolean('succeeded')->default(false);
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nvoos_routing_metrics');
    }
};
