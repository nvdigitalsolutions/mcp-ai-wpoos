<?php
/**
 * Tool that lists files uploaded to OpenAI.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists files uploaded to OpenAI for the current user or organization.
 */
class WP_MCP_AI_Tool_List_OpenAI_Files implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_openai_files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List OpenAI Files', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists files uploaded to OpenAI. Use this to audit uploaded files, find files by purpose (assistants, fine-tune), check file quotas, or clean up old/unused files.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'purpose' => array(
					'type'        => 'string',
					'description' => __( 'Filter files by purpose (e.g., assistants, fine-tune, batch).', 'mcp-ai-wpoos' ),
				),
				'limit'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of files to return. Range: 1-100.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'order'   => array(
					'type'        => 'string',
					'enum'        => array( 'asc', 'desc' ),
					'description' => __( 'Sort order by creation date.', 'mcp-ai-wpoos' ),
					'default'     => 'desc',
				),
				'after'   => array(
					'type'        => 'string',
					'description' => __( 'Cursor for pagination. Use the last file ID from previous results.', 'mcp-ai-wpoos' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check permissions.
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to list OpenAI files.', 'mcp-ai-wpoos' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}
		// Build arguments for the OpenAI client.
		$api_args = array();

		if ( isset( $arguments['purpose'] ) && '' !== $arguments['purpose'] ) {
			$api_args['purpose'] = sanitize_key( $arguments['purpose'] );
		}

		if ( isset( $arguments['limit'] ) && '' !== $arguments['limit'] ) {
			$limit             = absint( $arguments['limit'] );
			$api_args['limit'] = max( 1, min( 100, $limit ) );
		}

		if ( isset( $arguments['order'] ) && '' !== $arguments['order'] ) {
			$order = sanitize_text_field( $arguments['order'] );
			if ( in_array( $order, array( 'asc', 'desc' ), true ) ) {
				$api_args['order'] = $order;
			}
		}

		if ( isset( $arguments['after'] ) && '' !== $arguments['after'] ) {
			$api_args['after'] = sanitize_text_field( $arguments['after'] );
		}

		// Call OpenAI API.
		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->list_files( $api_args );

		// Handle errors.
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				$result->get_error_data()
			);
		}

		// Format the response.
		$files = array();
		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			foreach ( $result['data'] as $file ) {
				$files[] = array(
					'id'         => isset( $file['id'] ) ? $file['id'] : '',
					'filename'   => isset( $file['filename'] ) ? $file['filename'] : '',
					'purpose'    => isset( $file['purpose'] ) ? $file['purpose'] : '',
					'bytes'      => isset( $file['bytes'] ) ? $file['bytes'] : 0,
					'created_at' => isset( $file['created_at'] ) ? gmdate( 'Y-m-d H:i:s', $file['created_at'] ) : '',
					'status'     => isset( $file['status'] ) ? $file['status'] : '',
				);
			}
		}

		$has_more = isset( $result['has_more'] ) ? $result['has_more'] : false;

		$summary_text = sprintf(
			/* translators: %d: number of files */
			__( 'Found %d files in OpenAI storage.', 'mcp-ai-wpoos' ),
			count( $files )
		);

		return array(
			'success'     => true,
			'files'       => $files,
			'total_count' => count( $files ),
			'has_more'    => $has_more,
			'message'     => $summary_text,
			'summary'     => $summary_text,
		);
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

			'pattern_compatibility' => array( 'experimentation' ),

			'profession_tags'       => array( 'machine_learning_engineer', 'data_scientist' ),

			'risk_level'            => 'info',

		);

	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'external-api',         // Makes external API calls to OpenAI.
			'requires-capability',  // Requires 'manage_options' capability.
		);
	}
}
