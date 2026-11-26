<?php

namespace App\Support\Logs;

use App\Support\Admin\AuditTrail;
use App\Support\Observability\TraceRuns;
use Nvoos\Core\Domain\Contract\SystemLogInterface;

/**
 * The app's two log stores behind the core contract — Proposal 090-M §13.
 *
 * The WordPress tool merges `debug.log`, the NV oOS activity option and any
 * plugin `.log` it finds. This runtime has no log files it should paste into a
 * model's context, but it has two structured stores that answer the same
 * questions better:
 *
 *  - **audit** — `nvoos_admin_audit`, via `AuditTrail`, the reader the
 *    `/ops/audit-log` page already uses. Both row kinds are included: record rows
 *    ("what changed") and request rows ("who tried and was refused"), because a
 *    log that hides 403s hides the half that matters when something broke.
 *  - **traces** — `nvoos_harness_trace_records`, via `TraceRuns`. Only runs that
 *    are `incomplete` (no `end` record) or `aborted` become entries; a completed
 *    run is not a log event, and listing fifty healthy turns would bury the two
 *    that failed.
 *
 * ## What is not here
 *
 * No file tailing. `storage/logs/laravel.log` is unstructured, the path is
 * host-specific, and a tail is the one thing on this list that would leak
 * absolute paths and hostnames into a transcript. The two stores above cover
 * "what did an operator do" and "which turn died".
 *
 * ## Levels
 *
 * The audit trail has HTTP statuses, not severities, so the mapping is explicit:
 * `5xx` → `error`, `4xx` → `warning` (a refusal is a warning, not a failure),
 * `2xx`/`3xx` → `notice`. A record row is `notice` — a change that was applied,
 * which is exactly what the trail exists to show. Traces map `incomplete` →
 * `error`, `aborted` → `warning`.
 *
 * ## Redaction
 *
 * `AdminAuditLogger::redact()` runs at write time on the payload and diff
 * columns. This reader does not re-redact: the write path is the one every row
 * passes through, and a second pass would only give false confidence about this
 * one. See `AuditTrail`'s class comment.
 */
final class SystemLog implements SystemLogInterface
{
    public function __construct(private readonly TraceRuns $traces)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function sources(): array
    {
        return array('audit', 'traces');
    }

    /**
     * {@inheritDoc}
     */
    public function entries(array $query = array()): array
    {
        $limit  = max(1, min(AuditTrail::LIMIT, (int) ($query['limit'] ?? 20)));
        $since  = (string) ($query['since'] ?? '');
        $search = (string) ($query['search'] ?? '');

        $entries = array_merge(
            $this->auditEntries($limit, $since, $search),
            $this->traceEntries($limit, $since)
        );

        usort(
            $entries,
            static function (array $a, array $b): int {
                $left  = strtotime((string) ($a['at'] ?? ''));
                $right = strtotime((string) ($b['at'] ?? ''));

                return (false === $right ? 0 : $right) <=> (false === $left ? 0 : $left);
            }
        );

        return array_slice($entries, 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditEntries(int $limit, string $since, string $search): array
    {
        $filters = array('search' => $search);

        if ('' !== $since) {
            $timestamp = strtotime($since);

            if (false !== $timestamp) {
                // AuditTrail compares against a DATETIME column, so the ISO
                // timestamp the contract carries is converted here rather than
                // relying on MySQL to parse a `T`/`Z` form.
                $filters['since'] = gmdate('Y-m-d H:i:s', $timestamp);
            }
        }

        $entries = array();

        foreach (AuditTrail::rows($filters, $limit) as $row) {
            $status = (int) ($row['status'] ?? 0);
            $kind   = (string) ($row['kind'] ?? 'request');

            $entries[] = array(
                'id'      => 'audit:'.$row['id'],
                'at'      => (string) ($row['at'] ?? ''),
                'source'  => 'audit',
                'type'    => 'request' === $kind ? 'audit.request' : 'audit.record',
                'level'   => $this->auditLevel($kind, $status),
                'summary' => (string) ($row['label'] ?? ''),
                'actor'   => isset($row['actor']) ? (string) $row['actor'] : null,
                'detail'  => array(
                    'estate'    => (string) ($row['estate'] ?? ''),
                    'panel'     => (string) ($row['panel'] ?? ''),
                    'method'    => (string) ($row['method'] ?? ''),
                    'status'    => $status,
                    'resource'  => (string) ($row['resource'] ?? ''),
                    'action'    => (string) ($row['action'] ?? ''),
                    'record_id' => (string) ($row['record_id'] ?? ''),
                    'has_diff'  => (bool) ($row['has_diff'] ?? false),
                ),
            );
        }

        return $entries;
    }

    /**
     * A change is a notice; a refusal is a warning; a server error is an error.
     */
    private function auditLevel(string $kind, int $status): string
    {
        if ('record' === $kind) {
            return SystemLogInterface::NOTICE;
        }

        if ($status >= 500) {
            return SystemLogInterface::ERROR;
        }

        if ($status >= 400) {
            return SystemLogInterface::WARNING;
        }

        return SystemLogInterface::NOTICE;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function traceEntries(int $limit, string $since): array
    {
        $sinceAt = '' === $since ? false : strtotime($since);
        $entries = array();

        foreach ($this->traces->recent($limit) as $run) {
            $status = (string) ($run['status'] ?? '');

            if (! in_array($status, array('incomplete', 'aborted'), true)) {
                continue;
            }

            $at        = (string) ('' !== (string) $run['ended_at'] ? $run['ended_at'] : $run['started_at']);
            $timestamp = strtotime($at);

            if (false !== $sinceAt && false !== $timestamp && $timestamp < $sinceAt) {
                continue;
            }

            $incomplete = 'incomplete' === $status;

            $entries[] = array(
                'id'      => 'run:'.(string) $run['run_id'],
                'at'      => false === $timestamp ? $at : gmdate('Y-m-d\TH:i:s\Z', $timestamp),
                'source'  => 'traces',
                'type'    => $incomplete ? 'run.incomplete' : 'run.aborted',
                'level'   => $incomplete ? SystemLogInterface::ERROR : SystemLogInterface::WARNING,
                'summary' => sprintf(
                    'Run %s %s (%s, %d records).',
                    (string) $run['run_id'],
                    $incomplete ? 'ended without a summary' : 'was aborted',
                    (string) $run['assistant'],
                    (int) $run['records']
                ),
                'actor'   => null,
                'detail'  => array(
                    'run_id'         => (string) $run['run_id'],
                    'assistant_id'   => (int) $run['assistant_id'],
                    'assistant'      => (string) $run['assistant'],
                    'estate'         => (string) $run['estate'],
                    'records'        => (int) $run['records'],
                    'duration_ms'    => $run['duration_ms'],
                    'cost_usd'       => $run['cost_usd'],
                    'provider_calls' => $run['provider_calls'],
                ),
            );
        }

        return $entries;
    }
}
