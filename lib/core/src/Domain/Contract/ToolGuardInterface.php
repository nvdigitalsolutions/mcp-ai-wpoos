<?php
/**
 * ToolGuard — monotonic, deny-only final pre-dispatch policy.
 *
 * Guards run after the extensible tools/pre_execute waterfall and before
 * the tool body. A guard returns a denial reason or null; there is no
 * "allow" result, so listener ordering can never turn a denial back into
 * permission. Mirrors deepseek-harness ToolGuard semantics.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ToolGuardInterface {

	/**
	 * Evaluate one pending tool call.
	 *
	 * @param string $slug      Canonical tool slug about to execute.
	 * @param array  $arguments Parsed, sanitized tool arguments.
	 * @param array  $context   Execution context (user_id, assistant_id, …).
	 *
	 * @return string|null  Denial reason to fail the call with, or null to
	 *                      leave the call allowed.
	 */
	public function evaluate( string $slug, array $arguments, array $context ): ?string;
}
