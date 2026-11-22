<?php

namespace App\Support\ToolQuota;

use RuntimeException;

/**
 * The caller is at or over a tool's daily quota — Proposal 149 §176.
 *
 * Typed so the `tools/pre_execute` listener can turn it into a refusal that
 * names the tool, the ceiling and when it resets. The agent loop treats this
 * exactly like any other denial: the tool does not run and the model is told
 * why, so it can answer from what it already has rather than retrying.
 */
final class ToolQuotaExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $toolSlug,
        public readonly int $used,
        public readonly int $limit,
        public readonly string $resetsAt,
    ) {
        parent::__construct(sprintf(
            'The daily token quota for "%s" is spent: %d of %d estimated tokens used. It resets at %s.',
            $toolSlug,
            $used,
            $limit,
            $resetsAt
        ));
    }
}
