<?php

/**
 * A recording double for the audit reader.
 *
 * `db_audit_tail` is pass-through: it forwards four filters and a clamped limit,
 * then renders `records` / `count` / `summary` / `retained`. The store's own
 * query behaviour is covered against a live PostgreSQL in
 * `tests/database/postgres/LaravelAdapterTest.php`, so this double only needs to
 * remember what it was asked and answer with a prepared page.
 *
 * @package Nvoos\Core\Tests
 * @since   2.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Support;

use Nvoos\Database\Domain\Contract\Database\AuditReaderInterface;

final class FakeAuditReader implements AuditReaderInterface
{
    /**
     * The last `tail()` call, as `[limit, filters]`.
     *
     * @var array{0: int, 1: array<string, mixed>}|null
     */
    public ?array $lastTail = null;

    /**
     * @param list<array<string, mixed>> $records Records to return.
     * @param array<string, int>         $summary Summary to return.
     */
    public function __construct(
        public array $records = array(),
        public array $summary = array('total' => 0),
        public int $retainedRecords = 0,
    ) {
    }

    public function tail(int $limit = 50, array $filters = array()): array
    {
        $this->lastTail = array($limit, $filters);

        return array_slice($this->records, 0, $limit);
    }

    public function summary(int $window_seconds = 3600): array
    {
        unset($window_seconds);

        return $this->summary;
    }

    public function retained(): int
    {
        return $this->retainedRecords;
    }
}
