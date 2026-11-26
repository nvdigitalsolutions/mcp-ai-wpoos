<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tool capability-flag overrides — Proposal 149 §177.
 *
 * The WordPress counterpart is `orchestration` → `tools`, which writes
 * `wp_mcp_ai_tool_capability_flags`. This is the same affordance with one
 * deliberate restriction: a row may only **add** flags, never remove them. The
 * table has no `removed` column because removal must be unrepresentable — see
 * `Nvoos\Core\Domain\Contract\CapabilityFlagOverridesInterface`.
 *
 * One row per estate per tool. `flags` is a JSON list of canonical flag strings
 * drawn from `CapabilityFlagVocabulary::READ_ONLY_GATED`, so the stored value can
 * only ever make a tool stricter.
 */
return new class extends Migration
{
    private const TABLE = 'nvoos_tool_flag_overrides';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 64)->default('default');
            $table->string('tool_slug', 120);
            $table->json('flags');
            $table->timestamps();

            $table->unique(['tenant_id', 'tool_slug'], 'nvoos_tool_flag_overrides_tenant_tool_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
