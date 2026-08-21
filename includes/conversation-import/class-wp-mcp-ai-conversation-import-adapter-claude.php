<?php
/**
 * Anthropic Claude data export adapter.
 *
 * Parses claude.ai's `conversations.jsonl` data export (Settings → Privacy →
 * Export data). The file is JSONL with one conversation per line; each line
 * carries `uuid`, `name`, ISO timestamps, and a `chat_messages` list whose
 * entries use `sender` (`human` / `assistant`) and either a plain `text`
 * field or structured `content` blocks in newer exports.
 *
 * @link    https://xtrace.ai/blog/export-claude-conversations
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
 * Adapter for Anthropic Claude conversations.jsonl exports.
 */
class WP_MCP_AI_Conversation_Import_Adapter_Claude implements WP_MCP_AI_Conversation_Import_Adapter_Interface {

	const SENDER_HUMAN     = 'human';
	const SENDER_ASSISTANT = 'assistant';

	/**
	 * {@inheritdoc}
	 */
	public function get_platform() {
		return 'claude';
	}

	/**
	 * Whether the decoded structure is a Claude conversations.jsonl list.
	 *
	 * @param mixed $decoded Result of JSON/JSONL decoding.
	 * @return bool
	 */
	public function supports_decoded( $decoded ) {
		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			return false;
		}

		$first = reset( $decoded );

		return is_array( $first ) && isset( $first['chat_messages'] ) && is_array( $first['chat_messages'] );
	}

	/**
	 * Extract canonical conversations from a decoded Claude export.
	 *
	 * @param mixed $decoded Result of JSON/JSONL decoding.
	 * @param array $options Extraction options.
	 * @return \\Traversable|\\WP_Error
	 */
	public function extract( $decoded, array $options = array() ) {
		if ( ! $this->supports_decoded( $decoded ) ) {
			return new WP_Error(
				'wp_mcp_ai_import_claude_shape',
				__( 'The file does not look like a Claude conversations.jsonl export.', 'mcp-ai-wpoos' )
			);
		}

		return $this->extract_all( $decoded, $options );
	}

	/**
	 * Yield canonical conversations from a validated Claude export.
	 *
	 * @param mixed $decoded Validated decoded structure.
	 * @param array $options Extraction options.
	 * @return \\Generator
	 */
	protected function extract_all( $decoded, array $options ) {
		foreach ( $decoded as $raw ) {
			if ( ! is_array( $raw ) || empty( $raw['chat_messages'] ) || ! is_array( $raw['chat_messages'] ) ) {
				continue;
			}

			$conversation = $this->build_conversation( $raw );
			if ( null !== $conversation ) {
				yield $conversation;
			}
		}
	}

	/**
	 * Build one canonical conversation from a raw Claude export line.
	 *
	 * @param array $raw Raw conversation line.
	 * @return WP_MCP_AI_Conversation_Import_Conversation|null
	 */
	protected function build_conversation( array $raw ) {
		$source_id = isset( $raw['uuid'] ) ? sanitize_text_field( (string) $raw['uuid'] ) : '';
		if ( '' === $source_id ) {
			$encoded   = wp_json_encode( $raw );
			$source_id = substr( sha1( false !== $encoded ? $encoded : 'claude' ), 0, 16 );
		}

		$title = isset( $raw['name'] ) ? sanitize_text_field( (string) $raw['name'] ) : '';
		if ( '' === $title ) {
			$title = $source_id;
		}

		$created_at = isset( $raw['created_at'] ) ? (string) $raw['created_at'] : '';
		$updated_at = isset( $raw['updated_at'] ) ? (string) $raw['updated_at'] : '';

		$messages = array();
		foreach ( $raw['chat_messages'] as $message ) {
			$normalised = $this->normalise_message( $message );
			if ( null !== $normalised ) {
				$messages[] = $normalised;
			}
		}

		if ( empty( $messages ) ) {
			return null;
		}

		return WP_MCP_AI_Conversation_Import_Conversation::create(
			$this->get_platform(),
			$source_id,
			$title,
			$created_at,
			$updated_at,
			'',
			$messages
		);
	}

	/**
	 * Normalise one Claude chat message into the canonical message shape.
	 *
	 * @param array $message Raw chat message entry.
	 * @return array|null Null when the message carries no usable content.
	 */
	protected function normalise_message( array $message ) {
		$sender = isset( $message['sender'] ) ? sanitize_key( (string) $message['sender'] ) : '';
		if ( self::SENDER_HUMAN === $sender ) {
			$role = WP_MCP_AI_Conversation_Import_Conversation::ROLE_USER;
		} elseif ( self::SENDER_ASSISTANT === $sender ) {
			$role = WP_MCP_AI_Conversation_Import_Conversation::ROLE_ASSISTANT;
		} else {
			return null;
		}

		$content = $this->extract_content( $message );
		if ( '' === $content ) {
			return null;
		}

		$timestamp = isset( $message['created_at'] ) ? (string) $message['created_at'] : '';

		$metadata = array();
		if ( isset( $message['uuid'] ) ) {
			$metadata['message_id'] = sanitize_text_field( (string) $message['uuid'] );
		}
		if ( isset( $message['model'] ) && is_string( $message['model'] ) ) {
			$metadata['model'] = sanitize_text_field( $message['model'] );
		}

		return array(
			'role'      => $role,
			'content'   => $content,
			'timestamp' => $timestamp,
			'hidden'    => false,
			'metadata'  => $metadata,
		);
	}

	/**
	 * Extract plain text from a Claude message (text field or content blocks).
	 *
	 * @param array $message Raw chat message entry.
	 * @return string
	 */
	protected function extract_content( array $message ) {
		if ( isset( $message['text'] ) && is_string( $message['text'] ) && '' !== trim( $message['text'] ) ) {
			return trim( $message['text'] );
		}

		if ( empty( $message['content'] ) || ! is_array( $message['content'] ) ) {
			return '';
		}

		$parts = array();
		foreach ( $message['content'] as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) && is_string( $block['text'] ) ) {
				$text = trim( $block['text'] );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
				continue;
			}

			// Tool-use / tool-result blocks carry structured data; preserve a
			// compact marker so agentic context is not silently lost.
			if ( isset( $block['type'] ) && in_array( $block['type'], array( 'tool_use', 'tool_result' ), true ) && isset( $block['name'] ) && is_string( $block['name'] ) ) {
				/* translators: %s: Claude tool name. */
				$parts[] = sprintf( __( '[Tool: %s]', 'mcp-ai-wpoos' ), sanitize_text_field( $block['name'] ) );
			}
		}

		return implode( "\n", $parts );
	}
}
