<?php

/**
 * Exporting assistants as a portable bundle, as a host capability.
 *
 * The Laravel runtime already has one export engine —
 * `App\Services\AssistantPortability` — shared by the REST controller and the
 * `/admin` portability page. This contract is a third transport onto it, not a
 * third implementation. The reason is not tidiness: the engine is where the
 * `nvoos-assistant` v1 shape and the credential denylist live, and a tool that
 * assembled its own bundle would emit a document the other two surfaces cannot
 * reproduce — or, worse, one that carries a credential key the engine strips.
 *
 * ## The estate is ambient, never a parameter
 *
 * An assistant is estate-owned configuration: its system prompt, provider and
 * tool list are the estate's. A parameter that named the estate would be a
 * cross-estate read primitive, so the host takes it from its own scope — the
 * same rule `AssistantWriteInterface` applies to writes.
 *
 * ## Unknown ids are refused, not dropped
 *
 * A bundle that quietly contains fewer assistants than were asked for is how a
 * restore lands incomplete and nobody notices until the missing assistant is
 * needed. An id that does not exist and an id that belongs to another estate are
 * reported identically: whether a row exists elsewhere is not the caller's
 * business.
 *
 * ## Return shape
 *
 * Success carries the canonical bundle — `format`, `format_version`,
 * `plugin_version`, `exported_at` and `assistants` — so a caller can write it
 * out without knowing this contract's envelope. Failure is `success: false`
 * plus a message safe to show a model. `code` distinguishes the two refusals a
 * caller can act on differently: `assistant_not_found` (ask for a different id)
 * and `assistant_export_failed` (the host failed; retrying may work).
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface AssistantExportInterface
{
    /**
     * Build an `nvoos-assistant` bundle for the host's current estate.
     *
     * @param list<int>|null $ids Null exports every assistant in the estate; a
     *                            list exports exactly those and is refused
     *                            outright when any id is not in the estate.
     *
     * @return array{
     *     success: bool,
     *     bundle?: array{format: string, format_version: int, plugin_version: string, exported_at: string, assistants: list<array<string, mixed>>},
     *     code?: string,
     *     error?: string
     * }
     */
    public function export(?array $ids = null): array;
}
