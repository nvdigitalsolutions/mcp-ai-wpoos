<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-hop attribution for routing metrics — Proposal 149 §72.
 *
 * §71 recorded model fallbacks. A provider hop is the other half: it fails for
 * different reasons (endpoint down, key revoked, region outage) and is fixed
 * by a different person, so it needs its own `kind` and, unlike a model
 * fallback, names the provider it came from.
 *
 * `from_model`/`to_model` stay non-null; a hop writes empty strings there
 * because no model changed, and `from_provider` carries the hop's source.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nvoos_routing_metrics')) {
            return;
        }

        if (Schema::hasColumn('nvoos_routing_metrics', 'kind')) {
            return;
        }

        Schema::table('nvoos_routing_metrics', function (Blueprint $table): void {
            $table->string('kind', 32)->default('model_fallback')->index();
            $table->string('from_provider', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('nvoos_routing_metrics')) {
            return;
        }

        if (! Schema::hasColumn('nvoos_routing_metrics', 'kind')) {
            return;
        }

        Schema::table('nvoos_routing_metrics', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'from_provider']);
        });
    }
};
