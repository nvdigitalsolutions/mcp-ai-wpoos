<?php

/**
 * Self-Refine failure carrying a machine-readable reason.
 *
 * Core must not import `WP_Error`, but the WordPress delegation has to rebuild
 * the *exact* error the old local implementation returned — including the
 * identity of an error object handed back by the caller's generator. So the
 * loop throws this instead of returning one, and the runtime translates.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Harness;

final class SelfRefineException extends \RuntimeException
{
    /**
     * @param string $reason        Machine-readable reason: `empty_task`,
     *                              `cost_exceeded`, `generator_failed` or
     *                              `critic_failed`.
     * @param string $message       Human-readable message.
     * @param mixed  $platformError The caller's error object, when the loop
     *                              stopped because a callable returned one.
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        private readonly mixed $platformError = null,
    ) {
        parent::__construct($message);
    }

    /**
     * The caller's error object, when the loop stopped because a callable
     * returned one.
     *
     * A getter rather than a public property: the WordPress consumer is
     * checked against WPCS, which only accepts snake_case properties, and
     * core is camelCase.
     */
    public function getPlatformError(): mixed
    {
        return $this->platformError;
    }
}
