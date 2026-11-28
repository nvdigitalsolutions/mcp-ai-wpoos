<?php

/**
 * A recording double for the database port.
 *
 * The tools are argument handling and envelope shaping; the kernel and the port
 * own every decision that can refuse. So the question these tests ask is narrow
 * and answerable without a database: did the tool hand the port the right query
 * object, and did it shape the answer the way the WordPress host does?
 *
 * @package Nvoos\Core\Tests
 * @since   2.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Support;

use Nvoos\Database\Domain\Contract\Database\ScopeAwareInterface;
use Nvoos\Database\Domain\Contract\DatabaseQueryInterface;
use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\ValueObject\Database\AggregateQuery;
use Nvoos\Database\Domain\ValueObject\Database\CatalogueDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\PlanDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use Nvoos\Database\Domain\ValueObject\Database\RawQuery;
use Nvoos\Database\Domain\ValueObject\Database\ResultSet;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\SearchQuery;
use Nvoos\Database\Domain\ValueObject\Database\SelectQuery;

final class FakeDatabasePort implements DatabaseQueryInterface, ScopeAwareInterface
{
    /**
     * Recorded calls, as `[method, arguments...]`.
     *
     * @var list<array<int, mixed>>
     */
    public array $calls = array();

    /**
     * @var list<ConnectionDescriptor>
     */
    public array $connections = array();

    public CatalogueDescriptor $catalogue;

    public ScopeDescriptor $scope;

    public ResultSet $result;

    public ?DatabaseRefusal $refusal = null;

    public function __construct()
    {
        $this->catalogue = new CatalogueDescriptor();
        $this->result    = new ResultSet();
        $this->scope     = new ScopeDescriptor(
            id: 'analyst',
            label: 'Analyst',
            connection: 'reporting',
            schemas: array('reporting'),
            tables: array('*'),
            tiers: array(),
            principals: array(new PrincipalRef(PrincipalRef::USER, '7')),
        );
    }

    public function connections(PrincipalRef $principal): array
    {
        $this->calls[] = array('connections', $principal);

        return $this->connections;
    }

    public function describe(string $connection, ?string $relation = null): CatalogueDescriptor
    {
        $this->calls[] = array('describe', $connection, $relation);
        $this->raise();

        return $this->catalogue;
    }

    public function select(string $connection, string $scope, SelectQuery $query): ResultSet
    {
        $this->calls[] = array('select', $connection, $scope, $query);
        $this->raise();

        return $this->result;
    }

    public function aggregate(string $connection, string $scope, AggregateQuery $query): ResultSet
    {
        $this->calls[] = array('aggregate', $connection, $scope, $query);
        $this->raise();

        return $this->result;
    }

    public function search(string $connection, string $scope, SearchQuery $query): ResultSet
    {
        $this->calls[] = array('search', $connection, $scope, $query);
        $this->raise();

        return $this->result;
    }

    public function explain(string $connection, string $scope, SelectQuery $query, bool $analyze = false): PlanDescriptor
    {
        $this->calls[] = array('explain', $connection, $scope, $query, $analyze);
        $this->raise();

        return new PlanDescriptor(array('Plan' => array()), $analyze);
    }

    public function query(string $connection, string $scope, RawQuery $query): ResultSet
    {
        $this->calls[] = array('query', $connection, $scope, $query);
        $this->raise();

        return $this->result;
    }

    public function scopeDescriptor(string $connection, string $scope, PrincipalRef $principal): ScopeDescriptor
    {
        $this->calls[] = array('scopeDescriptor', $connection, $scope, $principal);
        $this->raise();

        return $this->scope;
    }

    /**
     * The most recent call to a method.
     *
     * @return array<int, mixed>|null
     */
    public function last(string $method): ?array
    {
        $found = null;

        foreach ($this->calls as $call) {
            if ($call[0] === $method) {
                $found = $call;
            }
        }

        return $found;
    }

    private function raise(): void
    {
        if (null !== $this->refusal) {
            throw $this->refusal;
        }
    }
}
