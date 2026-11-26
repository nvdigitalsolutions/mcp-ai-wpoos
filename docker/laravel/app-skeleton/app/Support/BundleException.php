<?php

namespace App\Support;

use RuntimeException;

/**
 * A portability request that cannot be served.
 *
 * Carries the HTTP status so the service stays free of response concerns, the
 * same shape as {@see BlueprintInstallException}. Every failure here is a 422:
 * the request was understood and refused, not missing (404) or forbidden (403).
 *
 * The foreign-id case deliberately says "not in this estate" rather than
 * distinguishing "does not exist" from "belongs to another estate". The caller
 * already knows the id it asked for; what it must not learn is whether the id
 * exists somewhere else.
 */
final class BundleException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function notABundle(): self
    {
        return new self('The payload is not an nvoos-assistant bundle.');
    }

    public static function unsupportedVersion(int $version): self
    {
        return new self(sprintf('Only nvoos-assistant format_version 1 is supported; this bundle is version %d.', $version));
    }

    public static function emptyBundle(): self
    {
        return new self('The bundle contains no assistants.');
    }

    /**
     * @param list<int> $ids
     */
    public static function unknownAssistants(array $ids): self
    {
        return new self(sprintf(
            'These assistant ids are not in this estate: %s.',
            implode(', ', array_map('strval', $ids))
        ));
    }

    public function status(): int
    {
        return $this->status;
    }
}
