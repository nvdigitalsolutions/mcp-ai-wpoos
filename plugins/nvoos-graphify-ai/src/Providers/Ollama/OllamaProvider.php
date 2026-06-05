<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Providers\Ollama;

use NvoosGraphifyAi\Contracts\ProviderClient;
use NvoosGraphifyAi\Settings;

/**
 * Ollama (local LLM) provider client.
 *
 * Connects to a local Ollama instance. No API key required.
 *
 * @since 1.0.0
 */
class OllamaProvider implements ProviderClient {

	public function chat( array $messages, array $options = array() ) {
		$baseUrl = Settings::get( 'ollama_base_url', 'http://localhost:11434' );
		$model   = $options['model'] ?? Settings::get( 'ollama_model', 'llama3.3' );

		$body = array(
			'model'    => $model,
			'messages' => $this->formatMessages( $messages ),
			'stream'   => false,
			'options'  => array(
				'temperature' => $options['temperature'] ?? Settings::get( 'ai_temperature', 0.7 ),
			),
		);

		if ( ! empty( $options['tools'] ) ) {
			$body['tools'] = $options['tools'];
		}

		$response = wp_remote_post(
			rtrim( $baseUrl, '/' ) . '/api/chat',
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
			$errorMessage = $data['error'] ?? sprintf( 'HTTP %d', $status );
			return new \WP_Error(
				'nvoos_graphify_ai_ollama_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Ollama error: %s', 'nvoos-graphify-ai' ),
					$errorMessage
				)
			);
		}

		$msg       = $data['message'] ?? array();
		$toolCalls = array();

		if ( ! empty( $msg['tool_calls'] ) ) {
			foreach ( $msg['tool_calls'] as $tc ) {
				$toolCalls[] = array(
					'id'   => wp_generate_uuid4(),
					'type' => 'function',
					'function' => array(
						'name'      => $tc['function']['name'] ?? '',
						'arguments' => is_array( $tc['function']['arguments'] ?? null )
							? wp_json_encode( $tc['function']['arguments'] )
							: ( $tc['function']['arguments'] ?? '{}' ),
					),
				);
			}
		}

		return array(
			'content'       => $msg['content'] ?? '',
			'usage'         => array(
				'prompt_tokens'     => $data['prompt_eval_count'] ?? 0,
				'completion_tokens' => $data['eval_count'] ?? 0,
				'total_tokens'      => ( $data['prompt_eval_count'] ?? 0 ) + ( $data['eval_count'] ?? 0 ),
			),
			'model'         => $model,
			'finish_reason' => $data['done_reason'] ?? null,
			'tool_calls'    => $toolCalls,
			'raw'           => $data,
		);
	}

	public function stream( array $messages, array $options = array(), ?callable $callback = null ) {
		return $this->chat( $messages, $options );
	}

	public function listModels() {
		$baseUrl = Settings::get( 'ollama_base_url', 'http://localhost:11434' );

		$response = wp_remote_get(
			rtrim( $baseUrl, '/' ) . '/api/tags',
			array( 'timeout' => 10 )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return array_map(
			static fn( array $m ): string => $m['name'] ?? '',
			$data['models'] ?? array()
		);
	}

	public function getProviderSlug(): string {
		return 'ollama';
	}

	private function formatMessages( array $messages ): array {
		$formatted = array();

		foreach ( $messages as $msg ) {
			$formatted[] = array(
				'role'    => $msg['role'] ?? 'user',
				'content' => $msg['content'] ?? '',
			);
		}

		return $formatted;
	}
}
