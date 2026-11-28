<?php

/**
 * Catalogue loader the read executor needs.
 *
 * Proposal 167 phase P4. The catalogue is the grammar's identifier source, so
 * "what may be named" comes from here and nowhere else. A host supplies the
 * introspection (the WordPress registry caches it in a transient; the Laravel
 * adapter reads `information_schema`), and the executor filters it per scope.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Contract\Database;

use Nvoos\Database\Domain\ValueObject\Database\CatalogueDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;

interface CatalogueInterface
{
    /**
     * The unfiltered catalogue for the given schemas.
     *
     * @param list<string> $schemas Schema names to include.
     * @throws \Nvoos\Database\Domain\Service\Database\DatabaseRefusal
     */
    public function load(ConnectionDescriptor $connection, array $schemas = array()): CatalogueDescriptor;
}
