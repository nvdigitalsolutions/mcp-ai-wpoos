<?php

namespace App\Support;

/**
 * Raised when a trigger exists but cannot fire.
 *
 * Carries the HTTP status the controller should answer with, so the reason
 * (disabled, no workflow, workflow gone) is not flattened into a generic 500.
 */
final class TriggerNotRunnable extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
