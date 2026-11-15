<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime metadata, starting with the schema version (090-b Phase 5.5).
 *
 * Laravel's `migrations` table already records *which* migrations ran, but not
 * what the data means. A schema version is the operator-facing half of an
 * upgrade: `nvoos:doctor` compares it to `oos.schema_version` and tells you to
 * migrate when they disagree, instead of failing somewhere deeper with a
 * "column not found".
 *
 * Bump `oos.schema_version` whenever a migration changes data shape in a way a
 * previous release cannot read, and update this row in the same migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nvoos_meta', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->text('value');
            $table->timestamps();
        });

        DB::table('nvoos_meta')->insert([
            'key' => 'schema_version',
            'value' => (string) config('oos.schema_version', 1),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('nvoos_meta');
    }
};
