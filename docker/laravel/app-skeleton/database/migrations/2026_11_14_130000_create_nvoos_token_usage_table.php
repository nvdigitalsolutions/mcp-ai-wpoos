<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The token-usage store (090-i).
 *
 * `Nvoos\Laravel\Adapter\TokenUsage` has always written here, and the console
 * Analytics page reads it, but no app migration created the table: the adapter
 * ships its own migration for consumers that install the package, and the app
 * never loads it. A fresh install therefore reported zero cost and zero tokens
 * forever, and `commandCenter.tokens_today` had no source to count.
 *
 * The columns are copied from the adapter migration so the two agree; the guard
 * keeps this safe on installs that already carry the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nvoos_token_usage')) {
            return;
        }

        Schema::create('nvoos_token_usage', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0)->index();
            $table->string('model_id', 120)->index();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('cost', 12, 6)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        // Deliberately a no-op: the rows are usage history, and an install that
        // already had the table must not lose them on a rollback.
    }
};
