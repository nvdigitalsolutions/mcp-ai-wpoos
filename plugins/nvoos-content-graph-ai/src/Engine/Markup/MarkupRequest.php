<?php
/**
 * Markup request value object (Wave E6, sub-cluster 2).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Markup_Request`
 * (`includes/markup/`): byte-identical target-type / interaction-mode
 * constants, the 15-minute default TTL with the 60s–24h clamp, the
 * allowed-type/mode lists, the `mr_` request-ID generation with the
 * random-bytes/fallback chain, the target-descriptor sanitization
 * (attachment IDs, URLs, capped data URIs, 100 KB text cap, dimensions,
 * MIME type), the constructor validation, the full accessor set, the
 * array round-trip (`to_array()` / `from_array()`), and the expiry
 * probe.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - `InvalidArgumentException`, `Exception`, and `WP_Error` are fully
 *    qualified.
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\Markup
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\Markup;

/**
 * Immutable description of a markup elicitation.
 *
 * @since 1.1.0
 */
class MarkupRequest {

	const TARGET_TYPE_IMAGE         = 'image';
	const TARGET_TYPE_DOCUMENT_PDF  = 'document_pdf';
	const TARGET_TYPE_DOCUMENT_TEXT = 'document_text';

	const MODE_POSITION   = 'position';
	const MODE_MASK       = 'mask';
	const MODE_REGION     = 'region';
	const MODE_REDACT     = 'redact';
	const MODE_ANNOTATE   = 'annotate';
	const MODE_TEXT_RANGE = 'text_range';
	const MODE_CROP       = 'crop';

	/**
	 * Default request TTL in seconds (15 minutes).
	 */
	const DEFAULT_TTL = 900;

	/**
	 * Allowed target types.
	 *
	 * @return array<string>
	 */
	public static function allowed_target_types() {
		return array(
			self::TARGET_TYPE_IMAGE,
			self::TARGET_TYPE_DOCUMENT_PDF,
			self::TARGET_TYPE_DOCUMENT_TEXT,
		);
	}

	/**
	 * Allowed interaction modes.
	 *
	 * @return array<string>
	 */
	public static function allowed_modes() {
		return array(
			self::MODE_POSITION,
			self::MODE_MASK,
			self::MODE_REGION,
			self::MODE_REDACT,
			self::MODE_ANNOTATE,
			self::MODE_TEXT_RANGE,
			self::MODE_CROP,
		);
	}

	/**
	 * Stable request identifier (used to look the request up later).
	 *
	 * @var string
	 */
	private $request_id;

	/**
	 * Slug of the tool that created this request.
	 *
	 * @var string
	 */
	private $tool_slug;

	/**
	 * Target descriptor.
	 *
	 * @var array
	 */
	private $target;

	/**
	 * Target type (image, document_pdf, document_text).
	 *
	 * @var string
	 */
	private $target_type;

	/**
	 * Interaction mode (mask, position, redact, ...).
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Optional JSON Schema fragment describing extra fields.
	 *
	 * @var array
	 */
	private $schema;

	/**
	 * Human-readable instructions shown above the canvas.
	 *
	 * @var string
	 */
	private $instructions;

	/**
	 * Original tool arguments to replay when markup is submitted.
	 *
	 * @var array
	 */
	private $tool_arguments;

	/**
	 * Execution context to replay when markup is submitted.
	 *
	 * @var array
	 */
	private $tool_context;

	/**
	 * Owning assistant post ID, for capability scoping.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Creating user ID (0 for guest).
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Unix timestamp at which the request expires.
	 *
	 * @var int
	 */
	private $expires_at;

	/**
	 * Constructor.
	 *
	 * @param array $args Associative args: request_id, tool_slug, target,
	 *                    target_type, mode, schema, instructions,
	 *                    tool_arguments, tool_context, assistant_id,
	 *                    user_id, ttl.
	 *
	 * @throws \InvalidArgumentException If required arguments are missing or invalid.
	 */
	public function __construct( array $args ) {
		$tool_slug   = isset( $args['tool_slug'] ) ? (string) $args['tool_slug'] : '';
		$target_type = isset( $args['target_type'] ) ? (string) $args['target_type'] : '';
		$mode        = isset( $args['mode'] ) ? (string) $args['mode'] : '';

		if ( '' === $tool_slug ) {
			throw new \InvalidArgumentException( 'tool_slug is required.' );
		}
		if ( ! \in_array( $target_type, self::allowed_target_types(), true ) ) {
			throw new \InvalidArgumentException( 'Invalid target_type.' );
		}
		if ( ! \in_array( $mode, self::allowed_modes(), true ) ) {
			throw new \InvalidArgumentException( 'Invalid mode.' );
		}

		$target = isset( $args['target'] ) && \is_array( $args['target'] ) ? $args['target'] : array();
		if ( empty( $target ) ) {
			throw new \InvalidArgumentException( 'target is required.' );
		}

		$ttl = isset( $args['ttl'] ) ? (int) $args['ttl'] : self::DEFAULT_TTL;
		if ( $ttl < 60 ) {
			$ttl = 60;
		}
		if ( $ttl > 24 * HOUR_IN_SECONDS ) {
			$ttl = 24 * HOUR_IN_SECONDS;
		}

		$this->request_id     = isset( $args['request_id'] ) && \is_string( $args['request_id'] ) && '' !== $args['request_id']
			? $args['request_id']
			: self::generate_id();
		$this->tool_slug      = $tool_slug;
		$this->target         = $this->sanitize_target( $target );
		$this->target_type    = $target_type;
		$this->mode           = $mode;
		$this->schema         = isset( $args['schema'] ) && \is_array( $args['schema'] ) ? $args['schema'] : array();
		$this->instructions   = isset( $args['instructions'] ) ? (string) $args['instructions'] : '';
		$this->tool_arguments = isset( $args['tool_arguments'] ) && \is_array( $args['tool_arguments'] ) ? $args['tool_arguments'] : array();
		$this->tool_context   = isset( $args['tool_context'] ) && \is_array( $args['tool_context'] ) ? $args['tool_context'] : array();
		$this->assistant_id   = isset( $args['assistant_id'] ) ? (int) $args['assistant_id'] : 0;
		$this->user_id        = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;
		$this->expires_at     = isset( $args['expires_at'] ) ? (int) $args['expires_at'] : ( \time() + $ttl );
	}

	/**
	 * Generate a cryptographically random request ID.
	 *
	 * @return string
	 */
	public static function generate_id() {
		try {
			$bytes = \random_bytes( 16 );
			return 'mr_' . \bin2hex( $bytes );
		} catch ( \Exception $e ) {
			return 'mr_' . \wp_generate_password( 32, false, false );
		}
	}

	/**
	 * Sanitize the target descriptor.
	 *
	 * @param array $target Raw target descriptor.
	 * @return array Sanitized target descriptor.
	 */
	private function sanitize_target( array $target ) {
		$out = array();
		if ( isset( $target['attachment_id'] ) ) {
			$out['attachment_id'] = (int) $target['attachment_id'];
		}
		if ( isset( $target['url'] ) ) {
			$out['url'] = \esc_url_raw( (string) $target['url'] );
		}
		if ( isset( $target['data_uri'] ) ) {
			// We don't validate the data URI body, but we cap length for safety.
			$data_uri = (string) $target['data_uri'];
			if ( \strlen( $data_uri ) <= 5 * MB_IN_BYTES ) {
				$out['data_uri'] = $data_uri;
			}
		}
		if ( isset( $target['text'] ) ) {
			$text = (string) $target['text'];
			// Cap text length to 100 KB to prevent abuse.
			if ( \strlen( $text ) > 100 * 1024 ) {
				$text = \substr( $text, 0, 100 * 1024 );
			}
			$out['text'] = $text;
		}
		if ( isset( $target['width'] ) ) {
			$out['width'] = \max( 0, (int) $target['width'] );
		}
		if ( isset( $target['height'] ) ) {
			$out['height'] = \max( 0, (int) $target['height'] );
		}
		if ( isset( $target['mime_type'] ) ) {
			$out['mime_type'] = \sanitize_text_field( (string) $target['mime_type'] );
		}
		return $out;
	}

	// --- Accessors ---------------------------------------------------------

	/**
	 * Request ID getter.
	 *
	 * @return string
	 */
	public function get_request_id() {
		return $this->request_id;
	}

	/**
	 * Tool slug getter.
	 *
	 * @return string
	 */
	public function get_tool_slug() {
		return $this->tool_slug;
	}

	/**
	 * Target descriptor getter.
	 *
	 * @return array
	 */
	public function get_target() {
		return $this->target;
	}

	/**
	 * Target type getter.
	 *
	 * @return string
	 */
	public function get_target_type() {
		return $this->target_type;
	}

	/**
	 * Mode getter.
	 *
	 * @return string
	 */
	public function get_mode() {
		return $this->mode;
	}

	/**
	 * Schema getter.
	 *
	 * @return array
	 */
	public function get_schema() {
		return $this->schema;
	}

	/**
	 * Instructions getter.
	 *
	 * @return string
	 */
	public function get_instructions() {
		return $this->instructions;
	}

	/**
	 * Tool arguments getter.
	 *
	 * @return array
	 */
	public function get_tool_arguments() {
		return $this->tool_arguments;
	}

	/**
	 * Tool context getter.
	 *
	 * @return array
	 */
	public function get_tool_context() {
		return $this->tool_context;
	}

	/**
	 * Assistant ID getter.
	 *
	 * @return int
	 */
	public function get_assistant_id() {
		return $this->assistant_id;
	}

	/**
	 * User ID getter.
	 *
	 * @return int
	 */
	public function get_user_id() {
		return $this->user_id;
	}

	/**
	 * Expiry timestamp getter.
	 *
	 * @return int
	 */
	public function get_expires_at() {
		return $this->expires_at;
	}

	/**
	 * Whether the request has expired.
	 *
	 * @return bool
	 */
	public function is_expired() {
		return \time() >= $this->expires_at;
	}

	/**
	 * Serialize to a plain array for storage.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'request_id'     => $this->request_id,
			'tool_slug'      => $this->tool_slug,
			'target'         => $this->target,
			'target_type'    => $this->target_type,
			'mode'           => $this->mode,
			'schema'         => $this->schema,
			'instructions'   => $this->instructions,
			'tool_arguments' => $this->tool_arguments,
			'tool_context'   => $this->tool_context,
			'assistant_id'   => $this->assistant_id,
			'user_id'        => $this->user_id,
			'expires_at'     => $this->expires_at,
		);
	}

	/**
	 * Reconstruct a request from its array form.
	 *
	 * @param array $data Stored request array.
	 * @return MarkupRequest|\WP_Error
	 */
	public static function from_array( array $data ) {
		try {
			return new self( $data );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'wp_mcp_ai_markup_invalid_request', $e->getMessage() );
		}
	}
}
