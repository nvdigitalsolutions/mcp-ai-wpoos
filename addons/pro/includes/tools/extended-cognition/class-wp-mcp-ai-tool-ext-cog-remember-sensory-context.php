<?php
/**
 * Extended Cognition Tool — Remember Sensory Context
 *
 * Stores a labeled sensory snapshot into the assistant's memory system,
 * creating a persistent extended mind store analogous to Clark & Chalmers'
 * "Otto's notebook". The stored snapshot can be retrieved by the AI in
 * future turns to maintain continuity of sensory context across sessions.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remember sensory context tool.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Ext_Cog_Remember_Sensory_Context implements WP_MCP_AI_Ext_Cog_Tool_Interface {

	use WP_MCP_AI_Ext_Cog_Sensor_Access;

	/**
	 * WordPress option key prefix for stored sensory memories.
	 *
	 * @var string
	 */
	const MEMORY_OPTION_PREFIX = 'wp_mcp_ai_ext_cog_memory_';

	/**
	 * Get tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Remember Sensory Context (Extended Cognition)', 'mcp-ai-wpoos' );
	}

	/**
	 * Get tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Store a labeled sensory snapshot in the assistant\'s persistent extended memory for future retrieval.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'ext_cog_remember_sensory_context';
	}

	/**
	 * Get tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'ext_cog_remember_sensory_context',
			'description'         => 'Store a labeled sensory snapshot in the assistant\'s persistent extended memory — the AI\'s equivalent of writing in a notebook (Clark & Chalmers 1998). Saved snapshots persist across conversations and can be recalled to maintain sensory context continuity. Supports visual snapshots (base64 images), audio transcripts, motion states, and arbitrary observation notes. Use this to anchor important perceptual moments: room layouts, user states, environmental conditions, on-screen data.',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'label'        => array(
						'type'        => 'string',
						'description' => 'Human-readable label for this memory (e.g. "user\'s desk setup", "current screen state", "meeting room audio context").',
						'maxLength'   => 200,
					),
					'tags'         => array(
						'type'        => 'array',
						'items'       => array(
							'type'      => 'string',
							'maxLength' => 50,
						),
						'description' => 'Semantic tags for retrieval (e.g. ["#visual-context", "#workspace", "#audio-context"]).',
						'maxItems'    => 20,
					),
					'sensory_data' => array(
						'type'        => 'object',
						'description' => 'Sensory payload to store. Can contain: image_base64 (camera/screen), transcript (audio), orientation (motion), or any structured observation.',
					),
					'observation'  => array(
						'type'        => 'string',
						'description' => 'AI-generated textual description or interpretation of the sensory data to store alongside raw data.',
						'maxLength'   => 5000,
					),
					'assistant_id' => array(
						'type'        => 'integer',
						'description' => 'Assistant post ID to scope the memory to. If omitted, stores globally for the current user.',
					),
					'ttl_days'     => array(
						'type'        => 'integer',
						'description' => 'Time-to-live in days before the memory expires (1–365). Default: 30.',
						'minimum'     => 1,
						'maximum'     => 365,
						'default'     => 30,
					),
				),
				'required'   => array( 'label' ),
			),
			'required_capability' => 'edit_posts',
			'category'            => array( 'extended-cognition', 'memory' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! $this->current_user_can_use_sensors( $context ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to use sensory tools.', 'mcp-ai-wpoos' ) );
		}

		$label        = isset( $arguments['label'] ) ? sanitize_text_field( $arguments['label'] ) : '';
		$tags         = isset( $arguments['tags'] ) && is_array( $arguments['tags'] )
			? array_map( 'sanitize_text_field', array_slice( $arguments['tags'], 0, 20 ) )
			: array();
		$sensory_data = isset( $arguments['sensory_data'] ) && is_array( $arguments['sensory_data'] )
			? $arguments['sensory_data']
			: array();
		$observation  = isset( $arguments['observation'] ) ? sanitize_textarea_field( $arguments['observation'] ) : '';
		$assistant_id = isset( $arguments['assistant_id'] ) ? absint( $arguments['assistant_id'] ) : 0;
		$ttl_days     = isset( $arguments['ttl_days'] ) ? max( 1, min( 365, absint( $arguments['ttl_days'] ) ) ) : 30;

		if ( empty( $label ) ) {
			return new WP_Error( 'missing_label', __( 'A label is required to store a sensory memory.', 'mcp-ai-wpoos' ) );
		}

		// Sanitize sensory_data: separate lightweight metadata from heavy base64.
		// Base64 images are stored as media attachments (not in options)
		// to avoid bloating the wp_options table. Only a reference (attachment ID
		// or a "_truncated" flag) is kept in the memory record.
		$settings       = wp_mcp_ai_ext_cog_get_settings();
		$max_size_bytes = absint( $settings['max_capture_size_kb'] ) * 1024;
		$light_data     = array();
		$heavy_media    = array(); // attachment IDs keyed by sensor key.

		foreach ( $sensory_data as $key => $value ) {
			$safe_key = sanitize_key( $key );
			if ( 'image_base64' === $safe_key || 'screen_base64' === $safe_key ) {
				if ( is_string( $value ) && strlen( $value ) <= $max_size_bytes ) {
					// Store as media attachment; keep only the ID in the record.
					$attachment_id = self::save_base64_to_media( $value, $safe_key );
					if ( ! is_wp_error( $attachment_id ) ) {
						$heavy_media[ $safe_key ] = $attachment_id;
						$light_data[ $safe_key ]  = array(
							'attachment_id' => $attachment_id,
							'stored_in'     => 'media_library',
						);
					} else {
						$light_data[ $safe_key . '_truncated' ] = true;
					}
				} else {
					$light_data[ $safe_key . '_truncated' ] = true;
				}
			} elseif ( is_scalar( $value ) ) {
				$light_data[ $safe_key ] = sanitize_text_field( (string) $value );
			} elseif ( is_array( $value ) ) {
				$light_data[ $safe_key ] = array_map(
					function ( $v ) {
						return sanitize_text_field( (string) $v );
					},
					$value
				);
			}
		}

		$user_id   = get_current_user_id();
		$memory_id = wp_generate_uuid4();

		$record = array(
			'id'           => $memory_id,
			'label'        => $label,
			'tags'         => $tags,
			'sensory_data' => $light_data,
			'heavy_media'  => $heavy_media,
			'observation'  => $observation,
			'user_id'      => $user_id,
			'assistant_id' => $assistant_id,
			'created_at'   => time(),
			'expires_at'   => time() + ( $ttl_days * DAY_IN_SECONDS ),
		);

		// Attempt to store via NV oOS core memory system if available.
		$stored_via_core = false;
		if ( function_exists( 'wp_mcp_ai_store_memory' ) ) {
			$core_result = wp_mcp_ai_store_memory(
				array(
					'content'      => $observation ? $observation : $label,
					'meta'         => $record,
					'tags'         => $tags,
					'assistant_id' => $assistant_id,
					'user_id'      => $user_id,
					'ttl_days'     => $ttl_days,
				)
			);
			if ( ! is_wp_error( $core_result ) ) {
				$stored_via_core = true;
				$memory_id       = is_array( $core_result ) && isset( $core_result['id'] ) ? $core_result['id'] : $memory_id;
			}
		}

		// Fallback: store in WordPress options for the user.
		if ( ! $stored_via_core ) {
			$option_key = self::MEMORY_OPTION_PREFIX . $user_id;
			$memories   = get_option( $option_key, array() );
			$memories   = is_array( $memories ) ? $memories : array();

			// Clean expired memories.
			$now      = time();
			$memories = array_filter(
				$memories,
				function ( $m ) use ( $now ) {
					return isset( $m['expires_at'] ) && $m['expires_at'] > $now;
				}
			);

			$memories[ $memory_id ] = $record;

			// Cap at 200 memories per user.
			if ( count( $memories ) > 200 ) {
				uasort(
					$memories,
					function ( $a, $b ) {
						return $a['created_at'] - $b['created_at'];
					}
				);
				$memories = array_slice( $memories, -200, 200, true );
			}

			update_option( $option_key, $memories, false );
		}

		return array(
			'success'     => true,
			'memory_id'   => $memory_id,
			'label'       => $label,
			'tags'        => $tags,
			'ttl_days'    => $ttl_days,
			'expires_at'  => $record['expires_at'],
			'stored_via'  => $stored_via_core ? 'core_memory_system' : 'ext_cog_options',
			'attachments' => $heavy_media,
			'message'     => sprintf(
				/* translators: %s: memory label */
				__( 'Sensory memory "%s" stored successfully. Use this memory_id to retrieve or reference this context in future turns.', 'mcp-ai-wpoos' ),
				$label
			),
		);
	}

	/**
	 * Save a base64-encoded image as a WordPress media attachment.
	 *
	 * Reuses the media-upload logic from WP_MCP_AI_Ext_Cog_REST so we
	 * don't duplicate file-system code.
	 *
	 * @since 1.8.1
	 *
	 * @param string $base64     Base64 image data (with or without data URI prefix).
	 * @param string $source_key Sensor key for filename prefix (e.g. "image_base64").
	 * @return int|WP_Error Attachment ID on success.
	 */
	private static function save_base64_to_media( $base64, $source_key ) {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return new WP_Error( 'upload_unavailable', __( 'Media upload not available.', 'mcp-ai-wpoos' ) );
		}

		$raw  = $base64;
		$mime = 'image/jpeg';
		$ext  = 'jpg';

		if ( strpos( $raw, 'data:image/' ) === 0 ) {
			$parts = explode( ',', $raw, 2 );
			$raw   = isset( $parts[1] ) ? $parts[1] : $raw;
			if ( strpos( $parts[0], 'image/png' ) !== false ) {
				$mime = 'image/png';
				$ext  = 'png';
			}
		}

		$decoded = base64_decode( $raw, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded ) {
			return new WP_Error( 'decode_failed', __( 'Failed to decode image data.', 'mcp-ai-wpoos' ) );
		}

		$upload_dir = wp_upload_dir();
		$filename   = sanitize_file_name(
			'ext-cog-memory-' . $source_key . '-' . time() . '.' . $ext
		);
		$filepath   = trailingslashit( $upload_dir['path'] ) . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $filepath, $decoded ) ) {
			return new WP_Error( 'write_failed', __( 'Failed to write image file.', 'mcp-ai-wpoos' ) );
		}

		$attachment = array(
			'post_mime_type' => $mime,
			'post_title'     => sanitize_file_name( $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $filepath );

		if ( ! is_wp_error( $attach_id ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attach_data = wp_generate_attachment_metadata( $attach_id, $filepath );
			wp_update_attachment_metadata( $attach_id, $attach_data );
		}

		return $attach_id;
	}
}
