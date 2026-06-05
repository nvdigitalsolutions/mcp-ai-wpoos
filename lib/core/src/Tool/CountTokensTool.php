<?php
/**
 * Count Tokens tool — heuristic token estimation for text and messages.
 *
 * Provides fast approximate token counts without external dependencies.
 * Uses the common ~4 characters-per-token heuristic and includes message
 * overhead estimates per ChatGPT convention.
 *
 * Zero external dependencies — only ErrorFactoryInterface.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

class CountTokensTool extends AbstractTool {

	/**
	 * Approximate characters per token (GPT-3.5/4 heuristic).
	 */
	private const CHARS_PER_TOKEN = 4;

	/**
	 * Token overhead per message (im_start, role, im_end formatting).
	 */
	private const MESSAGE_OVERHEAD = 4;

	/**
	 * Token overhead for priming the assistant's reply.
	 */
	private const REPLY_PRIMING = 3;

	/**
	 * Model context limits (max input + output tokens).
	 *
	 * @var array<string, int>
	 */
	private const MODEL_LIMITS = array(
		'gpt-5'              => 256000,
		'gpt-5-mini'         => 256000,
		'gpt-5-nano'         => 256000,
		'gpt-4o'             => 128000,
		'gpt-4o-mini'        => 128000,
		'gpt-4.1'            => 1048576,
		'gpt-4.1-mini'       => 1048576,
		'gpt-4.1-nano'       => 1048576,
		'o4-mini'            => 200000,
		'o3'                 => 200000,
		'o3-mini'            => 200000,
		'claude-opus-4-6'    => 200000,
		'claude-sonnet-4-6'  => 200000,
		'claude-haiku-4-6'   => 200000,
		'gemini-2.5-pro'     => 1048576,
		'gemini-2.5-flash'   => 1048576,
		'gemini-2.0-flash'   => 1048576,
		'gemini-2.0-flash-lite' => 1048576,
		'deepseek-chat'      => 131072,
		'deepseek-reasoner'  => 131072,
		'kimi-k2.6'          => 131072,
	);

	public function getSlug(): string {
		return 'count_tokens';
	}

	public function getName(): string {
		return 'Count Tokens';
	}

	public function getDescription(): string {
		return 'Estimates token counts for text or chat messages using ~4 chars/token heuristic. Includes message overhead estimates and model context limits.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'text'     => array(
					'type'        => 'string',
					'description' => 'Plain text to count tokens for.',
				),
				'messages' => array(
					'type'        => 'array',
					'description' => 'Array of chat messages with role and content.',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'role'    => array( 'type' => 'string' ),
							'content' => array( 'type' => 'string' ),
						),
					),
				),
				'model'    => array(
					'type'        => 'string',
					'description' => 'Optional model identifier to include context limit info.',
				),
			),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$text     = $this->stringParam( $arguments, 'text' );
		$messages = $arguments['messages'] ?? null;
		$model    = $this->stringParam( $arguments, 'model' );

		$tokens = 0;
		$method = 'heuristic';

		if ( '' !== $text ) {
			$tokens = $this->countTextTokens( $text );
			$note   = 'Plain text token count.';
		} elseif ( is_array( $messages ) && array() !== $messages ) {
			$tokens = $this->countMessageTokens( $messages );
			$note   = sprintf(
				'Includes %d message(s) with formatting overhead.',
				count( $messages ),
			);
		} else {
			return $this->errors->validationFailed(
				'Either text or messages parameter is required.',
				array( 'input' => array( 'Provide either text or messages.' ) ),
			);
		}

		$data = array(
			'tokens'        => $tokens,
			'method'        => $method,
			'chars_per_token' => self::CHARS_PER_TOKEN,
			'note'          => $note,
		);

		if ( '' !== $model ) {
			$limit = self::MODEL_LIMITS[ $model ] ?? null;
			if ( null !== $limit ) {
				$data['model']         = $model;
				$data['context_limit'] = $limit;
				$data['remaining']     = max( 0, $limit - $tokens );
				$data['utilization_pct'] = round( ( $tokens / $limit ) * 100, 1 );
			}
		}

		return $this->success(
			sprintf( 'Estimated %d tokens.', $tokens ),
			$data,
		);
	}

	/**
	 * Count tokens in plain text using the heuristic method.
	 */
	private function countTextTokens( string $text ): int {
		return max( 1, (int) ceil( strlen( $text ) / self::CHARS_PER_TOKEN ) );
	}

	/**
	 * Count tokens in a conversation messages array.
	 *
	 * Includes per-message formatting overhead.
	 */
	private function countMessageTokens( array $messages ): int {
		$total = self::REPLY_PRIMING; // Assistant reply priming.

		foreach ( $messages as $message ) {
			// Per-message overhead.
			$total += self::MESSAGE_OVERHEAD;

			// Content tokens.
			if ( isset( $message['content'] ) && is_string( $message['content'] ) ) {
				$total += $this->countTextTokens( $message['content'] );
			}

			// Role tokens (approximate).
			if ( isset( $message['role'] ) && is_string( $message['role'] ) ) {
				$total += max( 1, (int) ceil( strlen( $message['role'] ) / self::CHARS_PER_TOKEN ) );
			}
		}

		return max( 1, $total );
	}
}
