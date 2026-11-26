<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A row in Laravel's own `failed_jobs` table (Proposal 149, Wave 2 `/ops`).
 *
 * Read-only by design: the panel retries or forgets through the
 * `FailedJobProviderInterface`, never by saving this model. It exists so the
 * Filament table can paginate, sort and resolve rows with Eloquent instead of
 * hand-rolling a query builder.
 *
 * Distinct from the console's DLQ: that one reads `nvoos_jobs` (the durable
 * job-status rows), while this table holds framework-level dead letters written
 * when a worker exhausts its retries. Both are real; neither is a duplicate.
 */
final class FailedJob extends Model
{
    protected $table = 'failed_jobs';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array(
            'failed_at' => 'datetime',
        );
    }

    /**
     * The failed job's class name, from the serialized payload.
     *
     * The payload carries both `job` (the handler class) and `displayName`
     * (Laravel's prettified name); the display name is what an operator
     * recognises, so it wins when present.
     */
    public function jobName(): string
    {
        $payload = json_decode((string) $this->payload, true);

        $name = is_array($payload)
            ? ($payload['displayName'] ?? $payload['job'] ?? '')
            : '';

        return is_string($name) && '' !== $name ? class_basename($name) : 'unknown';
    }

    /**
     * The first line of the exception, which is the message rather than the
     * stack trace. A full trace belongs in the log, not in a table cell.
     */
    public function exceptionSummary(): string
    {
        return Str::limit(Str::before((string) $this->exception, "\n"), 160);
    }
}
