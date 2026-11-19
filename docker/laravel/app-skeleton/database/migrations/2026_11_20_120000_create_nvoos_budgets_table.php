<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estate budgets — Proposal 154.
 *
 * A budget is a limit over the spend record that already exists
 * (`nvoos_token_usage`, estate-scoped since Proposal 152). Nothing is stored as
 * a running total: spend is `SUM(cost)` over the window, so a budget and the
 * cost pages can never disagree. A counter would be a second truth.
 *
 * One row per estate per window. The unique key makes a duplicate
 * unrepresentable rather than leaving the reader to pick one.
 */
return new class extends Migration
{
    private const TABLE = 'nvoos_budgets';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 64)->default('default');
            $table->string('window', 16);
            $table->decimal('limit_usd', 12, 6)->default(0);
            $table->decimal('warn_at', 5, 4)->default(0.8);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'window'], 'nvoos_budgets_tenant_window_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
