<?php
/**
 * ShareGPT dataset adapter (Wave E4, sub-cluster 6).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Conversation_Import_Adapter_Sharegpt`: byte-identical
 * `conversations`-array structural detection, the community-standard
 * role map (human/user → user, gpt/assistant → assistant, system →
 * system, observation/function_call/tool → tool), the
 * `sharegpt-{n}` synthetic source-ID fallback, and the first-user-turn
 * title derivation.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - `WP_Error` is fully qualified (`\WP_Error`).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\ConversationImport
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ConversationImport;

/**
 * Adapter for ShareGPT-formatted conversation datasets.
 *
 * @since 2.1.0
 */
class AdapterSharegpt implements AdapterInterface {

	/**
	 * {@inheritdoc}
	 */
	public function get_platform() {
		return 'sharegpt';
	}

	/**
	 * Whether the decoded structure is a ShareGPT dataset.
	 *
	 * @param mixed $decoded Result of JSON/JSONL decoding.
	 * @return bool
	 */
	public function supports_decoded( $decoded ) {
		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			return false;
		}

		$first = reset( $decoded );

		return is_array( $first ) && isset( $first['conversations'] ) && is_array( $first['conversations'] );
	}

	/**
	 * Extract canonical conversations from a decoded ShareGPT dataset.
	 *
	 * @param mixed $decoded Result of JSON/JSONL decoding.
	 * @param array $options Extraction options.
	 * @return \Traversable|\WP_Error
	 */
	public function extract( $decoded, array $options = array() ) {
		if ( ! $this->supports_decoded( $decoded ) ) {
			return new \WP_Error(
				'wp_mcp_ai_import_sharegpt_shape',
				__( 'The file does not look like a ShareGPT conversation dataset.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return $this->extract_all( $decoded, $options );
	}

	/**
	 * Yield canonical conversations from a validated ShareGPT dataset.
	 *
	 * @param mixed $decoded Validated decoded structure.
	 * @param array $options Extraction options.
	 * @return \Generator
	 */
	protected function extract_all( $decoded, array $options ) {
		foreach ( $decoded as $index => $raw ) {
			if ( ! is_array( $raw ) || empty( $raw['conversations'] ) || ! is_array( $raw['conversations'] ) ) {
				continue;
			}

			$conversation = $this->build_conversation( $raw, $index );
			if ( null !== $conversation ) {
				yield $conversation;
			}
		}
	}

	/**
	 * Build one canonical conversation from a raw ShareGPT item.
	 *
	 * @param array $raw   Raw ShareGPT item.
	 * @param int   $index Position in the dataset (for synthetic source IDs).
	 * @return Conversation|null
	 */
	protected function build_conversation( array $raw, $index ) {
		$source_id = isset( $raw['id'] ) ? sanitize_text_field( (string) $raw['id'] ) : '';
		if ( '' === $source_id ) {
			$source_id = 'sharegpt-' . ( $index + 1 );
		}

		$messages = array();
		foreach ( $raw['conversations'] as $turn ) {
			$normalised = $this->normalise_turn( $turn );
			if ( null !== $normalised ) {
				$messages[] = $normalised;
			}
		}

		if ( empty( $messages ) ) {
			return null;
		}

		$title = $source_id;
		foreach ( $messages as $message ) {
			if ( Conversation::ROLE_USER === $message['role'] ) {
				$title = wp_trim_words( $message['content'], 8, '...' );
				break;
			}
		}

		return Conversation::create(
			$this->get_platform(),
			$source_id,
			$title,
			0,
			0,
			'',
			$messages
		);
	}

	/**
	 * Normalise one ShareGPT turn into the canonical message shape.
	 *
	 * @param array $turn Raw `{from, value}` turn.
	 * @return array|null Null for unparseable turns.
	 */
	protected function normalise_turn( array $turn ) {
		if ( ! isset( $turn['from'] ) || ! is_string( $turn['from'] ) ) {
			return null;
		}

		$role = $this->map_role( sanitize_key( $turn['from'] ) );
		if ( '' === $role ) {
			return null;
		}

		$value = isset( $turn['value'] ) ? (string) $turn['value'] : '';
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		return array(
			'role'      => $role,
			'content'   => $value,
			'timestamp' => 0,
			'hidden'    => false,
			'metadata'  => array(),
		);
	}

	/**
	 * Map a ShareGPT role string to a canonical role.
	 *
	 * @param string $role Raw role slug.
	 * @return string Canonical role, or '' to skip the turn.
	 */
	protected function map_role( $role ) {
		$map = array(
			'human'         => Conversation::ROLE_USER,
			'user'          => Conversation::ROLE_USER,
			'gpt'           => Conversation::ROLE_ASSISTANT,
			'assistant'     => Conversation::ROLE_ASSISTANT,
			'system'        => Conversation::ROLE_SYSTEM,
			'observation'   => Conversation::ROLE_TOOL,
			'function_call' => Conversation::ROLE_TOOL,
			'tool'          => Conversation::ROLE_TOOL,
		);

		return isset( $map[ $role ] ) ? $map[ $role ] : '';
	}
}
