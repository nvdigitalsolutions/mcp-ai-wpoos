<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remote sites (090-i step a of the `remoteSites` decomposition).
 *
 * One row per outbound connection to another service. The schema is
 * **generic** where WordPress's record is a ~50-field flat array: type-specific
 * settings live in `config`, and anything secret lives in `credentials`, sealed
 * per field by `App\Support\CredentialStore`.
 *
 * `last_tested_at` / `last_test_status` are written by the verification step,
 * not by the store — a connection can exist before it has ever been tested.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nvoos_remote_sites')) {
            return;
        }

        Schema::create('nvoos_remote_sites', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('name', 100);
            $table->string('url', 255)->nullable();
            $table->string('connection_type', 40)->default('wordpress');
            $table->string('auth_type', 32)->default('none');
            // Non-secret settings only; the store rejects known secret names.
            $table->json('config')->nullable();
            // Sealed JSON: field => Crypt ciphertext.
            $table->text('credentials')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable();
            $table->timestamps();

            $table->index('connection_type');
            $table->index('enabled');
        });
    }

    public function down(): void
    {
        // Connections are user data; a rollback must not silently delete them.
        // Drop the table only when the operator explicitly wants that, by hand.
    }
};
