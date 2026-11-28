<?php

/**
 * Config-backed connection and scope registries.
 *
 * Proposal 167 phase P4, tasks 4.5 and 4.6. On WordPress a connection is a
 * database row with an encrypted credential and a scope is a grant edited in the
 * console. In a Laravel app both are code: they live in `config/oos.php` and
 * ship with the deployment, which is the right place for an operator-scoped
 * grant — a config change is a reviewed commit, not a form submission.
 *
 * ## Fail-closed parsing
 *
 * A malformed scope is *dropped*, not coerced. `ScopeDescriptor` already refuses
 * a wildcard schema, an empty schema list and a version below one; catching that
 * refusal here means a typo in `config/oos.php` removes a grant instead of
 * widening one. The same is true of a scope naming a connection that does not
 * exist: it is inert.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Database;

use Nvoos\Database\Domain\Contract\Database\ConnectionRegistryInterface;
use Nvoos\Database\Domain\Contract\Database\ScopeRegistryInterface;
use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\GrantTier;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;

final class DatabaseConfig implements ConnectionRegistryInterface, ScopeRegistryInterface
{
    /**
     * @var array<string, ConnectionDescriptor>
     */
    private array $connections = array();

    /**
     * @var array<string, ScopeDescriptor>
     */
    private array $scopes = array();

    /**
     * @param array<string, mixed> $config The `oos.database` array.
     */
    public function __construct(array $config)
    {
        foreach ((array) ($config['connections'] ?? array()) as $id => $record) {
            if (! is_string($id) || ! is_array($record)) {
                continue;
            }

            try {
                // `role` is the connection's login (the descriptor's name for it,
                // shared with the probed session role). Config says `username`
                // because that is what an operator writes.
                $record['role'] = (string) ($record['role'] ?? $record['username'] ?? '');

                $this->connections[strtolower($id)] = ConnectionDescriptor::fromArray(
                    array('id' => $id) + $record,
                );
            } catch (DatabaseRefusal $refusal) {
                unset($refusal);
            }
        }

        foreach ((array) ($config['scopes'] ?? array()) as $id => $record) {
            if (! is_string($id) || ! is_array($record)) {
                continue;
            }

            try {
                $scope = ScopeDescriptor::fromArray(array('id' => $id) + $record);
            } catch (DatabaseRefusal $refusal) {
                unset($refusal);
                continue;
            }

            if (! isset($this->connections[$scope->connection])) {
                // A scope over a connection nobody declared cannot be served.
                continue;
            }

            $this->scopes[$scope->id] = $scope;
        }
    }

    /**
     * Every declared connection, without a principal filter.
     *
     * Used by `db_test_connection`-style diagnostics and by the console; the
     * port itself only ever calls {@see self::visible_to()}.
     *
     * @return array<string, ConnectionDescriptor>
     */
    public function all(): array
    {
        return $this->connections;
    }

    /**
     * {@inheritDoc}
     *
     * A connection is visible when *any* scope binds the principal to it, which
     * is the same rule the WordPress registry applies.
     */
    public function visible_to(PrincipalRef $principal): array
    {
        $found = array();

        foreach ($this->for_principal($principal) as $scope) {
            $found[$scope->connection] = true;
        }

        $connections = array();

        foreach (array_keys($found) as $id) {
            if (isset($this->connections[$id])) {
                $connections[] = $this->connections[$id];
            }
        }

        usort(
            $connections,
            static fn (ConnectionDescriptor $a, ConnectionDescriptor $b): int => strcmp($a->id, $b->id),
        );

        return $connections;
    }

    /**
     * {@inheritDoc}
     */
    public function get_or_refuse(string $id): ConnectionDescriptor
    {
        $key = strtolower(trim($id));

        if (! isset($this->connections[$key])) {
            throw DatabaseRefusal::of(
                RefusalCode::UNKNOWN_CONNECTION,
                'The connection is not declared in oos.database.connections.',
            );
        }

        return $this->connections[$key];
    }

    /**
     * {@inheritDoc}
     *
     * @return list<ScopeDescriptor>
     */
    public function for_principal(PrincipalRef $principal, ?string $connection = null): array
    {
        $wanted = null === $connection ? null : strtolower(trim($connection));
        $scopes = array();

        foreach ($this->scopes as $scope) {
            if (! $scope->allowsPrincipal($principal)) {
                continue;
            }

            if (null !== $wanted && $scope->connection !== $wanted) {
                continue;
            }

            $scopes[] = $scope;
        }

        return $scopes;
    }

    /**
     * {@inheritDoc}
     */
    public function resolve_for(
        PrincipalRef $principal,
        string $connection,
        ?string $scope_id,
        GrantTier $tier,
    ): ScopeDescriptor {
        $wanted = strtolower(trim($connection));

        if (null !== $scope_id && '' !== trim($scope_id)) {
            $key = strtolower(trim($scope_id));

            if (! isset($this->scopes[$key])) {
                throw DatabaseRefusal::of(RefusalCode::SCOPE_NOT_ALLOWED, 'Unknown scope: ' . $key);
            }

            $scope = $this->scopes[$key];

            if ($scope->connection !== $wanted) {
                throw DatabaseRefusal::of(
                    RefusalCode::SCOPE_NOT_ALLOWED,
                    'Scope ' . $scope->id . ' does not grant connection ' . $wanted . '.',
                );
            }

            if (! $scope->allowsPrincipal($principal)) {
                throw DatabaseRefusal::of(
                    RefusalCode::SCOPE_NOT_ALLOWED,
                    'Principal is not bound to scope ' . $scope->id . '.',
                );
            }

            if (! $scope->hasTier($tier)) {
                throw DatabaseRefusal::of(RefusalCode::TIER_REQUIRED, 'Scope ' . $scope->id . ' lacks tier ' . $tier->value . '.');
            }

            return $scope;
        }

        foreach ($this->for_principal($principal, $wanted) as $scope) {
            if ($scope->hasTier($tier)) {
                return $scope;
            }
        }

        throw DatabaseRefusal::of(
            RefusalCode::SCOPE_NOT_ALLOWED,
            'No scope binds this principal to ' . $wanted . ' at tier ' . $tier->value . '.',
        );
    }
}
