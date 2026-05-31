<?php
/**
 * Anthropic Claude provider client.
 *
 * Uses the Anthropic Messages API (not OpenAI-compatible).
 * POST https://api.anthropic.com/v1/messages
 * Requires: anthropic-version header, x-api-key header.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Infrastructure\Provider;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\SettingsStoreInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;

class AnthropicClient extends AbstractProviderClient {

	private const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';
	private const API_VERSION      = '2023-06-01';

	public function __construct(
		SettingsStoreInterface $settings,
		HttpClientInterface $http,
		ErrorFactoryInterface $errors,
	) {
		parent::__construct( $settings, $http, $errors );
		$this->providerSlug = 'anthropic';
	}

	protected function getDefaultBaseUrl(): string {
		return self::DEFAULT_BASE_URL;
	}

	public function chat( array $messages, array $options = array() ): mixed {
		$apiKey = $this->getApiKey();

		if ( '' === $apiKey ) {
			return $this->missingApiKeyError();
		}

		$model = $this->resolveModel( $options );

		// Separate system message from conversation.
		$system       = '';
		$convMessages = array();

		foreach ( $messages as $msg ) {
			if ( 'system' === ( $msg['role'] ?? '' ) ) {
				$system = is_string( $msg['content'] ?? null ) ? $msg['content'] : '';
			} else {
				$convMessages[] = $msg;
			}
		}

		$anthropicMessages = $this->convertMessages( $convMessages );

		$payload = array(
			'model'      => $model,
			'max_tokens' => (int) ( $options['max_tokens'] ?? 4096 ),
			'messages'   => $anthropicMessages,
		);

		if ( '' !== $system ) {
			$payload['system'] = $system;
		}

		if ( isset( $options['temperature'] ) ) {
			$payload['temperature'] = (float) $options['temperature'];
		}
		if ( isset( $options['top_p'] ) ) {
			$payload['top_p'] = (float) $options['top_p'];
		}
		if ( ! empty( $options['tools'] ) ) {
			$payload['tools'] = $this->convertTools( $options['tools'] );
		}
		if ( ! empty( $options['stop'] ) ) {
			$payload['stop_sequences'] = (array) $options['stop'];
		}

		try {
			$body = \json_encode( $payload, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return $this->errors->create( 'json_encode_failed', $e->getMessage() );
		}

		$headers = array(
			'x-api-key'         => $apiKey,
			'anthropic-version' => self::API_VERSION,
			'Content-Type'      => 'application/json',
		);

		try {
			$request    = new \Nyholm\Psr7\Request(
				'POST',
				$this->getBaseUrl() . '/messages',
				$headers,
				$body,
			);
			$response   = $this->http->sendRequest( $request );
			$statusCode = $response->getStatusCode();
			$respBody   = (string) $response->getBody();

			if ( $statusCode >= 400 ) {
				return $this->parseError( $statusCode, $respBody );
			}

			return $this->normalizeResponse(
				\json_decode( $respBody, true ) ?: array(),
				$model,
			);

		} catch ( \Psr\Http\Client\ClientExceptionInterface $e ) {
			return $this->errors->create( 'http_request_failed', $e->getMessage() );
		}
	}

	public function stream( array $messages, array $options = array(), ?callable $onChunk = null ): mixed {
		return $this->chat( $messages, $options );
	}

	public function listModels(): mixed {
		// Anthropic does not have a public models list endpoint.
		return array(
			'claude-opus-4-6',
			'claude-sonnet-4-6',
			'claude-haiku-4-6',
			'claude-3-5-sonnet-latest',
			'claude-3-5-haiku-latest',
		);
	}

	// ─── Message conversion ──────────────────────────────────────────

	private function convertMessages( array $messages ): array {
		$converted = array();

		foreach ( $messages as $msg ) {
			$role = $msg['role'] ?? 'user';

			// Anthropic uses 'user' or 'assistant' roles.
			if ( ! in_array( $role, array( 'user', 'assistant' ), true ) ) {
				$role = 'user';
			}

			if ( is_string( $msg['content'] ?? null ) ) {
				$converted[] = array(
					'role'    => $role,
					'content' => $msg['content'],
				);
			} elseif ( is_array( $msg['content'] ?? null ) ) {
				$contentBlocks = array();

				foreach ( $msg['content'] as $segment ) {
					if ( isset( $segment['text'] ) ) {
						$contentBlocks[] = array(
							'type' => 'text',
							'text' => $segment['text'],
						);
					} elseif ( isset( $segment['image_url']['url'] ) ) {
						$url = $segment['image_url']['url'];
						// Extract base64 data.
						$mediaType = 'image/jpeg';
						if ( \preg_match( '#^data:(image/\w+);base64,#', $url, $m ) ) {
							$mediaType = $m[1];
							$url       = \preg_replace( '#^data:image/\w+;base64,#', '', $url );
						}
						$contentBlocks[] = array(
							'type'   => 'image',
							'source' => array(
								'type'       => 'base64',
								'media_type' => $mediaType,
								'data'       => $url,
							),
						);
					}
				}

				if ( array() !== $contentBlocks ) {
					$converted[] = array(
						'role'    => $role,
						'content' => $contentBlocks,
					);
				}
			}
		}

		return $converted;
	}

	private function convertTools( array $tools ): array {
		$converted = array();

		foreach ( $tools as $tool ) {
			if ( isset( $tool['function'] ) ) {
				$converted[] = array(
					'name'         => $tool['function']['name'],
					'description'  => $tool['function']['description'] ?? '',
					'input_schema' => $tool['function']['parameters'] ?? array( 'type' => 'object' ),
				);
			}
		}

		return $converted;
	}

	/**
	 * Normalize Anthropic response to OpenAI-compatible shape.
	 */
	private function normalizeResponse( array $data, string $model ): array {
		$text      = '';
		$toolCalls = array();

		foreach ( $data['content'] ?? array() as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= ( $block['text'] ?? '' );
			} elseif ( 'tool_use' === ( $block['type'] ?? '' ) ) {
				$toolCalls[] = array(
					'id'       => $block['id'] ?? '',
					'type'     => 'function',
					'function' => array(
						'name'      => $block['name'] ?? '',
						'arguments' => \json_encode( $block['input'] ?? array() ),
					),
				);
			}
		}

		$message = array(
			'role'    => 'assistant',
			'content' => $text,
		);
		if ( array() !== $toolCalls ) {
			$message['tool_calls'] = $toolCalls;
		}

		return array(
			'id'      => $data['id'] ?? '',
			'object'  => 'chat.completion',
			'model'   => $model,
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => $message,
					'finish_reason' => ( 'tool_use' === ( $data['stop_reason'] ?? '' ) ) ? 'tool_calls' : 'stop',
				),
			),
			'usage'   => array(
				'prompt_tokens'     => $data['usage']['input_tokens'] ?? 0,
				'completion_tokens' => $data['usage']['output_tokens'] ?? 0,
				'total_tokens'      => ( $data['usage']['input_tokens'] ?? 0 ) + ( $data['usage']['output_tokens'] ?? 0 ),
			),
		);
	}

	private function parseError( int $statusCode, string $body ): mixed {
		$data = \json_decode( $body, true );
		$msg  = is_array( $data ) && isset( $data['error']['message'] )
			? $data['error']['message']
			: 'Anthropic API returned status ' . $statusCode;

		if ( 429 === $statusCode ) {
			return $this->errors->rateLimited( $msg );
		}

		return $this->errors->create( "http_{$statusCode}", $msg, array( 'status' => $statusCode ) );
	}
}
