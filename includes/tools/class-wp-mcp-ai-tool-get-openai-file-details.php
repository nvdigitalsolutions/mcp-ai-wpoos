<?php
/**
 * Tool that retrieves detailed metadata about a specific OpenAI file.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves detailed metadata about a specific OpenAI file.
 */
class WP_MCP_AI_Tool_Get_OpenAI_File_Details implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_openai_file_details';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get OpenAI File Details', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves detailed metadata about a specific OpenAI file. Use this to verify file upload success, check file processing status, get file size and format info, or debug file-related issues.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'file_id' => array(
					'type'        => 'string',
					'description' => __( 'The OpenAI file identifier (e.g., file-abc123).', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'file_id' ),
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
		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to retrieve OpenAI file details.', 'wp-mcp-ai' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Validate file_id.
		if ( ! isset( $arguments['file_id'] ) || '' === $arguments['file_id'] ) {
			return new WP_Error(
				'wp_mcp_ai_missing_file_id',
				__( 'File ID is required.', 'wp-mcp-ai' )
			);
		}

		$file_id = sanitize_text_field( $arguments['file_id'] );

		// Call OpenAI API.
		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->retrieve_file( $file_id );

		// Handle errors.
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				$result->get_error_data()
			);
		}

		// Format the response.
		$file_details = array(
			'id'             => isset( $result['id'] ) ? $result['id'] : '',
			'object'         => isset( $result['object'] ) ? $result['object'] : '',
			'bytes'          => isset( $result['bytes'] ) ? $result['bytes'] : 0,
			'created_at'     => isset( $result['created_at'] ) ? gmdate( 'Y-m-d H:i:s', $result['created_at'] ) : '',
			'filename'       => isset( $result['filename'] ) ? $result['filename'] : '',
			'purpose'        => isset( $result['purpose'] ) ? $result['purpose'] : '',
			'status'         => isset( $result['status'] ) ? $result['status'] : '',
			'status_details' => isset( $result['status_details'] ) ? $result['status_details'] : null,
		);

		return array(
			'success'      => true,
			'file'         => $file_details,
			'summary'      => sprintf(
				/* translators: 1: filename, 2: file size in bytes */
				__( 'Retrieved details for file "%1$s" (%2$d bytes).', 'wp-mcp-ai' ),
				$file_details['filename'],
				$file_details['bytes']
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'external-api',         // Makes external API calls to OpenAI.
			'requires-capability',  // Requires 'read' capability.
		);
	}
}
