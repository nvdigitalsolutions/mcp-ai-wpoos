<?php
/**
 * OpenAI fine-tuning JSONL adapter (Wave E4, sub-cluster 6).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Conversation_Import_Adapter_Openai_Jsonl`: byte-identical
 * `messages`-array and `{prompt, completion}` structural detection,
 * the role-whitelisted message normalisation, the multimodal
 * content-part collapsing, the `openai-jsonl-{n}` synthetic source-ID
 * fallback, and the first-user-turn title derivation.
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
 * Adapter for OpenAI fine-tuning JSONL datasets.
 *
 * @since 2.1.0
 */
class AdapterOpenaiJsonl implements AdapterInterface {

	/**
	 * {@inheritdoc}
	 */
	public function get_platform() {
		return 'openai_jsonl';
	}

	/**
	 * Whether the decoded structure is an OpenAI fine-tuning JSONL dataset.
	 *
	 * @param mixed $decoded Result of JSON/JSONL decoding.
	 * @return bool
	 */
	public function supports_decoded( $decoded ) {
		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			return false;
		}

		$first = reset( $decoded );
		if ( ! is_array( $first ) ) {
			return false;
		}

		if ( isset( $first['messages'] ) && is_array( $first['messages'] ) ) {
			return true;
		}

		return ( isset( $first['prompt'] ) || isset( $first['completion'] ) );
	}

	/**
	 * Extract canonical conversations from a decoded OpenAI JSONL dataset.
	 *
	 * @param mixed $decoded Result of JSON/JSONL decoding.
	 * @param array $options Extraction options.
	 * @return \Traversable|\WP_Error
	 */
	public function extract( $decoded, array $options = array() ) {
		if ( ! $this->supports_decoded( $decoded ) ) {
			return new \WP_Error(
				'wp_mcp_ai_import_openai_jsonl_shape',
				__( 'The file does not look like an OpenAI fine-tuning JSONL dataset.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return $this->extract_all( $decoded, $options );
	}

	/**
	 * Yield canonical conversations from a validated OpenAI JSONL dataset.
	 *
	 * @param mixed $decoded Validated decoded structure.
	 * @param array $options Extraction options.
	 * @return \Generator
	 */
	protected function extract_all( $decoded, array $options ) {
		foreach ( $decoded as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$conversation = $this->build_conversation( $raw, $index );
			if ( null !== $conversation ) {
				yield $conversation;
			}
		}
	}

	/**
	 * Build one canonical conversation from a raw dataset line.
	 *
	 * @param array $raw   Raw line.
	 * @param int   $index Position in the dataset.
	 * @return Conversation|null
	 */
	protected function build_conversation( array $raw, $index ) {
		$source_id = 'openai-jsonl-' . ( $index + 1 );
		if ( isset( $raw['id'] ) && is_scalar( $raw['id'] ) ) {
			$source_id = sanitize_text_field( (string) $raw['id'] );
		}

		if ( isset( $raw['messages'] ) && is_array( $raw['messages'] ) ) {
			$messages = $this->normalise_messages( $raw['messages'] );
		} else {
			$messages = $this->normalise_prompt_completion( $raw );
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
	 * Normalise an OpenAI `messages` array.
	 *
	 * @param array $messages Raw messages.
	 * @return array[]
	 */
	protected function normalise_messages( array $messages ) {
		$normalised = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || empty( $message['role'] ) || ! is_string( $message['role'] ) ) {
				continue;
			}

			$role = sanitize_key( $message['role'] );
			if ( ! in_array( $role, Conversation::ALLOWED_ROLES, true ) ) {
				continue;
			}

			$content = $this->collapse_content( isset( $message['content'] ) ? $message['content'] : '' );
			if ( '' === $content ) {
				continue;
			}

			$normalised[] = array(
				'role'      => $role,
				'content'   => $content,
				'timestamp' => 0,
				'hidden'    => false,
				'metadata'  => array(),
			);
		}

		return $normalised;
	}

	/**
	 * Normalise a classic `{prompt, completion}` pair.
	 *
	 * @param array $raw Raw line.
	 * @return array[]
	 */
	protected function normalise_prompt_completion( array $raw ) {
		$normalised = array();

		if ( isset( $raw['system'] ) && is_scalar( $raw['system'] ) && '' !== trim( (string) $raw['system'] ) ) {
			$normalised[] = array(
				'role'      => Conversation::ROLE_SYSTEM,
				'content'   => trim( (string) $raw['system'] ),
				'timestamp' => 0,
				'hidden'    => false,
				'metadata'  => array(),
			);
		}

		if ( isset( $raw['prompt'] ) && is_scalar( $raw['prompt'] ) && '' !== trim( (string) $raw['prompt'] ) ) {
			$normalised[] = array(
				'role'      => Conversation::ROLE_USER,
				'content'   => trim( (string) $raw['prompt'] ),
				'timestamp' => 0,
				'hidden'    => false,
				'metadata'  => array(),
			);
		}

		if ( isset( $raw['completion'] ) && is_scalar( $raw['completion'] ) && '' !== trim( (string) $raw['completion'] ) ) {
			$normalised[] = array(
				'role'      => Conversation::ROLE_ASSISTANT,
				'content'   => trim( (string) $raw['completion'] ),
				'timestamp' => 0,
				'hidden'    => false,
				'metadata'  => array(),
			);
		}

		return $normalised;
	}

	/**
	 * Collapse message content (string or multimodal part array) to text.
	 *
	 * @param mixed $content Raw content value.
	 * @return string
	 */
	protected function collapse_content( $content ) {
		if ( is_string( $content ) ) {
			return trim( $content );
		}

		if ( ! is_array( $content ) ) {
			return '';
		}

		$parts = array();
		foreach ( $content as $part ) {
			if ( is_string( $part ) ) {
				$text = trim( $part );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
				continue;
			}

			if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
				$text = trim( $part['text'] );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
			}
		}

		return implode( "\n", $parts );
	}
}
