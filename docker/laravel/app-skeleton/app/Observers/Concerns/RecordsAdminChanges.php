<?php

namespace App\Observers\Concerns;

use App\Support\Admin\AdminAuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Record-level audit for admin-managed models (Proposal 149 T-0.9, Wave 2).
 *
 * Wave 0 could say who made a request but not what a write changed. Model
 * events are the missing half; this trait is attached to each model a panel
 * manages, so the diff is recorded by one implementation rather than one per
 * resource.
 *
 * Three rules, and every administrable model inherits them:
 *
 *  1. **An audit row without an actor answers nothing.** Console seeds and
 *     queue jobs legitimately mutate records with nobody signed in; those
 *     writes are covered by the row's own `updated_at`, not by an operator
 *     audit log. A row with `user_id = null` would name an action nobody took.
 *  2. **`updated_at` is not a change.** It moves on every write, so recording
 *     it would bury the meaningful diff in noise.
 *  3. **Secrets are redacted by the logger, not here.** A model may carry a
 *     credential in plain text to PHP (the estate licence key does), so
 *     `AdminAuditLogger::recordResource()` applies the same structural
 *     redaction as request payloads.
 *
 * The table guard exists because the capability suites deliberately run
 * without migrations (see `TenantEntitlement`); a write must not fail because
 * the audit table is not there yet.
 */
trait RecordsAdminChanges
{
    public function __construct(private readonly AdminAuditLogger $logger)
    {
    }

    /**
     * The `resource` name written on this model's audit rows.
     */
    abstract protected function auditResource(): string;

    /**
     * The identifier an operator would recognise on the audit row.
     *
     * Defaults to the primary key. A model whose public identity is a natural
     * key (the model catalogue's `model_id`) overrides this, so the audit trail
     * names the row the panel actually shows rather than a surrogate integer
     * that changes between deployments.
     */
    protected function auditRecordId(Model $model): string
    {
        return (string) $model->getKey();
    }

    public function created(Model $model): void
    {
        $this->recordAudit($model, 'created', array(), $this->attributesFor($model));
    }

    public function updated(Model $model): void
    {
        $changes = $model->getChanges();
        unset($changes['updated_at']);

        if (array() === $changes) {
            return;
        }

        $before = array();
        $after = array();

        foreach (array_keys($changes) as $key) {
            $before[$key] = $model->getOriginal($key);
            $after[$key] = $model->getAttribute($key);
        }

        $this->recordAudit($model, 'updated', $before, $after);
    }

    public function deleted(Model $model): void
    {
        $this->recordAudit($model, 'deleted', $this->attributesFor($model), array());
    }

    /**
     * Whether the audit table accepts a write right now.
     *
     * Guards the suites that run without migrations and a deploy that writes
     * before the schema is in place. Public so the rule can be tested.
     */
    public function auditTableReady(): bool
    {
        try {
            return Schema::hasTable('nvoos_admin_audit');
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function recordAudit(Model $model, string $action, array $before, array $after): void
    {
        if (null === auth()->id()) {
            return;
        }

        if (! $this->auditTableReady()) {
            return;
        }

        $this->logger->recordResource(
            $this->auditResource(),
            $this->auditRecordId($model),
            $action,
            $before,
            $after
        );
    }

    /**
     * The record's attributes, minus the timestamps.
     *
     * @return array<string, mixed>
     */
    private function attributesFor(Model $model): array
    {
        $attributes = $model->getAttributes();

        unset($attributes['created_at'], $attributes['updated_at']);

        return $attributes;
    }
}
