<?php

namespace App\Support\SlashCommands;

use RuntimeException;

/**
 * A slash command that parsed but could not run.
 *
 * Carries the same `code` values the WordPress executor puts on its
 * `WP_Error`, so the console reads one error vocabulary on both runtimes.
 */
final class SlashCommandFailed extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message, 400);
    }
}
