<?php

/**
 * Chat profile — a per-principal permission boundary over tool execution.
 *
 * WordPress has carried this since Proposal 015 (`WP_MCP_AI_Chat_Profile`): a
 * profile declares which capability flags are gated, and the enforcement gate
 * blocks any tool that carries one. This is the framework-agnostic half of the
 * port — the vocabulary and the decision — so the Laravel runtime enforces the
 * same boundary without a second copy of the flag list. The gated set is
 * {@see CapabilityFlagVocabulary::READ_ONLY_GATED}, which already mirrors
 * WordPress's, so `ReadOnlyToolGuard` and this profile cannot disagree.
 *
 * WHY THE DECISION LIVES HERE AND NOT IN THE MODEL OR THE TOOL
 *
 * The industry position is unanimous that this is a runtime control:
 *
 *  - MCP tool annotations are **hints**. The specification requires clients to
 *    treat them as untrusted, and an unannotated tool is assumed non-read-only
 *    and potentially destructive ("Tool Annotations as Risk Vocabulary",
 *    Model Context Protocol, 2026-03). A profile may not treat a tool's own
 *    self-description as permission.
 *  - OWASP LLM06:2025 (Excessive Agency) asks for least privilege enforced in
 *    the downstream system, with actions executed in the user's own security
 *    context — not merely requested of the model.
 *  - Claude Code's permission model is deny → ask → allow, with deny never
 *    overridable. A read-only mode that a prompt can argue past is not a mode.
 *
 * Hence the two readers below, and the difference matters:
 *
 *  - {@see self::fromSlug()} is **total** and falls back to `write`. It is the
 *    fail-safe floor WordPress's registry guarantees: a typo in stored data
 *    must not lock every chat out of tool access.
 *  - {@see self::tryFromSlug()} returns null. It is the **validating** form,
 *    used by a setter, where coercing a typo to `write` would silently widen
 *    the grant.
 *
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Security;

use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;

/**
 * An immutable chat profile.
 *
 * @since 1.4.0
 */
final class ChatProfile
{
    /**
     * Full-access profile slug. Gates nothing — the historical default.
     *
     * @var string
     */
    public const WRITE = 'write';

    /**
     * Read-only profile slug. Blocks writes, state changes and destructive
     * actions; read-only tools run freely.
     *
     * @var string
     */
    public const READ_ONLY = 'read-only';

    /**
     * The registry, built once.
     *
     * @var array<string, self>|null
     */
    private static ?array $catalogue = null;

    /**
     * @param list<string> $gatedFlags   Flags that disqualify a tool.
     * @param list<string> $allowedSlugs Slugs that always run.
     */
    private function __construct(
        private readonly string $slug,
        private readonly string $label,
        private readonly string $description,
        private readonly array $gatedFlags,
        private readonly array $allowedSlugs,
    ) {
    }

    /**
     * The non-restrictive profile.
     */
    public static function write(): self
    {
        return new self(
            self::WRITE,
            'Full access',
            'All tools allowed. Destructive actions still require confirmation.',
            array(),
            array(),
        );
    }

    /**
     * The restrictive profile.
     */
    public static function readOnly(): self
    {
        return new self(
            self::READ_ONLY,
            'Read-only',
            'Reads allowed. Writes, state changes and destructive actions are blocked.',
            CapabilityFlagVocabulary::READ_ONLY_GATED,
            array(),
        );
    }

    /**
     * Every registered profile, keyed by slug.
     *
     * @return array<string, self>
     */
    public static function catalogue(): array
    {
        return self::$catalogue ??= array(
            self::WRITE => self::write(),
            self::READ_ONLY => self::readOnly(),
        );
    }

    /**
     * Total reader: an unknown or blank slug becomes `write`.
     *
     * This is the fail-safe floor. It is deliberately *not* the reader a
     * setter uses — see {@see self::tryFromSlug()}.
     */
    public static function fromSlug(?string $slug): self
    {
        return self::tryFromSlug($slug) ?? self::write();
    }

    /**
     * Validating reader: null when the slug is not registered.
     *
     * A setter must use this. Falling back to `write` on a typo would turn an
     * unknown profile name into the widest possible grant.
     */
    public static function tryFromSlug(?string $slug): ?self
    {
        $slug = strtolower(trim((string) $slug));

        return '' === $slug ? null : (self::catalogue()[ $slug ] ?? null);
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function description(): string
    {
        return $this->description;
    }

    /**
     * @return list<string>
     */
    public function gatedFlags(): array
    {
        return $this->gatedFlags;
    }

    /**
     * @return list<string>
     */
    public function allowedSlugs(): array
    {
        return $this->allowedSlugs;
    }

    /**
     * Whether this profile gates anything at all.
     */
    public function isRestrictive(): bool
    {
        return array() !== $this->gatedFlags;
    }

    /**
     * Whether selecting this profile is the privileged direction.
     *
     * Leaving a restrictive profile is an *upgrade*; entering one is a
     * *downgrade*. The asymmetry is the whole point: a user who has been
     * downgraded must not be able to self-elevate by calling the same setter
     * that downgraded them. Which capability the upgrade needs is a transport
     * question (`manage_options` here, `wp_mcp_ai_change_chat_profile` in
     * WordPress); that it needs one is not.
     */
    public function requiresPrivilegeToSelect(): bool
    {
        return ! $this->isRestrictive();
    }

    /**
     * Advisory text for the system prompt, or an empty string.
     *
     * WordPress appends the same idea on `wp_mcp_ai_resolved_system_prompt`.
     * It is **not** a control — the guard is (§174) — it stops the model
     * retrying a write the server has already refused, which otherwise burns
     * turns on a result it cannot change.
     *
     * The wording follows the prompt-engineering guidance rather than the
     * obvious phrasing. "Do not attempt writes" is a negation: the model has
     * to hold the forbidden action in mind to refuse it, and OpenAI's and
     * Anthropic's guidance both say the positive form is the stronger one
     * ("state the desired behavior positively"). So this names the mode, says
     * what the boundary is, and describes what to do instead — including that
     * a blocked call is final, so the model does not loop.
     */
    public function promptHint(): string
    {
        if (! $this->isRestrictive()) {
            return '';
        }

        return sprintf(
            'System: this conversation runs in %s mode. Tools that change data are blocked at the execution layer. '
            . 'Gather what you need with read-only tools and answer from what you retrieved. '
            . 'If the task needs a change, describe the change, its target and its effect instead of performing it. '
            . 'A blocked call is final; carry on with the information you already have.',
            $this->label
        );
    }

    /**
     * Serialize for the REST catalogue and any panel that reads it.
     *
     * `is_default` names the registry's fallback, matching WordPress's
     * `to_array()`, not the site-configured default.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $selectable = true): array
    {
        return array(
            'slug'        => $this->slug,
            'label'       => $this->label,
            'description' => $this->description,
            'selectable'  => $selectable,
            'is_default'  => self::WRITE === $this->slug,
        );
    }
}
