<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tool quotas — Proposal 149 §176.
 *
 * The WordPress counterpart (`token_manager` → `per_tool`) keeps a per-tool
 * daily token limit in one option and the running usage in per-user meta. This
 * runtime needs the same control, split into two tables for the reason the
 * budgets table records: a limit and its consumption are different facts, and
 * merging them makes the counter a second truth.
 *
 * **Policy** — one row per estate per tool. Absence of a row means the tool is
 * unmetered, which is the fail-safe floor: a control nobody configured must not
 * block anybody.
 *
 * **Usage** — one row per estate, user, tool and UTC day. `user_id = 0` is a
 * real bucket rather than a sentinel for "unknown": machine principals and
 * queued jobs execute tools with no signed-in user, and giving them their own
 * row is what keeps their traffic bounded instead of invisible.
 *
 * Neither table carries a `tenant_id` the caller may choose: the model's
 * creating hook and global scope take it from `TenantContext`, the same rule
 * `Budget`, `Approval` and `Assistant` follow (Proposal 153).
 */
return new class extends Migration
{
    public const QUOTAS_TABLE = 'nvoos_tool_quotas';

    public const USAGE_TABLE = 'nvoos_tool_quota_usage';

    public function up(): void
    {
        if (! Schema::hasTable(self::QUOTAS_TABLE)) {
            Schema::create(self::QUOTAS_TABLE, function (Blueprint $table): void {
                $table->id();
                $table->string('tenant_id', 64)->default('default');
                $table->string('tool_slug', 120);
                $table->unsignedBigInteger('daily_tokens')->default(0);
                $table->boolean('enabled')->default(true);
                $table->timestamps();

                // One limit per tool per estate. A duplicate is unrepresentable
                // rather than something the reader has to reconcile.
                $table->unique(['tenant_id', 'tool_slug'], 'nvoos_tool_quotas_tenant_tool_key');
            });
        }

        if (! Schema::hasTable(self::USAGE_TABLE)) {
            Schema::create(self::USAGE_TABLE, function (Blueprint $table): void {
                $table->id();
                $table->string('tenant_id', 64)->default('default');
                $table->unsignedBigInteger('user_id')->default(0);
                $table->string('tool_slug', 120);
                $table->date('day');
                $table->unsignedBigInteger('tokens')->default(0);
                $table->unsignedInteger('requests')->default(0);
                $table->timestamps();

                $table->unique(
                    ['tenant_id', 'user_id', 'tool_slug', 'day'],
                    'nvoos_tool_quota_usage_bucket_key'
                );

                // Pruning walks whole estates by day; the page reads one day.
                $table->index(['tenant_id', 'day'], 'nvoos_tool_quota_usage_tenant_day_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::USAGE_TABLE);
        Schema::dropIfExists(self::QUOTAS_TABLE);
    }
};
