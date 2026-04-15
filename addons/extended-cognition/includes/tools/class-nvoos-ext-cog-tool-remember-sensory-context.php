<?php
/**
 * Extended Cognition Tool — Remember Sensory Context
 *
 * Stores a labeled sensory snapshot into the assistant's memory system,
 * creating a persistent extended mind store analogous to Clark & Chalmers'
 * "Otto's notebook". The stored snapshot can be retrieved by the AI in
 * future turns to maintain continuity of sensory context across sessions.
 *
 * @package NV_oOS_Ext_Cognition
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
class NV_oOS_Ext_Cog_Tool_Remember_Sensory_Context {

	/**
	 * WordPress option key prefix for stored sensory memories.
	 *
	 * @var string
	 */
	const MEMORY_OPTION_PREFIX = 'nvoos_ext_cog_memory_';

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
			return new WP_Error( 'forbidden', __( 'You do not have permission to use sensory tools.', 'nvoos-ext-cognition' ) );
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
			return new WP_Error( 'missing_label', __( 'A label is required to store a sensory memory.', 'nvoos-ext-cognition' ) );
		}

		// Sanitize sensory_data: remove raw image data for storage if too large.
		$settings       = NV_oOS_Ext_Cognition::get_settings();
		$max_size_bytes = absint( $settings['max_capture_size_kb'] ) * 1024;
		$stored_data    = array();

		foreach ( $sensory_data as $key => $value ) {
			$safe_key = sanitize_key( $key );
			if ( 'image_base64' === $safe_key || 'screen_base64' === $safe_key ) {
				// Only store image data if within size limit.
				if ( is_string( $value ) && strlen( $value ) <= $max_size_bytes ) {
					$stored_data[ $safe_key ] = $value;
				} else {
					$stored_data[ $safe_key . '_truncated' ] = true;
				}
			} elseif ( is_scalar( $value ) ) {
				$stored_data[ $safe_key ] = sanitize_text_field( (string) $value );
			} elseif ( is_array( $value ) ) {
				$stored_data[ $safe_key ] = array_map( 'sanitize_text_field', array_map( 'strval', $value ) );
			}
		}

		$user_id   = get_current_user_id();
		$memory_id = wp_generate_uuid4();

		$record = array(
			'id'           => $memory_id,
			'label'        => $label,
			'tags'         => $tags,
			'sensory_data' => $stored_data,
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
			'success'    => true,
			'memory_id'  => $memory_id,
			'label'      => $label,
			'tags'       => $tags,
			'ttl_days'   => $ttl_days,
			'expires_at' => $record['expires_at'],
			'stored_via' => $stored_via_core ? 'core_memory_system' : 'ext_cog_options',
			'message'    => sprintf(
				/* translators: %s: memory label */
				__( 'Sensory memory "%s" stored successfully. Use this memory_id to retrieve or reference this context in future turns.', 'nvoos-ext-cognition' ),
				$label
			),
		);
	}

	/**
	 * Check if the current user (or guest) is allowed to use sensors.
	 *
	 * @param array $context Execution context.
	 * @return bool
	 */
	private function current_user_can_use_sensors( array $context ) {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}

		$settings = NV_oOS_Ext_Cognition::get_settings();
		if ( ! empty( $settings['guest_access'] ) && ! empty( $context['guest_request'] ) ) {
			return true;
		}

		return false;
	}
}
