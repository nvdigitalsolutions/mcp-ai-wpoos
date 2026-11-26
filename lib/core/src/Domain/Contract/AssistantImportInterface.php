<?php

/**
 * Importing an assistant bundle, as a host capability.
 *
 * The Laravel runtime already has one import engine —
 * `App\Services\AssistantPortability::importJson()` — shared by the REST
 * portability controller and the `/admin` portability page. This contract is a
 * third transport onto it, and the reason is the same one that made export a
 * contract rather than a bundle builder: the engine owns the `nvoos-assistant`
 * v1 shape, the mode semantics and the credential denylist, and a second
 * implementation would drift from all three.
 *
 * ## Import is the restore path, which is why it is the wider capability
 *
 * `overwrite` replaces an existing assistant's prompt, provider and tool list.
 * That is not a side effect — it *is* restore, the operation an operator runs to
 * put a previous state back. WordPress gates `import_assistant` on
 * `manage_options` for exactly this reason, and this contract assumes the host
 * does the same: `edit_posts` is enough to add an assistant, not enough to
 * replace one.
 *
 * ## Modes are never coerced
 *
 * `skip`, `duplicate`, `overwrite`. A caller that names a mode this contract
 * does not know is refused by the tool before it reaches here; the engine's own
 * fallback to `skip` must never be the thing that decides an unknown mode meant
 * "do nothing", because a caller that asked to overwrite and got a silent skip
 * believes a restore happened.
 *
 * ## Status is part of the document
 *
 * A bundle records the status each assistant had. Import restores it, because a
 * restore that silently levels every assistant to draft is not a restore. There
 * is deliberately no override argument: re-levelling status during an import is
 * a different operation, and the one this runtime withholds from tools
 * elsewhere (`create_assistant` refuses `status`).
 *
 * ## The denylist runs here too
 *
 * The engine strips `_wp_mcp_ai_credentials` and MCP App tokens on the way in
 * (issue #8175), so a hand-edited or third-party bundle cannot plant a stored
 * secret. This contract does not restate that; it relies on the engine being the
 * only path.
 *
 * ## Return shape
 *
 * Success carries the engine's report — `dry_run`, `imported`, `updated`,
 * `skipped` and a per-assistant list of what happened. Failure is
 * `success: false` plus a message safe to show a model; every refusal is about
 * the payload, so `code` distinguishes `bundle_invalid` (the document is wrong;
 * fixing it may help) from `assistant_import_failed` (the host failed; retrying
 * may).
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface AssistantImportInterface
{
    /**
     * Import a bundle document into the host's current estate.
     *
     * @param string $json  The bundle document.
     * @param string $mode  One of `skip`, `duplicate`, `overwrite`.
     * @param bool   $dryRun Report what would happen without writing.
     *
     * @return array{
     *     success: bool,
     *     report?: array{dry_run: bool, imported: int, updated: int, skipped: int, assistants: list<array<string, mixed>>},
     *     code?: string,
     *     error?: string
     * }
     */
    public function import(string $json, string $mode = 'skip', bool $dryRun = false): array;
}
