<?php

/**
 * Operator-authored SQL, single statement.
 *
 * Proposal 167 section 167.6.6 and decision D3. This is the escape hatch, and it
 * is the *only* shape in the toolkit that carries SQL. Three properties keep it
 * from becoming the design:
 *
 *  1. It requires the `raw_sql` tier, which is off by default and separately
 *     grantable.
 *  2. It is admitted through the same classifier as generated SQL — single
 *     statement, `SELECT`/`WITH … SELECT` only, no `SET`, no file/OS functions.
 *  3. Even if the classifier were removed entirely, the role has no write grant.
 *     That is the test the implementation plan calls the L2-removal gate.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\ValueObject\Database;

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\RefusalCode;

final readonly class RawQuery
{
    /**
     * @param string               $sql    The statement.
     * @param array<string, mixed> $params Named bindings, colon-prefixed keys.
     */
    public function __construct(
        public string $sql,
        public array $params = array(),
    ) {
        if ('' === trim($sql)) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Empty statement.');
        }
    }

    /**
     * @param array<string, mixed> $args Tool arguments.
     */
    public static function fromArray(array $args): self
    {
        $params = array();

        foreach ((array) ($args['params'] ?? array()) as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $params[str_starts_with($key, ':') ? $key : ':' . $key] = $value;
        }

        return new self((string) ($args['sql'] ?? ''), $params);
    }
}
