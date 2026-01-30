<?php
/**
 * Tool for storing agent context/memory.
 *
 * Allows AI assistants to store important context for future retrieval.
 * Part of DeepSeek V4-inspired multi-agent orchestration enhancements (Phase 4/5).
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores important context for an agent to remember.
 *
 * This tool enables AI models to persist context, learnings, and important
 * information that should be remembered across sessions. Stored context can
 * be retrieved later using retrieve_agent_memory tool.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Store_Agent_Context implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'store_agent_context';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Store Agent Context', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Stores important context, learnings, or information for an agent to remember. Use this to persist knowledge across sessions, track important facts, or maintain agent memory. Context can be retrieved later using retrieve_agent_memory.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'agent_id'     => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Agent assistant ID (post ID) or virtual agent identifier', 'mcp-ai-wpoos' ),
				),
				'context_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of context being stored', 'mcp-ai-wpoos' ),
					'enum'        => array(
						'learning',
						'fact',
						'preference',
						'pattern',
						'workflow',
						'decision',
						'result',
						'insight',
						'note',
						'generic',
					),
				),
				'context_data' => array(
					'type'        => 'object',
					'description' => __( 'The context data to store', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'title'       => array(
							'type'        => 'string',
							'description' => __( 'Short title or summary of the context', 'mcp-ai-wpoos' ),
						),
						'content'     => array(
							'type'        => 'string',
							'description' => __( 'The main content or information to store', 'mcp-ai-wpoos' ),
						),
						'metadata'    => array(
							'type'        => 'object',
							'description' => __( 'Additional metadata about the context', 'mcp-ai-wpoos' ),
						),
						'tags'        => array(
							'type'        => 'array',
							'description' => __( 'Tags for categorization and retrieval', 'mcp-ai-wpoos' ),
							'items'       => array( 'type' => 'string' ),
						),
						'importance'  => array(
							'type'        => 'string',
							'description' => __( 'Importance level: low, medium, high, critical', 'mcp-ai-wpoos' ),
							'enum'        => array( 'low', 'medium', 'high', 'critical' ),
							'default'     => 'medium',
						),
						'source_task' => array(
							'type'        => 'string',
							'description' => __( 'ID or reference to the task that generated this context', 'mcp-ai-wpoos' ),
						),
					),
					'required'    => array( 'title', 'content' ),
				),
				'ttl'          => array(
					'type'        => 'integer',
					'description' => __( 'Time to live in seconds (default: 30 days)', 'mcp-ai-wpoos' ),
					'default'     => 2592000, // 30 days.
					'minimum'     => 3600,    // 1 hour minimum.
					'maximum'     => 31536000, // 1 year maximum.
				),
			),
			'required'             => array( 'agent_id', 'context_type', 'context_data' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool results.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['agent_id'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Agent ID is required.', 'mcp-ai-wpoos' ),
			);
		}

		if ( empty( $arguments['context_type'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Context type is required.', 'mcp-ai-wpoos' ),
			);
		}

		if ( empty( $arguments['context_data'] ) || ! is_array( $arguments['context_data'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Context data is required and must be an object.', 'mcp-ai-wpoos' ),
			);
		}

		// Sanitize inputs.
		$agent_id     = is_numeric( $arguments['agent_id'] ) ? absint( $arguments['agent_id'] ) : sanitize_text_field( $arguments['agent_id'] );
		$context_type = sanitize_key( $arguments['context_type'] );
		$context_data = $this->sanitize_context_data( $arguments['context_data'] );
		$ttl          = isset( $arguments['ttl'] ) ? absint( $arguments['ttl'] ) : 2592000; // 30 days default.

		// Validate TTL bounds.
		$ttl = max( 3600, min( 31536000, $ttl ) ); // Between 1 hour and 1 year.

		// Validate context_data has required fields.
		if ( empty( $context_data['title'] ) || empty( $context_data['content'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Context data must include title and content fields.', 'mcp-ai-wpoos' ),
			);
		}

		// Generate unique context ID.
		$context_id = 'ctx_' . wp_generate_password( 12, false );

		// Prepare context record.
		$context_record = array(
			'context_id'   => $context_id,
			'agent_id'     => $agent_id,
			'context_type' => $context_type,
			'data'         => $context_data,
			'stored_at'    => current_time( 'mysql' ),
			'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
			'ttl'          => $ttl,
		);

		// Store context using transient (WordPress built-in caching).
		// Use a compound key to allow retrieval by agent_id.
		$transient_key = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context_id );
		set_transient( $transient_key, $context_record, $ttl );

		// Also maintain an index of context IDs for this agent.
		$index_key     = 'mcp_ai_ctx_index_' . md5( (string) $agent_id );
		$context_index = get_transient( $index_key );
		if ( ! is_array( $context_index ) ) {
			$context_index = array();
		}

		// Add to index with expiry time.
		$context_index[ $context_id ] = array(
			'type'       => $context_type,
			'title'      => $context_data['title'],
			'stored_at'  => current_time( 'mysql' ),
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
			'importance' => isset( $context_data['importance'] ) ? $context_data['importance'] : 'medium',
			'tags'       => isset( $context_data['tags'] ) ? $context_data['tags'] : array(),
		);

		// Store index with same TTL.
		set_transient( $index_key, $context_index, $ttl );

		return array(
			'success'     => true,
			'message'     => __( 'Context stored successfully.', 'mcp-ai-wpoos' ),
			'context_id'  => $context_id,
			'agent_id'    => $agent_id,
			'stored_at'   => $context_record['stored_at'],
			'expires_at'  => $context_record['expires_at'],
			'ttl_seconds' => $ttl,
			'ttl_human'   => $this->format_ttl( $ttl ),
			'storage'     => array(
				'method' => 'WordPress Transient',
				'key'    => $transient_key,
			),
			'next_steps'  => array(
				/* translators: %s: context_id value */
				sprintf( __( 'Retrieve this context later using retrieve_agent_memory with context_id: "%s"', 'mcp-ai-wpoos' ), $context_id ),
				/* translators: %s: agent_id value */
				sprintf( __( 'Or search all contexts for agent_id: "%s" using retrieve_agent_memory', 'mcp-ai-wpoos' ), $agent_id ),
			),
		);
	}

	/**
	 * Sanitize context data recursively.
	 *
	 * @param array $data Context data to sanitize.
	 * @return array Sanitized data.
	 */
	private function sanitize_context_data( $data ) {
		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$key = sanitize_key( $key );

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_context_data( $value );
			} elseif ( is_string( $value ) ) {
				// Allow HTML in content field for formatting.
				if ( 'content' === $key ) {
					$sanitized[ $key ] = wp_kses_post( $value );
				} else {
					$sanitized[ $key ] = sanitize_text_field( $value );
				}
			} elseif ( is_numeric( $value ) ) {
				$sanitized[ $key ] = $value;
			} elseif ( is_bool( $value ) ) {
				$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Format TTL into human-readable string.
	 *
	 * @param int $seconds TTL in seconds.
	 * @return string Human-readable time.
	 */
	private function format_ttl( $seconds ) {
		$days = floor( $seconds / 86400 );
		if ( $days > 0 ) {
			/* translators: %d: number of days */
			return sprintf( _n( '%d day', '%d days', $days, 'mcp-ai-wpoos' ), $days );
		}

		$hours = floor( $seconds / 3600 );
		if ( $hours > 0 ) {
			/* translators: %d: number of hours */
			return sprintf( _n( '%d hour', '%d hours', $hours, 'mcp-ai-wpoos' ), $hours );
		}

		$minutes = floor( $seconds / 60 );
		/* translators: %d: number of minutes */
		return sprintf( _n( '%d minute', '%d minutes', $minutes, 'mcp-ai-wpoos' ), $minutes );
	}


	/**

	 * Get extended tool definition including toolkit metadata.

	 *

	 * @since 1.1.0

	 *

	 * @return array Tool definition with metadata.

	 */

	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'ai_model_management',

			'pattern_compatibility' => array( 'orchestrator', 'hierarchical' ),

			'profession_tags'       => array( 'ai_researcher', 'machine_learning_engineer' ),

			'risk_level'            => 'standard',

		);

	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'safe'              => true,  // Only stores data.
			'local-only'        => true,  // No external API calls.
			'read-only'         => false, // Writes context data.
			'idempotent'        => false, // Creates new context each time.
			'cacheable'         => false, // Storage operation, not cacheable.
			'requires-auth'     => true,  // Needs user authentication.
			'blocking'          => false, // Fast operation.
			'uses-network'      => false, // No network calls.
			'modifies-wp'       => true,  // Stores data in transients.
			'expensive'         => false, // Low cost operation.
			'requires-approval' => false, // Auto-approved.
		);
	}
}
