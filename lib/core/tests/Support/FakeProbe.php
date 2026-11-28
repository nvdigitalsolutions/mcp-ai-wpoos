<?php

/**
 * A recording double for the privilege probe.
 *
 * `db_test_connection` is argument handling plus the four guidance strings; the
 * introspection itself is the kernel probe, covered against a live PostgreSQL in
 * `tests/database/postgres/`. So this double only needs to answer with a
 * prepared `ProbeResult` and remember which descriptor was probed.
 *
 * @package Nvoos\Core\Tests
 * @since   2.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Support;

use Nvoos\Database\Domain\Contract\Database\ConnectionProbeInterface;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\ProbeResult;

final class FakeProbe implements ConnectionProbeInterface
{
    /**
     * Descriptors this probe was asked about.
     *
     * @var list<ConnectionDescriptor>
     */
    public array $probed = array();

    public function __construct(
        public ProbeResult $result = new ProbeResult(reachable: true),
    ) {
    }

    public function probe(ConnectionDescriptor $connection): ProbeResult
    {
        $this->probed[] = $connection;

        return $this->result;
    }
}
