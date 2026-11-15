<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scope assistants to a tenant (090-g Phase 1).
 *
 * The original table made `slug` globally unique and left `is_default` global,
 * so two tenants could not both own "support-bot" and every tenant shared one
 * default assistant.
 *
 * Expand → migrate → contract: add the column with a default, backfill, then
 * swap the unique constraint and add the listing index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistants', function (Blueprint $table): void {
            $table->string('tenant_id', 64)->default('default');
        });

        // Backfill rows created before tenancy existed.
        DB::table('assistants')->update(['tenant_id' => 'default']);

        Schema::table('assistants', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('assistants', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'slug']);
            $table->dropIndex(['tenant_id', 'status']);
            $table->unique('slug');
        });

        Schema::table('assistants', function (Blueprint $table): void {
            $table->dropColumn('tenant_id');
        });
    }
};
