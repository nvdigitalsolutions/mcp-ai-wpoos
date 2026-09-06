<?php
/**
 * ChatGPT data export adapter (Wave E4, sub-cluster 6).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Conversation_Import_Adapter_Chatgpt`: byte-identical
 * `conversations.json` structural detection, the `current_node`
 * backward walk with the root/last-entry fallback resolution, the
 * 100k-node guard, weight/metadata-based hidden-message filtering with
 * the `keep_hidden` option, content-part collapsing (text + image
 * asset pointers), and the citation-marker stripping (Chinese brackets
 * + the U+E200/U+E201/U+E202 private-use markers matched as literal
 * UTF-8 bytes).
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
 * Adapter for OpenAI ChatGPT native data exports.
 *
 * @since 2.1.0
 */
class AdapterChatgpt implements AdapterInterface {

	/**
	 * {@inheritdoc}
	 */
	public function get_platform() {
		return 'chatgpt';
	}

	/**
	 * Whether the decoded structure is a ChatGPT conversations.json array.
	 *
	 * @param mixed $decoded Result of `json_decode( $contents, true )`.
	 * @return bool
	 */
	public function supports_decoded( $decoded ) {
		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			return false;
		}

		$first = reset( $decoded );

		return is_array( $first ) && isset( $first['mapping'] ) && is_array( $first['mapping'] );
	}

	/**
	 * Extract canonical conversations from a decoded ChatGPT export.
	 *
	 * Validation happens here (outside the generator) so invalid payloads
	 * surface as WP_Error instead of an empty generator.
	 *
	 * @param mixed $decoded Result of `json_decode( $contents, true )`.
	 * @param array $options Extraction options (e.g. "keep_hidden").
	 * @return \Traversable|\WP_Error
	 */
	public function extract( $decoded, array $options = array() ) {
		if ( ! $this->supports_decoded( $decoded ) ) {
			return new \WP_Error(
				'wp_mcp_ai_import_chatgpt_shape',
				__( 'The file does not look like a ChatGPT conversations.json export.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return $this->extract_all( $decoded, $options );
	}

	/**
	 * Yield canonical conversations from a validated ChatGPT export.
	 *
	 * @param mixed $decoded Validated decoded structure.
	 * @param array $options Extraction options (e.g. "keep_hidden").
	 * @return \Generator
	 */
	protected function extract_all( $decoded, array $options ) {
		$keep_hidden = ! empty( $options['keep_hidden'] );

		foreach ( $decoded as $raw ) {
			if ( ! is_array( $raw ) || empty( $raw['mapping'] ) || ! is_array( $raw['mapping'] ) ) {
				continue;
			}

			$conversation = $this->build_conversation( $raw, $keep_hidden );
			if ( null !== $conversation ) {
				yield $conversation;
			}
		}
	}

	/**
	 * Build one canonical conversation from a raw ChatGPT conversation object.
	 *
	 * @param array $raw         Raw conversation array from conversations.json.
	 * @param bool  $keep_hidden Whether to keep hidden/system-only messages.
	 * @return Conversation|null Null when no visible messages remain.
	 */
	protected function build_conversation( array $raw, $keep_hidden ) {
		$mapping      = $raw['mapping'];
		$current_node = isset( $raw['current_node'] ) && is_string( $raw['current_node'] )
			? sanitize_text_field( $raw['current_node'] )
			: '';

		if ( '' === $current_node || ! isset( $mapping[ $current_node ] ) ) {
			$current_node = $this->resolve_fallback_node( $mapping );
		}

		if ( '' === $current_node ) {
			return null;
		}

		$source_id = isset( $raw['id'] ) ? sanitize_text_field( (string) $raw['id'] ) : '';
		if ( '' === $source_id && isset( $raw['conversation_id'] ) ) {
			$source_id = sanitize_text_field( (string) $raw['conversation_id'] );
		}
		if ( '' === $source_id ) {
			$source_id = $current_node;
		}

		$title = isset( $raw['title'] ) ? sanitize_text_field( (string) $raw['title'] ) : '';
		if ( '' === $title ) {
			$title = sanitize_text_field( $source_id );
		}

		$created_at = isset( $raw['create_time'] ) ? (float) $raw['create_time'] : 0;
		$updated_at = isset( $raw['update_time'] ) ? (float) $raw['update_time'] : 0;
		$model      = isset( $raw['default_model_slug'] ) ? sanitize_text_field( (string) $raw['default_model_slug'] ) : '';

		$messages = $this->linearise( $mapping, $current_node, $keep_hidden, (int) floor( $created_at ) );
		if ( empty( $messages ) ) {
			return null;
		}

		return Conversation::create(
			$this->get_platform(),
			$source_id,
			$title,
			$created_at,
			$updated_at,
			$model,
			$messages
		);
	}

	/**
	 * Linearise the message tree by walking current_node backwards.
	 *
	 * @param array  $mapping      Raw mapping tree.
	 * @param string $current_node Starting (current) node ID.
	 * @param bool   $keep_hidden  Whether to keep hidden messages.
	 * @param int    $fallback_ts  Conversation create time used when a message
	 *                             has no timestamp of its own.
	 * @return array[] Canonical messages in chronological order.
	 */
	protected function linearise( array $mapping, $current_node, $keep_hidden, $fallback_ts ) {
		$ordered = array();
		$node_id = $current_node;
		$guard   = 0;

		while ( '' !== $node_id && isset( $mapping[ $node_id ] ) && $guard < 100000 ) {
			++$guard;
			$node = $mapping[ $node_id ];

			if ( isset( $node['message'] ) && is_array( $node['message'] ) ) {
				$message = $this->normalise_message( $node['message'], $keep_hidden, $fallback_ts );
				if ( null !== $message ) {
					$ordered[] = $message;
				}
			}

			$node_id = isset( $node['parent'] ) && is_string( $node['parent'] )
				? sanitize_text_field( $node['parent'] )
				: '';
		}

		return array_reverse( $ordered );
	}

	/**
	 * Pick a sensible node when current_node is missing or dangling.
	 *
	 * Falls back to the root node (parent null), then to the last mapping entry.
	 *
	 * @param array $mapping Raw mapping tree.
	 * @return string Node ID, or '' when the tree is empty.
	 */
	protected function resolve_fallback_node( array $mapping ) {
		foreach ( $mapping as $node_id => $node ) {
			if ( is_array( $node ) && ( ! isset( $node['parent'] ) || null === $node['parent'] ) ) {
				return sanitize_text_field( (string) $node_id );
			}
		}

		end( $mapping );
		$last = key( $mapping );

		return is_string( $last ) || is_int( $last ) ? sanitize_text_field( (string) $last ) : '';
	}

	/**
	 * Normalise one ChatGPT message node into the canonical message shape.
	 *
	 * @param array $message     Raw message object from the mapping node.
	 * @param bool  $keep_hidden Whether to keep hidden messages.
	 * @param int   $fallback_ts Conversation create time fallback.
	 * @return array|null Null when the message is filtered out.
	 */
	protected function normalise_message( array $message, $keep_hidden, $fallback_ts ) {
		$role = isset( $message['author']['role'] ) ? sanitize_key( (string) $message['author']['role'] ) : '';
		if ( ! in_array( $role, Conversation::ALLOWED_ROLES, true ) ) {
			return null;
		}

		$weight = isset( $message['weight'] ) ? (float) $message['weight'] : 1.0;
		$hidden = false;
		if ( 0.0 === $weight ) {
			$hidden = true;
		}
		if ( ! empty( $message['metadata']['is_visually_hidden_from_conversation'] ) ) {
			$hidden = true;
		}
		if ( $hidden && ! $keep_hidden ) {
			return null;
		}

		$content = $this->collapse_parts( isset( $message['content'] ) ? $message['content'] : array() );
		if ( '' === $content ) {
			return null;
		}

		$content = $this->strip_citations( $content );

		$timestamp = $fallback_ts;
		if ( isset( $message['create_time'] ) && is_numeric( $message['create_time'] ) ) {
			$timestamp = (int) floor( (float) $message['create_time'] );
		}

		$metadata = array();
		if ( isset( $message['metadata'] ) && is_array( $message['metadata'] ) ) {
			$metadata = $message['metadata'];
		}

		return array(
			'role'      => $role,
			'content'   => $content,
			'timestamp' => $timestamp,
			'hidden'    => $hidden,
			'metadata'  => $metadata,
		);
	}

	/**
	 * Collapse a ChatGPT content.parts array into a plain text string.
	 *
	 * @param mixed $content Raw content object.
	 * @return string
	 */
	protected function collapse_parts( $content ) {
		if ( ! is_array( $content ) || empty( $content['parts'] ) || ! is_array( $content['parts'] ) ) {
			return '';
		}

		$parts = array();
		foreach ( $content['parts'] as $part ) {
			if ( is_string( $part ) ) {
				$text = trim( $part );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
				continue;
			}

			if ( is_array( $part ) ) {
				if ( isset( $part['content_type'] ) && 'image_asset_pointer' === $part['content_type'] ) {
					$pointer = isset( $part['asset_pointer'] ) ? sanitize_text_field( (string) $part['asset_pointer'] ) : '';
					if ( '' !== $pointer ) {
						/* translators: %s: source image asset pointer. */
						$parts[] = sprintf( __( '[Image: %s]', 'nvoos-content-graph-ai-platform' ), $pointer );
					}
					continue;
				}

				if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
					$text = trim( $part['text'] );
					if ( '' !== $text ) {
						$parts[] = $text;
					}
				}
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Remove inline citation markers from exported assistant text.
	 *
	 * Handles both the Chinese-bracket format (`【cite】【...】`) and the
	 * private-use-area Unicode markers (`U+E200 cite U+E202 ... U+E201`).
	 * The PUA characters are matched as literal UTF-8 bytes because some
	 * PCRE2 builds reject `\x{U...}` escape sequences.
	 *
	 * @param string $content Raw message content.
	 * @return string Cleaned content.
	 */
	protected function strip_citations( $content ) {
		$content = preg_replace( '/【cite】【[^】]*】/u', '', $content );

		$cite_start = "\xEE\x88\x80"; // U+E200.
		$cite_mid   = "\xEE\x88\x82"; // U+E202.
		$cite_end   = "\xEE\x88\x81"; // U+E201.
		$pattern    = '/' . $cite_start . '(?:file)?cite' . $cite_mid . '[^' . $cite_end . ']+' . $cite_end . '/u';
		$content    = preg_replace( $pattern, '', $content );

		$content = preg_replace( '/\n{3,}/', "\n\n", $content );

		return trim( (string) $content );
	}
}
