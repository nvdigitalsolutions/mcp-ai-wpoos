<?php

namespace App\Support;

use RuntimeException;

/**
 * A blueprint install that cannot proceed.
 *
 * Carries the HTTP status the controller should answer with, so the service
 * stays free of response concerns while the two failure modes stay distinct:
 * a missing blueprint is a 404, an existing assistant without `overwrite` is a
 * 409 (WordPress returns `blueprint_duplicate`).
 */
final class BlueprintInstallException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    public static function notFound(string $slug): self
    {
        return new self(sprintf('Blueprint "%s" not found.', $slug), 404);
    }

    public static function duplicate(string $title): self
    {
        return new self(
            sprintf('An assistant named "%s" already exists. Set overwrite=true to replace it.', $title),
            409
        );
    }

    public function status(): int
    {
        return $this->status;
    }
}
