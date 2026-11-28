<?php

/**
 * Queue handler for `db_export` (Proposal 167 phase P7, task 7.1).
 *
 * `ExportTool` enqueues this class by name; `NvoosToolJob` resolves it from the
 * container and calls `handle()`. It lives in the core package next to the tool
 * for two reasons: the tool must be able to name the handler without depending
 * on an adapter, and the handler must be resolvable on any host that binds the
 * queue port — not only Laravel.
 *
 * ## What it does not do
 *
 * It does not re-implement the export. It re-enters the tool, so the worker
 * applies the grant in force **when it runs**, not when the job was queued: a
 * scope that was narrowed, re-tiered or deleted in between refuses, and masking
 * and the caps are recomputed. A queue payload holds arguments and a principal,
 * never a resolved scope.
 *
 * ## Failure
 *
 * A refusal is thrown, so the queue's own failure path records it and the job
 * reaches a terminal `failed` state. Returning a failure envelope instead would
 * mark a refused export as `completed`, which is the one thing a poll surface
 * must not do. `ErrorFactoryInterface::isError()` is the framework-agnostic
 * `is_wp_error()`: a host may represent a refusal as a throwable *or* as an
 * array, and both are converted to a throw here.
 *
 * @package Nvoos\Core
 * @since   2.7.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Database;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use RuntimeException;
use Throwable;

final class DatabaseExportHandler
{
    public function __construct(
        private readonly ExportTool $tool,
        private readonly ErrorFactoryInterface $errors,
    ) {
    }

    /**
     * Execute a queued export.
     *
     * @param array<string, mixed> $payload `arguments` and `context` from the enqueue.
     * @return array<string, mixed> The tool's success envelope, stored as the job result.
     * @throws Throwable When the export is refused or fails.
     */
    public function handle(array $payload): array
    {
        $arguments = isset($payload['arguments']) && is_array($payload['arguments']) ? $payload['arguments'] : array();
        $context   = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : array();

        // Defensive: the worker must never re-enter the async branch.
        unset($arguments['async']);

        $result = $this->tool->execute($arguments, $context);

        if ($result instanceof Throwable) {
            throw $result;
        }

        if (! is_array($result) || $this->errors->isError($result)) {
            $normalised = $this->errors->normalize(is_array($result) ? $result : null);

            throw new RuntimeException(sprintf(
                '%s: %s',
                (string) ($normalised['code'] ?? 'db_export_failed'),
                (string) ($normalised['message'] ?? 'The database export was refused.'),
            ));
        }

        return $result;
    }
}
