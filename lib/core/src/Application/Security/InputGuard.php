<?php

/**
 * Input guard — prompt-injection blocking and PII redaction for chat input.
 *
 * Composes the shared detector and filter so a runtime can enforce both
 * controls from one object. The orchestrator treats it as an optional
 * collaborator: when a runtime does not configure it, nothing changes.
 *
 * Blocking follows the WordPress harness semantics:
 *
 *  - `log`   — record the verdict, never block.
 *  - `warn`  — block jailbreak and injection; off-topic only at `high`
 *              strictness (a diversion is a hint, not an attack).
 *  - `block` — block anything that is not `safe`.
 *
 * PII is redacted, never blocked: a user's own phone number in their own
 * message is not an attack, but it must not reach long-lived storage.
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

use Nvoos\Core\Domain\Service\Harness\PiiFilter;
use Nvoos\Core\Domain\Service\Harness\PromptInjectionDetector;

final class InputGuard
{
	public const MODE_LOG = 'log';
	public const MODE_WARN = 'warn';
	public const MODE_BLOCK = 'block';

	/** How many matched-pattern labels are quoted back to the caller. */
	public const MAX_REPORTED_MATCHES = 3;

	public function __construct(
		private readonly string $mode = self::MODE_WARN,
		private readonly string $strictness = PromptInjectionDetector::STRICTNESS_MEDIUM,
		private readonly bool $piiEnabled = true,
		private readonly bool $injectionEnabled = true,
	) {
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
	 * Analyse one message.
	 *
	 * @return array{
	 *   blocked: bool,
	 *   result: string,
	 *   family: string,
	 *   matches: array<int, string>,
	 *   confidence: float,
	 *   severity: string,
	 *   detail: string
	 * }
	 */
	public function inspect(string $message): array
	{
		$analysis = PromptInjectionDetector::analyze($message, $this->strictness);

		$blocked = $this->injectionEnabled
			&& self::MODE_LOG !== $this->mode
			&& PromptInjectionDetector::RESULT_SAFE !== $analysis['result']
			&& (
				self::MODE_BLOCK === $this->mode
				|| PromptInjectionDetector::RESULT_JAILBREAK === $analysis['result']
				|| PromptInjectionDetector::RESULT_INJECTION === $analysis['result']
				|| (
					PromptInjectionDetector::STRICTNESS_HIGH === $this->strictness
					&& PromptInjectionDetector::RESULT_OFF_TOPIC === $analysis['result']
				)
			);

		return array(
			'blocked'    => $blocked,
			'result'     => $analysis['result'],
			'family'     => $analysis['family'],
			'matches'    => $analysis['matches'],
			'confidence' => $analysis['confidence'],
			'severity'   => $analysis['severity'],
			'detail'     => $blocked ? self::describe($analysis) : '',
		);
	}

	/**
	 * Redact PII / secrets before a write. A no-op when the filter is off.
	 *
	 * @return array{text: string, redactions: int}
	 */
	public function redact(string $text): array
	{
		if (! $this->piiEnabled) {
			return array( 'text' => $text, 'redactions' => 0 );
		}

		return PiiFilter::scrub($text);
	}

	/**
	 * Human sentence naming the family and the first few matched patterns.
	 *
	 * @param array{result: string, family: string, matches: array<int, string>} $analysis
	 */
	private static function describe(array $analysis): string
	{
		$subject = match ($analysis['result']) {
			PromptInjectionDetector::RESULT_JAILBREAK => 'a jailbreak attempt',
			PromptInjectionDetector::RESULT_INJECTION => 'a prompt-injection attempt',
			PromptInjectionDetector::RESULT_OFF_TOPIC => 'off-topic content',
			default => 'disallowed content',
		};

		$message = sprintf('This message was blocked because it looked like %s.', $subject);

		$matches = array_slice($analysis['matches'], 0, self::MAX_REPORTED_MATCHES);
		if (! empty($matches)) {
			$message .= ' Signals: ' . implode('; ', $matches) . '.';
		}

		return $message;
	}
}
