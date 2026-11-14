<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Derived media files (090-j slice 3).
 *
 * One row per (source file, output format). The row is the proof that a
 * derivative exists and the record of what optimisation saved — `compression`
 * is the sum of `savings_bytes`, so a saving is only ever reported when the
 * bytes were actually produced and stored.
 *
 * The unique key makes a re-run idempotent: a sweep replaces a derivative
 * rather than duplicating it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nvoos_media_derivatives')) {
            return;
        }

        Schema::create('nvoos_media_derivatives', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('file_id');
            $table->string('format', 16);
            $table->string('path', 255);
            $table->unsignedBigInteger('size_bytes')->default(0);
            // Signed: an "optimised" file can come out larger than the source,
            // and a negative saving must not silently inflate the total.
            $table->bigInteger('savings_bytes')->default(0);
            $table->timestamps();

            $table->unique(['file_id', 'format']);
            $table->index('file_id');
        });
    }

    public function down(): void
    {
        // Derived data: dropping the table is safe, and the files it points at
        // are rebuildable by running a sweep again.
        Schema::dropIfExists('nvoos_media_derivatives');
    }
};
