<?php

/**
 * Who is asking.
 *
 * Proposal 167 section 167.6.3. A principal is the resolved actor, not a value
 * the model supplies: the host derives it from the authenticated session (an
 * assistant id, a user id, an API client), and scope visibility is filtered
 * against it.
 *
 * Kept as a value object rather than a bare string so `assistant:42` and
 * `user:42` can never compare equal by accident — the failure mode that lets one
 * kind of principal widen another's grant.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\ValueObject\Database;

use InvalidArgumentException;

final readonly class PrincipalRef
{
    public const ASSISTANT = 'assistant';
    public const USER = 'user';
    public const CLIENT = 'client';

    /** @var list<string> */
    private const KINDS = array( self::ASSISTANT, self::USER, self::CLIENT );

    public function __construct(
        public string $kind,
        public string $id,
    ) {
        $kind = strtolower(trim($kind));
        $id = trim($id);

        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unknown principal kind: ' . $kind);
        }

        if ('' === $id) {
            throw new InvalidArgumentException('Principal id cannot be empty.');
        }
    }

    /**
     * Parse the canonical `kind:id` form used in stored scope records.
     */
    public static function fromString(string $value): self
    {
        $parts = explode(':', trim($value), 2);

        if (2 !== count($parts)) {
            throw new InvalidArgumentException('Expected a principal of the form kind:id.');
        }

        return new self($parts[0], $parts[1]);
    }

    /**
     * Try to parse, returning null on anything malformed.
     *
     * Used when reading stored scope records: a single corrupt entry must not
     * break the whole scope, and it certainly must not default to "everyone".
     */
    public static function tryFromString(string $value): ?self
    {
        try {
            return self::fromString($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function key(): string
    {
        return $this->kind . ':' . $this->id;
    }

    public function equals(self $other): bool
    {
        return $this->key() === $other->key();
    }
}
