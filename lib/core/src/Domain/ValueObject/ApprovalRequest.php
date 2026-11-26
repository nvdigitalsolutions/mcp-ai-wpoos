<?php

/**
 * Approval request — the exact action a human is being asked to allow.
 *
 * An approval that only says "yes" is not enough. It must approve *this* tool,
 * with *these* arguments, from *this* requester, and be spendable once. The
 * parts are split deliberately:
 *
 *   - `argumentsFingerprint()` identifies the payload.
 *   - the store binds the tool and the requester by querying their columns.
 *
 * The fingerprint algorithm is the one `MachineToolController` already uses for
 * machine approvals — `sha256` of `json_encode(canonical($arguments))`, where
 * canonical sorts **keys** recursively. It is copied rather than improved
 * because two hashes for the same call would be worse than one imperfect
 * scheme: a machine approval and a necessity approval for the same payload
 * must agree.
 *
 * List order is preserved, because `ksort` sorts keys and a list's keys are
 * already in order. `['a','b']` and `['b','a']` therefore hash differently —
 * which is the safe direction for an ordered argument.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

/**
 * A tool call awaiting human approval.
 *
 * @since 2.1.0
 */
final class ApprovalRequest
{
    /**
     * @param array<string, mixed> $arguments Tool arguments as the model sent them.
     */
    public function __construct(
        public readonly string $tool,
        public readonly array $arguments = array(),
        public readonly ?int $requesterId = null,
        public readonly ?string $sessionId = null,
        public readonly string $reason = '',
    ) {
    }

    /**
     * Hash of the call's arguments, independent of key order.
     */
    public function argumentsFingerprint(): string
    {
        $encoded = \json_encode(
            self::canonical($this->arguments),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return \hash('sha256', false === $encoded ? '' : $encoded);
    }

    /**
     * Sort keys recursively — the same canonicalisation the machine path uses.
     *
     * @param array<string, mixed> $value Argument sub-tree.
     * @return array<string, mixed>
     */
    private static function canonical(array $value): array
    {
        \ksort($value);

        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }

        return $value;
    }
}
