<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daily rollup acquires an estate — Proposal 152.
 *
 * The key becomes `(tenant_id, day, provider, model_id)`. The old
 * `(day, provider, model_id)` key is dropped rather than kept: two estates may
 * legitimately publish the same model on the same day, and leaving the narrower
 * key would reject the second estate's row while the wider key would have
 * accepted it.
 *
 * The table is new (§80) and small, so the rebuild is cheap on every engine the
 * app supports; the guards keep the migration replayable.
 */
return new class extends Migration
{
    private const TABLE = 'nvoos_token_usage_daily';

    private const OLD_KEY = 'nvoos_token_usage_daily_key';

    private const NEW_KEY = 'nvoos_token_usage_daily_tenant_key';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $indexes = $this->indexNames();

        if (in_array(self::OLD_KEY, $indexes, true)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::OLD_KEY);
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'tenant_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->string('tenant_id', 64)->default('default');
            });
        }

        if (! in_array(self::NEW_KEY, $this->indexNames(), true)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['tenant_id', 'day', 'provider', 'model_id'], self::NEW_KEY);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $indexes = $this->indexNames();

        if (in_array(self::NEW_KEY, $indexes, true)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::NEW_KEY);
            });
        }

        if (Schema::hasColumn(self::TABLE, 'tenant_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn('tenant_id');
            });
        }

        if (! in_array(self::OLD_KEY, $this->indexNames(), true)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['day', 'provider', 'model_id'], self::OLD_KEY);
            });
        }
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
