<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant tier of the profession catalogue — Proposal 149 §170.
 *
 * BEFORE THIS TABLE
 *
 * `save_profession` was the one manifest row this runtime could not serve, and
 * its recorded reason was that the catalogue is code-owned reference data with
 * no writable path here. That was true of the *base* tier and wrong as a
 * conclusion: the WordPress design has two tiers already — a seeded profession
 * CPT plus whatever an administrator added — and only the storage was missing.
 *
 * WHAT A ROW IS
 *
 * A tenant-authored profession, or a tenant's override of a base profession.
 * `slug` is unique per estate, so `save_profession` is an upsert, not an
 * append: an estate cannot accumulate two `lawyer` rows and then depend on
 * which one a query happened to return.
 *
 * COPY-ON-WRITE, NOT A PATCH
 *
 * When an estate edits a base profession the row is seeded from the base record
 * first, so an update that omits `description` keeps the description. The row
 * is then a complete record — not a diff — which is why every column is a real
 * field rather than a JSON blob: the catalogue is listed, filtered and counted
 * by category on two panels, and a blob would make each of those a PHP loop
 * over every estate's rows.
 *
 * `expertise`, `warnings` and `default_tools` are JSON because they are lists
 * with no query of their own; they are rendered, never filtered on.
 *
 * TENANCY
 *
 * `tenant_id` is a plain string, matching `nvoos_workflow_runs`, `nvoos_budgets`
 * and the assistant table. The model carries a global scope (§18), so a reader
 * cannot pick up another estate's profession by slug — the same rule that
 * closed the cross-estate leaks that came before it.
 *
 * The unique key is `(tenant_id, slug)` and its leftmost column is `tenant_id`,
 * so no separate index on `tenant_id` is needed: MySQL and SQLite both use the
 * composite for an estate-only lookup.
 *
 * Guarded so the migration replays, including on SQLite.
 */
return new class extends Migration
{
    private const TABLE = 'nvoos_professions';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 64);
            $table->string('slug', 100);
            $table->string('title', 200);
            $table->string('description', 500)->default('');
            $table->string('category', 64)->default('other');
            $table->text('role_description')->nullable();
            $table->json('expertise')->nullable();
            $table->json('warnings')->nullable();
            $table->text('knowledge_base')->nullable();
            $table->json('default_tools')->nullable();
            $table->timestamps();

            $table->unique(array('tenant_id', 'slug'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
