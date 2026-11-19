<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Budgets acquire an assistant scope — Proposal 154 slice D.
 *
 * The unique key moves from `(tenant_id, window)` to
 * `(tenant_id, assistant_id, window)`, and `assistant_id = 0` means
 * **whole estate**. A sentinel rather than `NULL` on purpose: SQL treats NULLs as
 * distinct in a unique index, so a nullable column would let two estate-wide
 * daily rows exist for the same estate — exactly the duplicate the original key
 * was added to make unrepresentable. `0` is also the sentinel the token table
 * uses for "not attributed", so the two tables speak the same dialect.
 *
 * The old key is dropped rather than kept: with it in place an estate could not
 * hold both a whole-estate limit and an assistant limit for the same window,
 * which is the pairing this slice exists to allow.
 *
 * A pre-existing budget keeps `assistant_id = 0` — every limit configured before
 * this migration was an estate-wide limit, so the default is not an assumption
 * here, it is the only possible reading.
 */
return new class extends Migration
{
    private const TABLE = 'nvoos_budgets';

    private const OLD_KEY = 'nvoos_budgets_tenant_window_key';

    private const NEW_KEY = 'nvoos_budgets_scope_window_key';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $indexes = $this->indexNames();

        if (! Schema::hasColumn(self::TABLE, 'assistant_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('assistant_id')->default(0);
            });
        }

        if (in_array(self::OLD_KEY, $indexes, true)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::OLD_KEY);
            });
        }

        if (! in_array(self::NEW_KEY, $this->indexNames(), true)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(array('tenant_id', 'assistant_id', 'window'), self::NEW_KEY);
            });
        }
    }

    /**
     * Deliberately does not restore the old key.
     *
     * Narrowing back to `(tenant_id, window)` would fail — or, worse, succeed
     * after deleting an operator's assistant limits — the moment an estate holds
     * two scopes for one window. A rollback should not remove a configured
     * control, so `assistant_id` stays; the wider key accepts every row the
     * narrower one accepted.
     */
    public function down(): void
    {
        // No-op by design; see the docblock.
    }

    /**
     * @return list<string>
     */
    private function indexNames(): array
    {
        $names = array();

        foreach (Schema::getIndexes(self::TABLE) as $index) {
            $names[] = (string) ($index['name'] ?? '');
        }

        return $names;
    }
};
