<?php

/**
 * Tools an operator has pre-approved past the necessity gate's approval step.
 *
 * The necessity gate asks a human before a *well-formed* risky call. That is the
 * right default for anything irreversible, externally visible or destructive.
 * It is the wrong tool for **spend**: a paid image generation is repeatable, its
 * output is not published by generating it, and the estate already has a cost
 * tracker and a token budget. Prompting for every render teaches the operator to
 * approve without reading — approval fatigue — which weakens the prompts that
 * actually matter (the four-eyes research is blunt about advisory controls:
 * a prompt that is routinely rubber-stamped is a log, not a control).
 *
 * So these slugs skip the approval step, and are covered by budget instead.
 *
 * ## What this does NOT waive
 *
 *  - **Not a validity waiver.** `block` is untouched, so a generation call with
 *    no prompt is still refused. This waives the *human decision*, not the
 *    check that the call is well-formed.
 *  - **Not a destruction waiver.** `cancel_higgsfield_request` is deliberately
 *    absent: it declares `destructive`, and "destructive still asks a human" is
 *    a rule worth keeping simple. Its flag is arguably over-declared, but that
 *    is a corpus decision, not an allowlist one.
 *  - **Not an estate override.** Each runtime may replace this list through its
 *    own configuration (`oos.necessity_gate.pre_approved` in Laravel,
 *    `wp_mcp_ai_necessity_gate_pre_approved` in WordPress). Passing an empty
 *    list restores the prompt.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Safety;

/**
 * The pre-approved corpus, shared so both runtimes agree.
 *
 * @since 2.1.0
 */
final class PreApprovedTools
{
    /**
     * Paid generation and edit tools.
     *
     * Every entry declares `consumes-tokens` + `external-api` + `write`, which
     * is a cost profile rather than an irreversible one. Keep this list to that
     * shape: a tool that can delete, send or publish does not belong here.
     *
     * @var list<string>
     */
    public const SLUGS = array(
        'edit_gemini_image',
        'edit_gemini_image_validated',
        'generate_gemini_image',
        'generate_gemini_image_validated',
        'generate_higgsfield_image',
        'generate_higgsfield_video',
        'generate_sora_video',
        'generate_sora_video_validated',
        'generate_veo_video',
        'generate_veo_video_validated',
    );

    /**
     * The default list.
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        return self::SLUGS;
    }

    /**
     * Whether a slug is pre-approved.
     *
     * `$override` distinguishes "not configured" (`null`, use the defaults) from
     * "configured to nothing" (`array()`, prompt for everything). That
     * distinction is the whole point: an operator who wants the prompts back
     * must be able to ask for them without naming every tool.
     *
     * @param string[]|null $override Operator-supplied list, or null for defaults.
     */
    public static function contains(string $slug, ?array $override = null): bool
    {
        return \in_array($slug, null === $override ? self::SLUGS : $override, true);
    }
}
