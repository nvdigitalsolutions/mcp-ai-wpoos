<?php

/**
 * A customer directory held in memory, implementing the query contract.
 *
 * The search matches the name and the email as substrings, the role filter is
 * exact, and the page is taken after both — the same semantics the adapter
 * implements, so a tool that ignored a filter cannot pass.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Support;

use Nvoos\Core\Domain\Contract\CustomerDirectoryInterface;
use Nvoos\Core\Domain\Entity\Customer;
use Nvoos\Core\Domain\Entity\CustomerCollection;
use Nvoos\Core\Domain\Entity\CustomerQuery;

final class CustomerDirectoryFake implements CustomerDirectoryInterface
{
    /** @var array<int, Customer> Accounts by id. */
    public array $customers = array();

    /** @var array<int, CustomerQuery> Every query the tool issued. */
    public array $queries = array();

    public function __construct(Customer ...$customers)
    {
        foreach ($customers as $customer) {
            $this->customers[ $customer->id ] = $customer;
        }
    }

    public function find(int $id): ?Customer
    {
        return $this->customers[ $id ] ?? null;
    }

    public function query(CustomerQuery $query): CustomerCollection
    {
        $this->queries[] = $query;

        $matching = array_values(array_filter(
            $this->customers,
            static function (Customer $customer) use ($query): bool {
                if ('' !== $query->search) {
                    $needle = strtolower($query->search);
                    $haystack = strtolower($customer->name . ' ' . $customer->email);

                    if (false === strpos($haystack, $needle)) {
                        return false;
                    }
                }

                return null === $query->role || $customer->role === $query->role;
            }
        ));

        $field = $query->orderBy;
        $descending = 'DESC' === strtoupper($query->order);

        usort(
            $matching,
            static function (Customer $a, Customer $b) use ($field, $descending): int {
                $left = match ($field) {
                    'email' => $a->email,
                    'id'    => $a->id,
                    default => $a->name,
                };

                $right = match ($field) {
                    'email' => $b->email,
                    'id'    => $b->id,
                    default => $b->name,
                };

                $comparison = $left <=> $right;

                return ($descending ? -$comparison : $comparison) ?: ($a->id <=> $b->id);
            }
        );

        $total = count($matching);
        $page = array_slice($matching, $query->getOffset(), $query->perPage);

        return new CustomerCollection(
            $page,
            $total,
            $query->page,
            $query->perPage,
            (int) ceil($total / $query->perPage)
        );
    }
}
