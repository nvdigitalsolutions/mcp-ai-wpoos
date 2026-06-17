<?php
/**
 * Google Gemini provider client.
 *
 * Uses the Gemini API (not OpenAI-compatible). Chat endpoint:
 * POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Provider;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class GeminiClient extends AbstractProviderClient {

	private const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

	public function __construct(
		SettingsStoreInterface $settings,
		HttpClientInterface $http,
		ErrorFactoryInterface $errors,
	) {
		parent::__construct( $settings, $http, $errors );
		$this->providerSlug = 'gemini';
	}

	protected function getDefaultBaseUrl(): string {
		return self::DEFAULT_BASE_URL;
	}

	public function chat( array $messages, array $options = array() ): mixed {
		$apiKey = $this->getApiKey();

		if ( '' === $apiKey ) {
			return $this->missingApiKeyError();
		}

		$model   = $this->resolveModel( $options );
		$baseUrl = $this->getBaseUrl();

		// Convert OpenAI-format messages to Gemini format.
		$contents   = $this->convertMessages( $messages );
		$systemText = $this->extractSystemInstruction( $messages );

		$payload = array(
			'contents' => $contents,
		);

		if ( '' !== $systemText ) {
			$payload['systemInstruction'] = array(
				'parts' => array( array( 'text' => $systemText ) ),
			);
		}

		if ( ! empty( $options['tools'] ) ) {
			$payload['tools'] = $this->convertToolsToGemini( $options['tools'] );
		}

		$generationConfig = array();
		if ( isset( $options['temperature'] ) ) {
			$generationConfig['temperature'] = (float) $options['temperature'];
		}
		if ( isset( $options['max_tokens'] ) ) {
			$generationConfig['maxOutputTokens'] = (int) $options['max_tokens'];
		}
		if ( isset( $options['top_p'] ) ) {
			$generationConfig['topP'] = (float) $options['top_p'];
		}
		if ( array() !== $generationConfig ) {
			$payload['generationConfig'] = $generationConfig;
		}

		$url = $baseUrl . '/models/' . \urlencode( $model ) . ':generateContent?key=' . \urlencode( $apiKey );

		try {
			$body = \json_encode( $payload, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return $this->errors->create( 'json_encode_failed', $e->getMessage() );
		}

		try {
			$response   = $this->http->send( 'POST', $url, array( 'Content-Type' => 'application/json' ), $body );
			$statusCode = $response->statusCode;
			$respBody   = $response->body;

			if ( $statusCode >= 400 ) {
				return $this->errors->create( "http_{$statusCode}", $respBody, array( 'status' => $statusCode ) );
			}

			return $this->normalizeResponse( \json_decode( $respBody, true ) ?: array(), $model );

		} catch ( \Exception $e ) {
			return $this->errors->create( 'http_request_failed', $e->getMessage() );
		}
	}

	public function stream( array $messages, array $options = array(), ?callable $onChunk = null ): mixed {
		$apiKey = $this->getApiKey();

		if ( '' === $apiKey ) {
			return $this->missingApiKeyError();
		}

		$model   = $this->resolveModel( $options );
		$baseUrl = $this->getBaseUrl();

		// Convert OpenAI-format messages to Gemini format.
		$contents   = $this->convertMessages( $messages );
		$systemText = $this->extractSystemInstruction( $messages );

		$payload = array(
			'contents' => $contents,
		);

		if ( '' !== $systemText ) {
			$payload['systemInstruction'] = array(
				'parts' => array( array( 'text' => $systemText ) ),
			);
		}

		$generationConfig = array();
		if ( isset( $options['temperature'] ) ) {
			$generationConfig['temperature'] = (float) $options['temperature'];
		}
		if ( isset( $options['max_tokens'] ) ) {
			$generationConfig['maxOutputTokens'] = (int) $options['max_tokens'];
		}
		if ( array() !== $generationConfig ) {
			$payload['generationConfig'] = $generationConfig;
		}

		$url = $baseUrl . '/models/' . \urlencode( $model ) . ':streamGenerateContent?alt=sse&key=' . \urlencode( $apiKey );

		try {
			$body = \json_encode( $payload, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return $this->errors->create( 'json_encode_failed', $e->getMessage() );
		}

		try {
			$request    = new \Nyholm\Psr7\Request(
				'POST',
				$url,
				array( 'Content-Type' => 'application/json' ),
				$body,
			);
			$response   = $this->http->sendRequest( $request );
			$statusCode = $response->getStatusCode();
			$respBody   = (string) $response->getBody();

			if ( $statusCode >= 400 ) {
				return $this->errors->create( "http_{$statusCode}", $respBody, array( 'status' => $statusCode ) );
			}

			// Parse Gemini SSE stream.
			$assembled = '';
			foreach ( \preg_split( "/\r?\n/", $respBody ) as $line ) {
				$line = \trim( $line );
				if ( '' === $line || 0 !== \strpos( $line, 'data: ' ) ) {
					continue;
				}
				$data = \substr( $line, 6 );
				$chunk = \json_decode( $data, true );
				if ( ! is_array( $chunk ) ) {
					continue;
				}
				if ( isset( $chunk['candidates'][0]['content']['parts'] ) ) {
					foreach ( $chunk['candidates'][0]['content']['parts'] as $part ) {
						if ( isset( $part['text'] ) ) {
							$assembled .= $part['text'];
							if ( null !== $onChunk ) {
								$onChunk( $part['text'] );
							}
						}
					}
				}
			}

			return $this->normalizeResponse(
				array(
					'candidates' => array(
						array(
							'content' => array(
								'parts' => array( array( 'text' => $assembled ) ),
							),
							'finishReason' => 'STOP',
						),
					),
				),
				$model,
			);

		} catch ( \Psr\Http\Client\ClientExceptionInterface $e ) {
			return $this->errors->create( 'http_request_failed', $e->getMessage() );
		}
	}

	public function listModels(): mixed {
		$apiKey = $this->getApiKey();
		if ( '' === $apiKey ) {
			return $this->missingApiKeyError();
		}

		$url = $this->getBaseUrl() . '/models?key=' . \urlencode( $apiKey );

		try {
			$response = $this->http->send( 'GET', $url );
			$data     = \json_decode( $response->body, true );

			if ( ! is_array( $data ) || ! isset( $data['models'] ) ) {
				return array();
			}

			$models = array();
			foreach ( $data['models'] as $m ) {
				if ( is_array( $m ) && isset( $m['name'] ) ) {
					// Extract model ID from "models/gemini-pro"
					$models[] = \str_replace( 'models/', '', $m['name'] );
				}
			}
			\sort( $models );
			return $models;
		} catch ( \Exception $e ) {
			return $this->errors->create( 'list_models_failed', $e->getMessage() );
		}
	}

	// ─── Gemini-specific message conversion ──────────────────────────

	/**
	 * Convert OpenAI-format messages to Gemini contents array.
	 */
	private function convertMessages( array $messages ): array {
		$contents = array();

		foreach ( $messages as $msg ) {
			$role = $msg['role'] ?? 'user';

			// Skip system messages — handled separately.
			if ( 'system' === $role ) {
				continue;
			}

			$geminiRole = 'assistant' === $role ? 'model' : 'user';

			// Build the parts array.
			$parts = array();

			if ( is_string( $msg['content'] ?? null ) ) {
				$parts[] = array( 'text' => $msg['content'] );
			} elseif ( is_array( $msg['content'] ?? null ) ) {
				foreach ( $msg['content'] as $segment ) {
					if ( isset( $segment['text'] ) ) {
						$parts[] = array( 'text' => $segment['text'] );
					} elseif ( isset( $segment['image_url']['url'] ) ) {
						$parts[] = array(
							'inlineData' => array(
								'mimeType' => 'image/jpeg',
								'data'     => \preg_replace(
									'#^data:image/\w+;base64,#',
									'',
									$segment['image_url']['url'],
								),
							),
						);
					}
				}
			}

			if ( array() === $parts ) {
				continue;
			}

			// Merge consecutive messages from the same role.
			$last = \count( $contents ) - 1;
			if ( $last >= 0 && $contents[ $last ]['role'] === $geminiRole ) {
				$contents[ $last ]['parts'] = \array_merge(
					$contents[ $last ]['parts'],
					$parts,
				);
			} else {
				$contents[] = array(
					'role'  => $geminiRole,
					'parts' => $parts,
				);
			}
		}

		return $contents;
	}

	private function extractSystemInstruction( array $messages ): string {
		foreach ( $messages as $msg ) {
			if ( ( 'system' === ( $msg['role'] ?? '' ) ) && is_string( $msg['content'] ?? null ) ) {
				return $msg['content'];
			}
		}
		return '';
	}

	/**
	 * Convert OpenAI tool definitions to Gemini function declarations.
	 */
	private function convertToolsToGemini( array $tools ): array {
		$declarations = array();

		foreach ( $tools as $tool ) {
			if ( isset( $tool['function'] ) ) {
				$declarations[] = array(
					'name'        => $tool['function']['name'],
					'description' => $tool['function']['description'] ?? '',
					'parameters'  => $tool['function']['parameters'] ?? array(),
				);
			}
		}

		return array( array( 'functionDeclarations' => $declarations ) );
	}

	/**
	 * Normalize Gemini response to the OpenAI-compatible shape expected
	 * by the agentic loop and frontend.
	 */
	private function normalizeResponse( array $data, string $model ): array {
		$text = '';

		if ( isset( $data['candidates'][0]['content']['parts'] ) ) {
			foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
		}

		$finishReason = $data['candidates'][0]['finishReason'] ?? 'STOP';

		// Map Gemini finish reasons to OpenAI.
		$reasonMap = array(
			'STOP'                    => 'stop',
			'MAX_TOKENS'              => 'length',
			'SAFETY'                  => 'content_filter',
			'RECITATION'              => 'content_filter',
			'MALFORMED_FUNCTION_CALL' => 'tool_calls',
		);

		$usage = array();
		if ( isset( $data['usageMetadata'] ) ) {
			$usage = array(
				'prompt_tokens'     => $data['usageMetadata']['promptTokenCount'] ?? 0,
				'completion_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
				'total_tokens'      => $data['usageMetadata']['totalTokenCount'] ?? 0,
			);
		}

		return array(
			'id'      => $data['candidates'][0]['content']['parts'][0]['text'] ?? '',
			'object'  => 'chat.completion',
			'model'   => $model,
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => 'assistant',
						'content' => $text,
					),
					'finish_reason' => $reasonMap[ $finishReason ] ?? 'stop',
				),
			),
			'usage'   => $usage,
		);
	}
}
