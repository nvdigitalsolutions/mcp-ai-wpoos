<?php

/**
 * Output guard — response validation as a configurable chat collaborator.
 *
 * `OutputGuardrail` answers "what is in this response?". This answers the
 * narrower question the chat path needs: "what may I deliver?". It holds the
 * runtime's mode and strictness, and it returns a payload whose `content` is
 * already safe to render.
 *
 * The empty-string rule is the important one. When a response is blocked this
 * returns `content => ''` rather than the original text, so a caller that
 * forwards what it is given cannot leak the very thing that was just blocked.
 *
 * The orchestrator treats it as an optional collaborator, exactly like
 * `InputGuard`: an unconfigured runtime builds no guard and pays nothing.
 *
 * ## Licence
 *
 * @license MIT
 * @since   1.6.0
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Security;

use Nvoos\Core\Domain\Service\Harness\OutputGuardrail;

final class OutputGuard
{
	/** Shown in place of a blocked response. English, as `InputGuard::describe()` is. */
	public const WITHHELD_NOTICE = 'This response was withheld by the output guardrail.';

	private readonly string $mode;
	private readonly string $strictness;
	private readonly bool $enabled;

	/**
	 * Enums are normalised here rather than at use, so `mode()` and
	 * `strictness()` report what the guard will actually do. Config and profiles
	 * are both untrusted input.
	 */
	public function __construct(
		string $mode = OutputGuardrail::MODE_WARN,
		string $strictness = OutputGuardrail::STRICTNESS_MEDIUM,
		bool $enabled = true,
	) {
		$normalised = OutputGuardrail::normaliseConfig(
			array(
				'enabled' => $enabled,
				'mode' => $mode,
				'strictness' => $strictness,
			)
		);

		$this->mode = $normalised['mode'];
		$this->strictness = $normalised['strictness'];
		$this->enabled = $enabled;
	}

	/**
	 * Build a guard from a harness profile's `output_guard` block.
	 *
	 * Returns null when the layer is off, so the caller can skip wiring rather
	 * than carrying a guard that would never act.
	 */
	public static function fromProfile(array $profile): ?self
	{
		$config = isset($profile['output_guard']) && is_array($profile['output_guard'])
			? OutputGuardrail::normaliseConfig($profile['output_guard'])
			: null;

		if (null === $config || ! $config['enabled']) {
			return null;
		}

		return new self(
			mode: $config['mode'],
			strictness: $config['strictness'],
			enabled: true,
		);
	}

	public function enabled(): bool
	{
		return $this->enabled;
	}

	public function mode(): string
	{
		return $this->mode;
	}

	public function strictness(): string
	{
		return $this->strictness;
	}

	/**
	 * Validate one response.
	 *
	 * `content` is what may be delivered: unchanged when safe, annotated in
	 * `warn` mode, and **empty when blocked**. `detail` is an audit sentence and
	 * is not for the end user.
	 *
	 * @return array{
	 *   enabled: bool,
	 *   safe: bool,
	 *   blocked: bool,
	 *   findings: array<int, string>,
	 *   content: string,
	 *   detail: string
	 * }
	 */
	public function inspect(string $content): array
	{
		if (! $this->enabled) {
			return self::verdict(false, false, array(), $content, '');
		}

		$evaluation = OutputGuardrail::evaluate(
			$content,
			array(
				'enabled' => true,
				'mode' => $this->mode,
				'strictness' => $this->strictness,
			)
		);

		if ($evaluation['safe']) {
			return self::verdict(true, false, array(), $content, '');
		}

		$blocked = $evaluation['blocked'];

		return self::verdict(
			true,
			$blocked,
			$evaluation['findings'],
			$blocked ? '' : $evaluation['content'],
			self::describe($evaluation['findings'], $blocked),
		);
	}

	/**
	 * @param array<int, string> $findings
	 * @return array{
	 *   enabled: bool,
	 *   safe: bool,
	 *   blocked: bool,
	 *   findings: array<int, string>,
	 *   content: string,
	 *   detail: string
	 * }
	 */
	private static function verdict(bool $enabled, bool $blocked, array $findings, string $content, string $detail): array
	{
		return array(
			'enabled' => $enabled,
			'safe' => array() === $findings,
			'blocked' => $blocked,
			'findings' => $findings,
			'content' => $content,
			'detail' => $detail,
		);
	}

	/**
	 * Audit sentence naming the findings.
	 *
	 * @param array<int, string> $findings
	 */
	private static function describe(array $findings, bool $blocked): string
	{
		$verb = $blocked ? 'was blocked' : 'was annotated';

		return sprintf(
			'The response %s by the output guardrail: %s.',
			$verb,
			implode(', ', $findings)
		);
	}
}
