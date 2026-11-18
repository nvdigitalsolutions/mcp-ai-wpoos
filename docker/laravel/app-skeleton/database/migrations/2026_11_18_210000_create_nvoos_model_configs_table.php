<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The text-model catalogue — Proposal 149 §68.
 *
 * This is the runtime's own copy of the canonical chat/text model catalogue:
 * limits, prices and lifecycle. It is deliberately NOT a table of every model
 * the product can call. Image, video and audio models are a different catalogue
 * (`media-model-catalog.json`) with different facts — modes, aspect ratios,
 * resolutions, durations — and their prices are per image or per second, not
 * per token. Mixing both shapes in one table would make half the columns null
 * and invite a per-1M price on a video model.
 *
 * No `tenant_id`. Provider pricing and rate limits are deployment reference
 * data, the same rule §64/§66 applied to endpoints and security infrastructure:
 * an estate selects a model, it does not rewrite what the model costs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nvoos_model_configs')) {
            return;
        }

        Schema::create('nvoos_model_configs', function (Blueprint $table): void {
            $table->id();

            // The provider's API identifier (`gpt-4.1`, `claude-opus-4-8`).
            // This is the key settings and fallbacks reference, so it is fixed
            // after creation. It is only unique WITHIN a provider: `gpt-4o` is
            // both an Azure deployment and an OpenAI model in the canonical
            // catalogue, with different lifecycle, and upstream's id-keyed
            // option store collapses the pair. A single-column unique here
            // would silently drop one of them.
            $table->string('model_id', 191)->index();
            $table->string('name', 191);
            $table->string('provider', 64);
            $table->unique(['provider', 'model_id']);

            // Capacity and context.
            $table->unsignedInteger('context_window')->default(0);
            $table->unsignedInteger('max_output_tokens')->default(0);
            $table->unsignedBigInteger('tpm_limit')->default(0);
            $table->unsignedBigInteger('rpm_limit')->default(0);
            $table->unsignedBigInteger('tpd_limit')->default(0);
            $table->unsignedBigInteger('rpd_limit')->default(0);

            // USD per 1,000,000 tokens. Providers advertise per 1M; storing the
            // advertised unit keeps the table readable and avoids a per-render
            // conversion. The source catalogue is per 1K and the generator
            // multiplies by 1,000.
            $table->decimal('input_cost_per_million', 12, 6)->default(0);
            $table->decimal('output_cost_per_million', 12, 6)->default(0);

            // A single fallback, matching the WordPress Model Manager. A chain
            // is a router decision, not a row on this table; §68.5 records it.
            $table->string('fallback_model_id', 191)->nullable()->index();

            $table->string('status', 16)->default('active')->index();
            $table->date('sunset_date')->nullable();

            $table->boolean('supports_streaming')->default(true);
            $table->boolean('supports_function_calling')->default(false);
            $table->boolean('supports_vision')->default(false);

            $table->string('tier', 32)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nvoos_model_configs');
    }
};
