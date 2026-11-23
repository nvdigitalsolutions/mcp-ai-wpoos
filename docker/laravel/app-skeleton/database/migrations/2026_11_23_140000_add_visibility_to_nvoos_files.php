<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `nvoos_files` becomes the file registry the toolkits can trust — Proposal 162.
 *
 * Three columns, each fixing a defect that made the table unusable as a shared
 * file layer:
 *
 *  - `visibility` — there was none. Every write was public and the disk was
 *    hard-coded, so a private file could not exist. The default is `public`
 *    because every existing row *is* public: `/api/v1` and the media library only
 *    ever wrote to the web-served disk. Backfilling anything else would be
 *    inventing a fact about rows that recorded none.
 *  - `checksum` — `sha256` of the bytes. Nullable for the same reason: existing
 *    rows would need their files read to fill it, and a migration should not read
 *    every object in storage.
 *  - `metadata` — the JSON column `FileStoreInterface::findByMetadata()` already
 *    documents and queries, but which never existed on this table.
 *
 * Guarded per column, so the migration replays on SQLite as well as MySQL — the
 * same shape §109's tenant migration uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nvoos_files')) {
            return;
        }

        if (! Schema::hasColumn('nvoos_files', 'visibility')) {
            Schema::table('nvoos_files', function (Blueprint $table): void {
                $table->string('visibility', 16)->default('public');
                $table->index('visibility');
            });
        }

        if (! Schema::hasColumn('nvoos_files', 'checksum')) {
            Schema::table('nvoos_files', function (Blueprint $table): void {
                $table->string('checksum', 64)->nullable();
                $table->index('checksum');
            });
        }

        if (! Schema::hasColumn('nvoos_files', 'metadata')) {
            Schema::table('nvoos_files', function (Blueprint $table): void {
                $table->json('metadata')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('nvoos_files')) {
            return;
        }

        foreach (array('metadata', 'checksum', 'visibility') as $column) {
            if (Schema::hasColumn('nvoos_files', $column)) {
                Schema::table('nvoos_files', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
