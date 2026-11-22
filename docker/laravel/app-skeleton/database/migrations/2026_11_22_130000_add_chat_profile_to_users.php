<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user chat profile, mirroring WordPress's `wp_mcp_ai_chat_profile` meta.
 *
 * Proposal 149 — the chat-profile port (015). The column is the *preference*;
 * `users.role` is not consulted, because this is a permission boundary a user
 * chooses for themselves, not an authority grant an administrator confers.
 *
 * Null means "no personal selection — the site default applies". That is the
 * only safe backfill: every existing row inherits the default, which ships as
 * `write`, so the migration changes no behaviour until someone selects a
 * restrictive profile. Backfilling a value would silently downgrade every
 * existing user's tool access on deploy.
 *
 * Guarded, like every other column this runtime adds: a database that grew the
 * column out of band keeps its values.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'chat_profile')) {
            Schema::table('users', function (Blueprint $table): void {
                // Nullable and unindexed: read once per execution for one user
                // id, never filtered or sorted in bulk.
                $table->string('chat_profile', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'chat_profile')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('chat_profile');
            });
        }
    }
};
