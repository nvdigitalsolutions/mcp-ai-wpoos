<?php
/**
 * Canonical model for imported conversations.
 *
 * All adapters normalise their source formats into this shape before the
 * manager deduplicates and writes CCT rows. The message list follows the
 * OpenAI Chat Completions role set (`system`/`user`/`assistant`/`tool`) —
 * the ecosystem's de facto interchange format.
 *
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
 * Immutable canonical conversation value object.
 *
 * Instances are created through the static `create()` factory, which returns
 * a WP_Error on invalid input. All timestamps are UTC Unix seconds.
 */
class WP_MCP_AI_Conversation_Import_Conversation {

	const SCHEMA_VERSION = 1;

	const ROLE_SYSTEM    = 'system';
	const ROLE_USER      = 'user';
	const ROLE_ASSISTANT = 'assistant';
	const ROLE_TOOL      = 'tool';

	/**
	 * Allowed message roles in the canonical model.
	 *
	 * @var string[]
	 */
	const ALLOWED_ROLES = array(
		self::ROLE_SYSTEM,
		self::ROLE_USER,
		self::ROLE_ASSISTANT,
		self::ROLE_TOOL,
	);

	/**
	 * Source platform slug (e.g. "chatgpt", "gemini").
	 *
	 * @var string
	 */
	protected $platform;

	/**
	 * Source platform's identifier for the conversation.
	 *
	 * @var string
	 */
	protected $source_id;

	/**
	 * Human-readable conversation title.
	 *
	 * @var string
	 */
	protected $title;

	/**
	 * UTC Unix timestamp of conversation creation.
	 *
	 * @var int
	 */
	protected $created_at;

	/**
	 * UTC Unix timestamp of the last conversation update.
	 *
	 * @var int
	 */
	protected $updated_at;

	/**
	 * Model slug reported by the source (may be empty).
	 *
	 * @var string
	 */
	protected $model;

	/**
	 * Normalised messages in chronological order.
	 *
	 * Each entry: role, content, timestamp (UTC Unix seconds), hidden, metadata.
	 *
	 * @var array[]
	 */
	protected $messages;

	/**
	 * Create a validated conversation instance.
	 *
	 * @param string $platform   Source platform slug.
	 * @param string $source_id  Source conversation identifier.
	 * @param string $title      Conversation title.
	 * @param mixed  $created_at UTC Unix timestamp (int/float) or 0.
	 * @param mixed  $updated_at UTC Unix timestamp (int/float) or 0.
	 * @param string $model      Model slug reported by the source.
	 * @param array  $messages   Normalised messages (see {@see self::normalise_message()}).
	 * @return WP_MCP_AI_Conversation_Import_Conversation|\WP_Error
	 */
	public static function create( $platform, $source_id, $title, $created_at, $updated_at, $model, array $messages ) {
		$platform = sanitize_key( (string) $platform );
		if ( '' === $platform ) {
			return new WP_Error(
				'wp_mcp_ai_import_invalid_platform',
				__( 'Conversation platform slug is empty.', 'mcp-ai-wpoos' )
			);
		}

		$source_id = sanitize_text_field( (string) $source_id );
		if ( '' === $source_id ) {
			return new WP_Error(
				'wp_mcp_ai_import_invalid_source_id',
				__( 'Conversation source ID is empty.', 'mcp-ai-wpoos' )
			);
		}

		$title = sanitize_text_field( (string) $title );
		if ( '' === $title ) {
			$title = __( 'Untitled conversation', 'mcp-ai-wpoos' );
		}

		$created_at = self::normalise_timestamp( $created_at );
		$updated_at = self::normalise_timestamp( $updated_at );
		if ( 0 === $updated_at && 0 !== $created_at ) {
			$updated_at = $created_at;
		}
		if ( 0 === $created_at && 0 !== $updated_at ) {
			$created_at = $updated_at;
		}

		$model = sanitize_text_field( (string) $model );

		$normalised = array();
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			$normalised[] = self::normalise_message( $message );
		}

		if ( empty( $normalised ) ) {
			return new WP_Error(
				'wp_mcp_ai_import_empty_conversation',
				__( 'Conversation contains no importable messages.', 'mcp-ai-wpoos' )
			);
		}

		$instance             = new self();
		$instance->platform   = $platform;
		$instance->source_id  = $source_id;
		$instance->title      = $title;
		$instance->created_at = $created_at;
		$instance->updated_at = $updated_at;
		$instance->model      = $model;
		$instance->messages   = $normalised;

		return $instance;
	}

	/**
	 * Retrieve the source platform slug.
	 *
	 * @return string
	 */
	public function get_platform() {
		return $this->platform;
	}

	/**
	 * Retrieve the source conversation identifier.
	 *
	 * @return string
	 */
	public function get_source_id() {
		return $this->source_id;
	}

	/**
	 * Retrieve the conversation title.
	 *
	 * @return string
	 */
	public function get_title() {
		return $this->title;
	}

	/**
	 * Retrieve the conversation creation timestamp (UTC Unix seconds).
	 *
	 * @return int
	 */
	public function get_created_at() {
		return $this->created_at;
	}

	/**
	 * Retrieve the conversation update timestamp (UTC Unix seconds).
	 *
	 * @return int
	 */
	public function get_updated_at() {
		return $this->updated_at;
	}

	/**
	 * Retrieve the source-reported model slug.
	 *
	 * @return string
	 */
	public function get_model() {
		return $this->model;
	}

	/**
	 * Retrieve the normalised messages.
	 *
	 * @return array[]
	 */
	public function get_messages() {
		return $this->messages;
	}

	/**
	 * Return a copy of this conversation with a replaced message list.
	 *
	 * Used by the media sideload pass to rewrite image placeholders while
	 * keeping the source metadata (and therefore the dedupe hash) intact.
	 *
	 * @param array $messages Replacement normalised messages.
	 * @return WP_MCP_AI_Conversation_Import_Conversation|\WP_Error
	 */
	public function with_messages( array $messages ) {
		return self::create(
			$this->platform,
			$this->source_id,
			$this->title,
			$this->created_at,
			$this->updated_at,
			$this->model,
			$messages
		);
	}

	/**
	 * Build the deterministic CCT session key for this conversation.
	 *
	 * Format: `import-{platform}-{hash12}` where hash12 is a 12-char SHA-1
	 * fragment of the source ID. Bounded well below the 96-char CCT limit.
	 *
	 * @return string
	 */
	public function get_session_key() {
		$hash = substr( sha1( $this->source_id ), 0, 12 );

		return 'import-' . $this->platform . '-' . $hash;
	}

	/**
	 * Compute the dedupe hash for idempotent re-imports.
	 *
	 * @return string
	 */
	public function compute_dedupe_hash() {
		return hash( 'sha256', $this->platform . '|' . $this->source_id . '|' . $this->updated_at );
	}

	/**
	 * Build the provenance envelope stored in the CCT metadata field.
	 *
	 * @param string $imported_at ISO 8601 import timestamp.
	 * @param string $importer_version Plugin version performing the import.
	 * @return array
	 */
	public function build_metadata( $imported_at = '', $importer_version = '' ) {
		$metadata = array(
			'import' => array(
				'schema_version'    => self::SCHEMA_VERSION,
				'platform'          => $this->platform,
				'source_id'         => $this->source_id,
				'source_title'      => $this->title,
				'source_created_at' => $this->created_at,
				'source_updated_at' => $this->updated_at,
				'model'             => $this->model,
				'message_count'     => count( $this->messages ),
				'dedupe_hash'       => $this->compute_dedupe_hash(),
			),
		);

		if ( '' !== $imported_at ) {
			$metadata['import']['imported_at'] = $imported_at;
		}
		if ( '' !== $importer_version ) {
			$metadata['import']['importer_version'] = $importer_version;
		}

		return $metadata;
	}

	/**
	 * Serialise the conversation into its canonical array shape.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'source'         => array(
				'platform'   => $this->platform,
				'source_id'  => $this->source_id,
				'title'      => $this->title,
				'created_at' => $this->created_at,
				'updated_at' => $this->updated_at,
				'model'      => $this->model,
			),
			'messages'       => $this->messages,
		);
	}

	/**
	 * Encode the request payload stored in the CCT row.
	 *
	 * Mirrors the transcript recorder shape (`messages` + `options`), with the
	 * source envelope carried alongside.
	 *
	 * @return string JSON string, or '' on encoding failure.
	 */
	public function encode_request_payload() {
		$payload = array(
			'messages' => $this->messages,
			'options'  => array(
				'import' => $this->to_array()['source'],
			),
		);

		$encoded = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $encoded ) {
			$encoded = wp_json_encode( $payload );
		}

		return false === $encoded ? '' : $encoded;
	}

	/**
	 * Encode the response payload stored in the CCT row.
	 *
	 * Carries the final assistant message so consumers expecting a single
	 * response payload (like the native transcript recorder) still find one.
	 *
	 * @return string JSON string, or '' when there is no assistant message.
	 */
	public function encode_response_payload() {
		$final = $this->get_final_assistant_message();
		if ( null === $final ) {
			return '';
		}

		$encoded = wp_json_encode(
			array( 'message' => $final ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		if ( false === $encoded ) {
			$encoded = wp_json_encode( array( 'message' => $final ) );
		}

		return false === $encoded ? '' : $encoded;
	}

	/**
	 * Retrieve the final assistant message, if any.
	 *
	 * @return array|null
	 */
	public function get_final_assistant_message() {
		$messages = array_reverse( $this->messages );
		foreach ( $messages as $message ) {
			if ( self::ROLE_ASSISTANT === $message['role'] ) {
				return $message;
			}
		}

		return null;
	}

	/**
	 * Normalise a raw message array into the canonical message shape.
	 *
	 * @param array $message Raw message with optional role/content/timestamp keys.
	 * @return array Normalised message.
	 */
	protected static function normalise_message( array $message ) {
		$role = isset( $message['role'] ) ? sanitize_key( (string) $message['role'] ) : '';
		if ( ! in_array( $role, self::ALLOWED_ROLES, true ) ) {
			$role = self::ROLE_TOOL;
		}

		$content = isset( $message['content'] ) && is_scalar( $message['content'] )
			? (string) $message['content']
			: '';

		$timestamp = isset( $message['timestamp'] ) ? self::normalise_timestamp( $message['timestamp'] ) : 0;
		$hidden    = isset( $message['hidden'] ) ? (bool) $message['hidden'] : false;

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
	 * Convert a raw timestamp value into UTC Unix seconds.
	 *
	 * @param mixed $value Raw timestamp (int, float, numeric string).
	 * @return int Non-negative integer, or 0 when unparseable.
	 */
	protected static function normalise_timestamp( $value ) {
		if ( is_string( $value ) && ! is_numeric( $value ) ) {
			$parsed = strtotime( $value );
			if ( false === $parsed ) {
				return 0;
			}
			$value = $parsed;
		}

		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		$timestamp = (int) floor( (float) $value );

		return max( 0, $timestamp );
	}
}
