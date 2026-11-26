<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Routing export runs — Proposal 149 §74.
 *
 * Two jobs, one table, because they are the same fact:
 *
 *  - **the cursor.** `last_metric_id` on a successful run is the watermark; the
 *    next push sends only rows above it, so a collector sees each event once;
 *  - **the status.** The latest row is what `/ops/diagnostics` reports, so
 *    "the exporter has been failing since Tuesday" is visible without reading
 *    container logs.
 *
 * A failed run records the *unchanged* watermark, which makes the failure safe
 * by construction: nothing advances, the next attempt retries the same rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nvoos_routing_exports')) {
            return;
        }

        Schema::create('nvoos_routing_exports', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 16)->index();
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedBigInteger('last_metric_id')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nvoos_routing_exports');
    }
};
