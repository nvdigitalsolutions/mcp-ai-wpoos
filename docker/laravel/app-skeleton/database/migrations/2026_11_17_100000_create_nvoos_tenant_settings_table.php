<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settings acquire an estate dimension — Proposal 149 §16.
 *
 * `nvoos_settings` is install-wide and keyed only by `key`, so two estates on
 * one deployment share `default_model` and `openai_api_key`. That was invisible
 * while the only writer was the console, which never pinned a tenant; it stops
 * being invisible the moment `/admin` grows a settings form, because a row
 * written by estate A would then be read by estate B.
 *
 * The split follows the `scope` already declared on every field:
 *
 *  - `operator` scope stays in `nvoos_settings` — one platform layer, written
 *    only by `/ops`;
 *  - `tenant`/`user` scope moves to `nvoos_tenant_settings`, keyed by
 *    `(tenant_id, key)`.
 *
 * Compatibility, deliberately: existing rows are COPIED to the default estate,
 * never deleted. Every deployment that predates this migration has one estate,
 * so the copy is the whole story there; a deployment that already holds several
 * keeps resolving through the platform layer until each estate writes its own
 * value. Deleting the originals would break an estate that has never visited
 * this page, so the cleanup is a later, separate decision (plan §16.4).
 *
 * The platform-key list is frozen at migration time on purpose. Migrations must
 * not import `SettingsSchema`: the class will grow fields later, and a migration
 * that re-reads it would move different rows on every replay.
 */
return new class extends Migration
{
    /**
     * Keys owned by the platform layer. `ollama_endpoint` joins the list with
     * S-5: it is a URL the server fetches, so a tenant administrator must not
     * choose where the server connects.
     *
     * @var list<string>
     */
    private const PLATFORM_KEYS = [
        'enable_logging',
        'ollama_endpoint',
        // Pre-migration spelling of the same setting. Kept here so a row that
        // used it is not moved into an estate layer on replay.
        'ollama_endpoint_url',
    ];

    /** The estate that inherits the pre-split values. */
    private const DEFAULT_TENANT = 'default';

    public function up(): void
    {
        // The platform table is created lazily by `SettingsStore` today, which
        // meant a fresh install had no settings schema until the first write.
        // Creating it here makes both layers explicit and lets a migration-time
        // assertion (and a test) rely on the table existing.
        if (! Schema::hasTable('nvoos_settings')) {
            Schema::create('nvoos_settings', function (Blueprint $table): void {
                $table->string('key', 191)->primary();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('nvoos_tenant_settings')) {
            Schema::create('nvoos_tenant_settings', function (Blueprint $table): void {
                $table->string('tenant_id', 64);
                $table->string('key', 191);
                $table->text('value')->nullable();
                $table->timestamps();

                // Composite primary key rather than a surrogate id: the lookup
                // is always (tenant, key) and the pair is the natural identity.
                // It is also the conflict target `SettingsStore::set()` uses.
                $table->primary(['tenant_id', 'key']);
            });
        }

        $this->copyEstateRows();
    }

    /**
     * Nothing is dropped. A down() migration that deleted the estate rows would
     * destroy customer configuration on rollback; the platform layer still holds
     * the pre-split copy, so the previous code path works unchanged.
     */
    public function down(): void
    {
        Schema::dropIfExists('nvoos_tenant_settings');
    }

    /**
     * Copy every non-platform setting into the default estate.
     *
     * Idempotent: an existing estate row wins, so replaying the migration on a
     * database that has since been edited cannot resurrect a stale value.
     */
    private function copyEstateRows(): void
    {
        if (! Schema::hasTable('nvoos_settings')) {
            return;
        }

        $now = now();

        foreach (DB::table('nvoos_settings')->get(['key', 'value']) as $row) {
            $key = (string) $row->key;

            if (in_array($key, self::PLATFORM_KEYS, true)) {
                continue;
            }

            $exists = DB::table('nvoos_tenant_settings')
                ->where('tenant_id', self::DEFAULT_TENANT)
                ->where('key', $key)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('nvoos_tenant_settings')->insert([
                'tenant_id' => self::DEFAULT_TENANT,
                'key' => $key,
                'value' => $row->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
