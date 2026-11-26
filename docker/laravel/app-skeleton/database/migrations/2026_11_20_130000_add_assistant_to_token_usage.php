<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token usage acquires an assistant — Proposal 154 slice D.
 *
 * §92's D1 deferred assistant-scoped budgets for one reason: the assistant
 * travelled only inside `metadata`, so filtering by it on the enforcement path
 * would have been a per-request JSON scan of the spend table. This is the column
 * §86 added for the estate, applied to the assistant — a defaulted column, an
 * index, and no backfill pass.
 *
 * Existing rows keep `0`, and `0` means **not attributed**, not "some
 * assistant". That is the same honesty §86 chose for pre-tenancy rows: an
 * assistant budget starts measuring from the first attributed turn, and cannot
 * be retroactively exceeded by spend that predates the column.
 *
 * Guarded so the migration replays, including on SQLite.
 */
return new class extends Migration
{
    private const TABLE = 'nvoos_token_usage';

    private const INDEX = 'nvoos_token_usage_assistant_id_index';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, 'assistant_id')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->unsignedBigInteger('assistant_id')->default(0);
            $table->index('assistant_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'assistant_id')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
            $table->dropColumn('assistant_id');
        });
    }
};
