<?php

/**
 * CapabilityFlagOverridesInterface — per-tool flag additions.
 *
 * WordPress lets an administrator mark a tool as dangerous from the
 * orchestration `tools` view (`wp_mcp_ai_tool_capability_flags`). This is the
 * port's read side of that: a host-supplied source of flags to **add** to what
 * a tool already declares.
 *
 * ## Add, never remove — the asymmetry is the security property
 *
 * There is no `removedFlags()`, and that is deliberate rather than unfinished.
 * An override that could remove `destructive` or `write` would be an authority
 * grant dressed as a setting: the estate could clear the very flag the read-only
 * profile and the necessity gate refuse on. Leaving removal unrepresentable in
 * the contract means no store, no page and no future caller can express it.
 *
 * The MCP specification's own position is that tool annotations are untrusted
 * hints and the contract belongs in the authorization layer or the runtime
 * (*Tool Annotations as Risk Vocabulary*). An addition-only override keeps the
 * runtime as the authority and lets it become stricter, never looser.
 *
 * ## Undeclared stays undeclared
 *
 * A tool that declares nothing is already refused by `blockUndeclared`. Adding
 * a non-gated flag to such a tool would flip it from "unknown" to "known and
 * safe", which is a widen. Implementations return additions, and the resolver
 * ignores them when there is nothing to add to — see
 * {@see \Nvoos\Core\Domain\Service\Safety\CapabilityFlagResolution}.
 *
 * @package Nvoos\Core
 * @since   2.2.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface CapabilityFlagOverridesInterface
{
    /**
     * Flags to add to this tool's declared set.
     *
     * Returning an empty array means "no override", which is the same as not
     * binding this contract at all.
     *
     * @return list<string> Canonical or alias spellings; the caller normalises.
     */
    public function addedFlags(string $slug): array;
}
