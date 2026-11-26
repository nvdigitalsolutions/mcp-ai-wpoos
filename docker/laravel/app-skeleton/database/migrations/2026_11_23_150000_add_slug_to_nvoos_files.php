<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A media item gets its own slug — Proposal 162 §162.8.
 *
 * WordPress separates three things that `nvoos_files` had collapsed into one:
 *
 *  - `post_title`   — what the library shows ("Pixel.png");
 *  - `post_name`    — the slug, lowercased, hyphenated and **unique**
 *                     (`pixel`, `pixel-2`), via `wp_unique_post_slug()`;
 *  - `_wp_attached_file` — where the bytes live.
 *
 * Until this column existed, `MediaController` derived the slug with
 * `pathinfo($filename, PATHINFO_FILENAME)`. That made display naming and routing
 * the same field: changing the registry's display-name sanitisation changed every
 * slug with it, which is exactly how `slug: Pixel` reached the `wp/v2/media`
 * surface. A slug that a client routes on should not move when a filename's case
 * changes.
 *
 * Nullable on purpose: every existing row has no slug, and inventing one would
 * mean reading every row's filename and hoping it is unique. The reader falls
 * back to the derived stem for those rows (see `MediaController::record()`), and
 * new writes always carry a column value.
 *
 * The composite unique index is the backstop for two uploads racing on the same
 * name: the writer computes the next free suffix, and the index refuses the loser
 * so it retries rather than sharing a slug.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nvoos_files')) {
            return;
        }

        if (! Schema::hasColumn('nvoos_files', 'slug')) {
            Schema::table('nvoos_files', function (Blueprint $table): void {
                $table->string('slug', 160)->nullable();
            });
        }

        // `null` slugs do not collide with each other in either SQLite or MySQL,
        // so this is safe to add over a table of legacy rows. No plain `slug`
        // index: the only lookup is `(tenant_id, slug)`, which this covers.
        if (! Schema::hasIndex('nvoos_files', 'nvoos_files_tenant_slug_unique')) {
            Schema::table('nvoos_files', function (Blueprint $table): void {
                $table->unique(array('tenant_id', 'slug'), 'nvoos_files_tenant_slug_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('nvoos_files')) {
            return;
        }

        if (Schema::hasColumn('nvoos_files', 'slug')) {
            Schema::table('nvoos_files', function (Blueprint $table): void {
                if (Schema::hasIndex('nvoos_files', 'nvoos_files_tenant_slug_unique')) {
                    $table->dropUnique('nvoos_files_tenant_slug_unique');
                }

                $table->dropColumn('slug');
            });
        }
    }
};
