<?php
/**
 * Toolkit research — draft one record from a natural-language query.
 *
 * @package  Nvoos\Core
 * @since    2.1.0
 * @license  MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Toolkit;

use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\ValueObject\ToolkitManifest;

/**
 * Drafts one toolkit record from a natural-language query.
 *
 * The manifest is the schema. The model is told exactly which columns exist,
 * their types, enum values and required flags, and the reply is filtered back
 * to those columns — a hallucinated field never reaches the workbench, and a
 * real field the model omits stays absent so the operator fills it in.
 *
 * The draft is not saved here. The host shows it for review and saves it
 * through the normal create endpoint, so one validation path (the manifest's
 * column rules) governs everything that reaches storage.
 *
 * @since 2.1.0
 */
final class ToolkitResearchService {

	/**
	 * @param ProviderRouter $providers Provider router (shared with chat).
	 */
	public function __construct(
		private readonly ProviderRouter $providers,
	) {}

	/**
	 * @param ToolkitManifest      $manifest Toolkit manifest.
	 * @param array<string, mixed> $entity   Entity config from the manifest.
	 * @param string               $query    Operator's description.
	 * @return array{draft: array<string, mixed>, provider: string, error: string|null, message: string|null}
	 */
	public function draft( ToolkitManifest $manifest, array $entity, string $query ): array {
		$query = trim( $query );

		if ( '' === $query ) {
			return $this->failure( 'invalid_query', 'Describe what the record should contain.' );
		}

		$response = $this->providers->chat(
			$this->messages( $manifest, $entity, $query ),
			array(
				'temperature' => 0.2,
				'max_tokens'  => 800,
			),
		);

		// A non-array answer is an error object: no provider is registered,
		// the key is missing, or the request failed.
		if ( ! is_array( $response ) ) {
			$message = $response instanceof \Throwable
				? $response->getMessage()
				: 'No AI provider is configured for this runtime.';

			return $this->failure( 'ai_unavailable', $message );
		}

		$content = $response['choices'][0]['message']['content'] ?? null;

		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return $this->failure( 'ai_invalid_response', 'The provider returned no content.' );
		}

		$decoded = json_decode( $this->stripCodeFence( $content ), true );

		if ( ! is_array( $decoded ) ) {
			return $this->failure( 'ai_invalid_response', 'The provider did not return a JSON object.' );
		}

		$draft = array();

		foreach ( $entity['columns'] ?? array() as $column ) {
			$key = (string) ( $column['key'] ?? '' );

			if ( '' !== $key && array_key_exists( $key, $decoded ) ) {
				$draft[ $key ] = $decoded[ $key ];
			}
		}

		if ( array() === $draft ) {
			return $this->failure( 'ai_invalid_response', 'The provider returned none of the record fields.' );
		}

		return array(
			'draft'    => $draft,
			'provider' => (string) ( $response['model'] ?? '' ),
			'error'    => null,
			'message'  => null,
		);
	}

	/**
	 * @param array<string, mixed> $entity Entity config.
	 * @return array<int, array{role: string, content: string}>
	 */
	private function messages( ToolkitManifest $manifest, array $entity, string $query ): array {
		return array(
			array( 'role' => 'system', 'content' => $this->systemPrompt( $manifest, $entity ) ),
			array( 'role' => 'user', 'content' => $query ),
		);
	}

	/**
	 * @param array<string, mixed> $entity Entity config.
	 */
	private function systemPrompt( ToolkitManifest $manifest, array $entity ): string {
		$label = (string) ( $entity['label'] ?? $entity['key'] ?? 'record' );

		$lines = array(
			'You draft one ' . $label . ' record for the ' . $manifest->name() . ' toolkit.',
			'Reply with a single JSON object and nothing else.',
			'Use only these keys:',
		);

		foreach ( $entity['columns'] ?? array() as $column ) {
			$type = (string) ( $column['type'] ?? 'string' );
			$line = '- ' . $column['key'] . ' (' . $type;

			$enum = $column['enum'] ?? null;

			if ( is_array( $enum ) && array() !== $enum ) {
				$line .= '; one of: ' . implode( ', ', array_map( 'strval', $enum ) );
			}

			if ( ! empty( $column['required'] ) ) {
				$line .= '; required';
			}

			$lines[] = $line . ')';
		}

		$lines[] = 'Omit a key when the query does not determine its value.';
		$lines[] = 'Never invent identifiers, SKUs or prices. Numbers must be JSON numbers, not strings.';

		return implode( "\n", $lines );
	}

	/**
	 * Models wrap JSON in a markdown fence often enough that stripping it is
	 * cheaper than a retry. The fence is the only thing removed.
	 */
	private function stripCodeFence( string $content ): string {
		$trimmed = trim( $content );

		if ( str_starts_with( $trimmed, '```' ) ) {
			$trimmed = preg_replace( '/^```[a-zA-Z]*\s*/', '', $trimmed ) ?? $trimmed;
			$trimmed = preg_replace( '/\s*```$/', '', $trimmed ) ?? $trimmed;
		}

		return trim( $trimmed );
	}

	/**
	 * @return array{draft: array<string, mixed>, provider: string, error: string, message: string}
	 */
	private function failure( string $code, string $message ): array {
		return array(
			'draft'    => array(),
			'provider' => '',
			'error'    => $code,
			'message'  => $message,
		);
	}
}
