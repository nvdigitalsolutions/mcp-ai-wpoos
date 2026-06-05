<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Providers\Gemini;

use NvoosGraphifyAi\Contracts\ProviderClient;
use NvoosGraphifyAi\Settings;

/**
 * Google Gemini provider client.
 *
 * Uses Gemini's generateContent API.
 *
 * @since 1.0.0
 */
class GeminiProvider implements ProviderClient {

	private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

	public function chat( array $messages, array $options = array() ) {
		$apiKey = Settings::getApiKey( 'gemini' );
		if ( empty( $apiKey ) ) {
			return new \WP_Error(
				'nvoos_graphify_ai_missing_key',
				__( 'Gemini API key not configured.', 'nvoos-graphify-ai' )
			);
		}

		$model    = $options['model'] ?? 'gemini-2.5-flash';
		$contents = $this->formatContents( $messages );
		$systemInstruction = $this->extractSystemInstruction( $messages );

		$body = array(
			'contents'          => $contents,
			'generationConfig'  => array(
				'temperature'     => $options['temperature'] ?? Settings::get( 'ai_temperature', 0.7 ),
				'maxOutputTokens' => $options['max_tokens'] ?? (int) Settings::get( 'ai_max_tokens', 4096 ),
			),
		);

		if ( ! empty( $systemInstruction ) ) {
			$body['systemInstruction'] = array(
				'parts' => array( array( 'text' => $systemInstruction ) ),
			);
		}

		if ( ! empty( $options['tools'] ) ) {
			$body['tools'] = array( array( 'functionDeclarations' => $this->translateTools( $options['tools'] ) ) );
		}

		$response = wp_remote_post(
			self::API_BASE . "/models/{$model}:generateContent?key={$apiKey}",
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
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
				'nvoos_graphify_ai_gemini_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Gemini API error: %s', 'nvoos-graphify-ai' ),
					$errorMessage
				)
			);
		}

		$candidate = $data['candidates'][0] ?? array();
		$contentPart = $candidate['content'] ?? array();
		$parts       = $contentPart['parts'] ?? array();

		$textResponse = '';
		$toolCalls    = array();

		foreach ( $parts as $part ) {
			if ( ! empty( $part['text'] ) ) {
				$textResponse .= $part['text'];
			}
			if ( ! empty( $part['functionCall'] ) ) {
				$toolCalls[] = array(
					'id'   => wp_generate_uuid4(),
					'type' => 'function',
					'function' => array(
						'name'      => $part['functionCall']['name'] ?? '',
						'arguments' => wp_json_encode( $part['functionCall']['args'] ?? array() ),
					),
				);
			}
		}

		return array(
			'content'       => $textResponse,
			'usage'         => $data['usageMetadata'] ?? array(),
			'model'         => $model,
			'finish_reason' => $candidate['finishReason'] ?? null,
			'tool_calls'    => $toolCalls,
			'raw'           => $data,
		);
	}

	public function stream( array $messages, array $options = array(), ?callable $callback = null ) {
		return $this->chat( $messages, $options );
	}

	public function listModels() {
		return array( 'gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-1.5-pro', 'gemini-1.5-flash' );
	}

	public function getProviderSlug(): string {
		return 'gemini';
	}

	/**
	 * Format messages to Gemini contents format.
	 */
	private function formatContents( array $messages ): array {
		$contents   = array();
		$skipSystem = true;

		foreach ( $messages as $msg ) {
			$role = $msg['role'] ?? 'user';

			if ( 'system' === $role ) {
				continue; // Handled by systemInstruction.
			}

			$geminiRole = 'user';
			if ( 'assistant' === $role ) {
				$geminiRole = 'model';
			} elseif ( 'tool' === $role ) {
				$geminiRole = 'function';
			}

			$parts = array();

			if ( 'function' === $geminiRole ) {
				$parts[] = array(
					'functionResponse' => array(
						'name'     => '', // Gemini infers from context.
						'response' => array( 'name' => '', 'content' => $msg['content'] ?? '' ),
					),
				);
			} elseif ( 'model' === $geminiRole && ! empty( $msg['tool_calls'] ) ) {
				foreach ( $msg['tool_calls'] as $tc ) {
					$args = is_string( $tc['function']['arguments'] ?? '' )
						? json_decode( $tc['function']['arguments'], true )
						: ( $tc['function']['arguments'] ?? array() );

					$parts[] = array(
						'functionCall' => array(
							'name' => $tc['function']['name'] ?? '',
							'args' => is_array( $args ) ? $args : array(),
						),
					);
				}
			} elseif ( is_array( $msg['content'] ?? null ) ) {
				// Multi-part content (text + images).
				foreach ( $msg['content'] as $block ) {
					if ( 'text' === ( $block['type'] ?? '' ) ) {
						$parts[] = array( 'text' => $block['text'] ?? '' );
					} elseif ( 'image_url' === ( $block['type'] ?? '' ) ) {
						$url = $block['image_url']['url'] ?? '';
						$mimeType = 'image/jpeg';
						if ( 0 === strpos( $url, 'data:' ) ) {
							$mimeType = explode( ';', substr( $url, 5 ) )[0];
							$data = explode( ',', $url, 2 )[1] ?? '';
							$parts[] = array( 'inlineData' => array( 'mimeType' => $mimeType, 'data' => $data ) );
						}
					}
				}
			} else {
				$parts[] = array( 'text' => $msg['content'] ?? '' );
			}

			$contents[] = array(
				'role'  => $geminiRole,
				'parts' => $parts,
			);
		}

		return $contents;
	}

	/**
	 * Extract system instruction from messages.
	 */
	private function extractSystemInstruction( array $messages ): string {
		foreach ( $messages as $msg ) {
			if ( 'system' === ( $msg['role'] ?? '' ) ) {
				return $msg['content'] ?? '';
			}
		}
		return '';
	}

	/**
	 * Translate OpenAI-format tools to Gemini format.
	 */
	private function translateTools( array $tools ): array {
		$declarations = array();

		foreach ( $tools as $tool ) {
			if ( 'function' !== ( $tool['type'] ?? '' ) ) {
				continue;
			}

			$declarations[] = array(
				'name'        => $tool['function']['name'] ?? '',
				'description' => $tool['function']['description'] ?? '',
				'parameters'  => $tool['function']['parameters'] ?? array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
			);
		}

		return $declarations;
	}
}
