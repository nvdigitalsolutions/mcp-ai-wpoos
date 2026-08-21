<?php
/**
 * ShareGPT dataset adapter.
 *
 * Parses the ShareGPT/Vicuna conversation format used across the fine-tuning
 * ecosystem (oobabooga, FastChat, TRL): a list of items, each carrying a
 * `conversations` array of `{from, value}` turns. Canonical role mapping
 * follows the community convention: `human` → user, `gpt` → assistant,
 * `system` → system; other roles (observation, function_call, tool) map to
 * the canonical tool role.
 *
 * @link    https://github.com/oobabooga/text-generation-webui/issues/7184
 * @link    https://docs.anyscale.com/llm/fine-tuning/data-preparation
 * @package WP_MCP_AI
 * @since   1.1.60
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter for ShareGPT-formatted conversation datasets.
 */
class WP_MCP_AI_Conversation_Import_Adapter_Sharegpt implements WP_MCP_AI_Conversation_Import_Adapter_Interface {

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
	 * @return \\Traversable|\\WP_Error
	 */
	public function extract( $decoded, array $options = array() ) {
		if ( ! $this->supports_decoded( $decoded ) ) {
			return new WP_Error(
				'wp_mcp_ai_import_sharegpt_shape',
				__( 'The file does not look like a ShareGPT conversation dataset.', 'mcp-ai-wpoos' )
			);
		}

		return $this->extract_all( $decoded, $options );
	}

	/**
	 * Yield canonical conversations from a validated ShareGPT dataset.
	 *
	 * @param mixed $decoded Validated decoded structure.
	 * @param array $options Extraction options.
	 * @return \\Generator
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
	 * @return WP_MCP_AI_Conversation_Import_Conversation|null
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
			if ( WP_MCP_AI_Conversation_Import_Conversation::ROLE_USER === $message['role'] ) {
				$title = wp_trim_words( $message['content'], 8, '...' );
				break;
			}
		}

		return WP_MCP_AI_Conversation_Import_Conversation::create(
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
			'human'         => WP_MCP_AI_Conversation_Import_Conversation::ROLE_USER,
			'user'          => WP_MCP_AI_Conversation_Import_Conversation::ROLE_USER,
			'gpt'           => WP_MCP_AI_Conversation_Import_Conversation::ROLE_ASSISTANT,
			'assistant'     => WP_MCP_AI_Conversation_Import_Conversation::ROLE_ASSISTANT,
			'system'        => WP_MCP_AI_Conversation_Import_Conversation::ROLE_SYSTEM,
			'observation'   => WP_MCP_AI_Conversation_Import_Conversation::ROLE_TOOL,
			'function_call' => WP_MCP_AI_Conversation_Import_Conversation::ROLE_TOOL,
			'tool'          => WP_MCP_AI_Conversation_Import_Conversation::ROLE_TOOL,
		);

		return isset( $map[ $role ] ) ? $map[ $role ] : '';
	}
}
