<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resumable-upload sessions — Proposal 164 §164.30.
 *
 * A row is a claim about bytes that are owed: the declared total, how much has
 * arrived, and (once it completes) the media row the bytes became. The chunks
 * themselves live on a private disk keyed by the same id; this table is the only
 * thing that says a chunk directory is worth keeping.
 *
 * `attachment_id` is nullable and is the completion flag — a client that assembles
 * a file whose type is refused can retry or abort, and `prune()` only ever removes
 * rows that still have `null` here. `user_id` is not a foreign key for the same
 * reason the audit trail's is not: deleting a user must not resurrect their
 * half-finished uploads as anybody else's.
 *
 * `total_bytes` is bounded by `oos.chunked_uploads.max_bytes`, which defaults to
 * the registry's video group cap. That is a per-file decision, deliberately
 * separate from the per-request cap that makes `upload_max_filesize` matter.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nvoos_upload_sessions')) {
            return;
        }

        Schema::create('nvoos_upload_sessions', function (Blueprint $table): void {
            // The id is the client's capability: 32 hex characters from random_bytes.
            $table->string('id', 32)->primary();
            $table->string('tenant_id', 64)->nullable();
            $table->unsignedBigInteger('user_id')->default(0);
            $table->string('filename', 200);
            $table->string('declared_mime', 128)->nullable();
            $table->unsignedBigInteger('total_bytes');
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->unsignedBigInteger('attachment_id')->nullable();
            $table->timestamps();

            // Every lookup is (id) then ownership; the prune is by age.
            $table->index(['tenant_id', 'user_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nvoos_upload_sessions');
    }
};
