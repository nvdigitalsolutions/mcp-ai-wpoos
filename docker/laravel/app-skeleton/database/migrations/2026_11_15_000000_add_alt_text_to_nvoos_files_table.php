<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alt text on media files (090-j slice 2).
 *
 * Without it the command centre could not answer `with_alt` / `alt_pct` at
 * all, which is why those two fields shipped as `null` rather than `0`.
 * WordPress keeps this in `_wp_attachment_image_alt` meta; here it belongs on
 * the row, because `nvoos_files` is the media record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nvoos_files') || Schema::hasColumn('nvoos_files', 'alt_text')) {
            return;
        }

        Schema::table('nvoos_files', function (Blueprint $table): void {
            // Same ceiling as WordPress's meta value; long enough for a
            // sentence, short enough to stay out of TEXT.
            $table->string('alt_text', 500)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('nvoos_files') || ! Schema::hasColumn('nvoos_files', 'alt_text')) {
            return;
        }

        Schema::table('nvoos_files', function (Blueprint $table): void {
            $table->dropColumn('alt_text');
        });
    }
};
