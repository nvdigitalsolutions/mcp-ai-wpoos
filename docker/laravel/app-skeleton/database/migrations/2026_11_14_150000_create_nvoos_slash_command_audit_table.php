<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The slash-command audit trail (090-i).
 *
 * Mirrors WordPress's `wp_mcp_ai_slash_command_audit` columns so the same
 * question — who ran what, and did it work — is answerable on both runtimes.
 * Append-only: nothing updates a row.
 *
 * `created_at` rather than WordPress's `timestamp` column name; `timestamp` is
 * a type keyword and reads badly in raw SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nvoos_slash_command_audit')) {
            return;
        }

        Schema::create('nvoos_slash_command_audit', function (Blueprint $table): void {
            $table->id();
            $table->string('command', 255);
            $table->unsignedBigInteger('user_id')->default(0);
            $table->string('status', 20)->default('completed');
            $table->double('duration_ms')->default(0);
            $table->string('correlation_id', 64);
            $table->string('result', 500)->default('');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->index();

            $table->index('command');
            $table->index('user_id');
            $table->index('status');
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        // Deliberately a no-op: the rows are an audit trail and outlive a
        // rollback.
    }
};
