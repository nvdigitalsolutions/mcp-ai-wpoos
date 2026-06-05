<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Providers\Anthropic;

use NvoosGraphifyAi\Contracts\ProviderClient;
use NvoosGraphifyAi\Settings;

/**
 * Anthropic (Claude) provider client.
 *
 * Uses Anthropic's Messages API (https://docs.anthropic.com/en/api).
 *
 * @since 1.0.0
 */
class AnthropicProvider implements ProviderClient {

	private const API_BASE    = 'https://api.anthropic.com/v1';
	private const API_VERSION = '2023-06-01';

	public function chat( array $messages, array $options = array() ) {
		$apiKey = Settings::getApiKey( 'anthropic' );
		if ( empty( $apiKey ) ) {
			return new \WP_Error(
				'nvoos_graphify_ai_missing_key',
				__( 'Anthropic API key not configured.', 'nvoos-graphify-ai' )
			);
		}

		$model     = $options['model'] ?? 'claude-sonnet-4-20250514';
		$system    = $this->extractSystem( $messages );
		$formatted = $this->formatMessages( $messages );

		$body = array(
			'model'      => $model,
			'max_tokens' => $options['max_tokens'] ?? 4096,
			'messages'   => $formatted,
		);

		if ( ! empty( $system ) ) {
			$body['system'] = $system;
		}

		if ( ! empty( $options['tools'] ) ) {
			$body['tools'] = $this->translateTools( $options['tools'] );
		}

		$response = wp_remote_post(
			self::API_BASE . '/messages',
			array(
				'headers' => array(
					'x-api-key'         => $apiKey,
					'anthropic-version' => self::API_VERSION,
					'Content-Type'      => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 120,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			$errorMessage = $data['error']['message'] ?? sprintf( 'HTTP %d', $status );
			return new \WP_Error(
				'nvoos_graphify_ai_anthropic_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Anthropic API error: %s', 'nvoos-graphify-ai' ),
					$errorMessage
				)
			);
		}

		$content   = '';
		$toolCalls = array();

		foreach ( $data['content'] ?? array() as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$content .= $block['text'];
			} elseif ( 'tool_use' === ( $block['type'] ?? '' ) ) {
				$toolCalls[] = array(
					'id'       => $block['id'] ?? '',
					'type'     => 'function',
					'function' => array(
						'name'      => $block['name'] ?? '',
						'arguments' => wp_json_encode( $block['input'] ?? array() ),
					),
				);
			}
		}

		return array(
			'content'       => $content,
			'usage'         => $data['usage'] ?? array(),
			'model'         => $data['model'] ?? $model,
			'finish_reason' => $data['stop_reason'] ?? null,
			'tool_calls'    => $toolCalls,
			'raw'           => $data,
		);
	}

	public function stream( array $messages, array $options = array(), ?callable $callback = null ) {
		return $this->chat( $messages, $options );
	}

	public function listModels() {
		return array( 'claude-sonnet-4-20250514', 'claude-3-opus-20240229', 'claude-3-5-haiku-20241022' );
	}

	public function getProviderSlug(): string {
		return 'anthropic';
	}

	/**
	 * Extract system message from the messages array.
	 */
	private function extractSystem( array &$messages ): string {
		$system   = '';
		$filtered = array();

		foreach ( $messages as $msg ) {
			if ( 'system' === ( $msg['role'] ?? '' ) ) {
				$system = $msg['content'] ?? '';
			} else {
				$filtered[] = $msg;
			}
		}

		$messages = $filtered;
		return $system;
	}

	/**
	 * Format messages for Anthropic API (no system role in messages array).
	 */
	private function formatMessages( array $messages ): array {
		$formatted = array();

		foreach ( $messages as $msg ) {
			$role = $msg['role'] ?? 'user';

			// Anthropic uses 'user' and 'assistant' only.
			if ( 'tool' === $role ) {
				$formatted[] = array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'        => 'tool_result',
							'tool_use_id' => $msg['tool_call_id'] ?? '',
							'content'     => $msg['content'] ?? '',
						),
					),
				);
				continue;
			}

			if ( 'assistant' === $role && ! empty( $msg['tool_calls'] ) ) {
				$contentBlocks = array();
				foreach ( $msg['tool_calls'] as $tc ) {
					$args = is_string( $tc['function']['arguments'] ?? '' )
						? json_decode( $tc['function']['arguments'], true )
						: ( $tc['function']['arguments'] ?? array() );

					$contentBlocks[] = array(
						'type'  => 'tool_use',
						'id'    => $tc['id'] ?? '',
						'name'  => $tc['function']['name'] ?? '',
						'input' => is_array( $args ) ? $args : array(),
					);
				}
				$formatted[] = array(
					'role'    => 'assistant',
					'content' => $contentBlocks,
				);
				continue;
			}

			$formatted[] = array(
				'role'    => $role,
				'content' => $msg['content'] ?? '',
			);
		}

		return $formatted;
	}

	/**
	 * Translate OpenAI-format tools to Anthropic format.
	 */
	private function translateTools( array $tools ): array {
		$translated = array();

		foreach ( $tools as $tool ) {
			if ( 'function' !== ( $tool['type'] ?? '' ) ) {
				continue;
			}

			$translated[] = array(
				'name'         => $tool['function']['name'] ?? '',
				'description'  => $tool['function']['description'] ?? '',
				'input_schema' => $tool['function']['parameters'] ?? array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
			);
		}

		return $translated;
	}
}
