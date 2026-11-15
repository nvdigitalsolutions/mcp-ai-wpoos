<?php

namespace App\Mcp\Client;

use RuntimeException;

/**
 * A failed MCP request.
 *
 * Carries the HTTP status and JSON-RPC error code because the two decide the
 * dialect fallback: `McpHandshakePolicy` classifies a failure from exactly
 * these two values plus the message.
 */
final class McpAppClientException extends RuntimeException
{
    /**
     * @param string               $message Human-readable failure.
     * @param int                  $status  HTTP status, 0 when the transport failed.
     * @param int|null             $rpcCode JSON-RPC error code, null when absent.
     * @param array<string, mixed> $context Method and URL context for diagnostics.
     */
    public function __construct(
        string $message,
        private readonly int $status = 0,
        private readonly ?int $rpcCode = null,
        private readonly array $context = array(),
    ) {
        parent::__construct($message);
    }

    /**
     * HTTP status, or 0 when the transport itself failed.
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * JSON-RPC error code, or null when the body carried none.
     */
    public function rpcCode(): ?int
    {
        return $this->rpcCode;
    }

    /**
     * Context for diagnostics: the method, the URL, the attempt.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
