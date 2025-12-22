<?php
/**
 * Tool that lists OpenAI vector stores.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Lists OpenAI vector stores.
 */
class WP_MCP_AI_Tool_List_Vector_Stores implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_vector_stores';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Vector Stores', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists all OpenAI vector stores with optional filtering and pagination. Use this to discover available knowledge bases.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'limit'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of vector stores to return (1-100, default 20).', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'order'  => array(
					'type'        => 'string',
					'description' => __( 'Sort order (asc or desc, default desc).', 'wp-mcp-ai' ),
					'enum'        => array( 'asc', 'desc' ),
					'default'     => 'desc',
				),
				'after'  => array(
					'type'        => 'string',
					'description' => __( 'Cursor for pagination (ID of the last item from previous page).', 'wp-mcp-ai' ),
				),
				'before' => array(
					'type'        => 'string',
					'description' => __( 'Cursor for reverse pagination (ID of the first item from previous page).', 'wp-mcp-ai' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$options = array();

		if ( ! empty( $arguments['limit'] ) ) {
			$options['limit'] = absint( $arguments['limit'] );
		}

		if ( ! empty( $arguments['order'] ) ) {
			$options['order'] = sanitize_key( $arguments['order'] );
		}

		if ( ! empty( $arguments['after'] ) ) {
			$options['after'] = sanitize_text_field( $arguments['after'] );
		}

		if ( ! empty( $arguments['before'] ) ) {
			$options['before'] = sanitize_text_field( $arguments['before'] );
		}

		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->list_vector_stores( $options );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'vector_stores' => isset( $result['data'] ) ? $result['data'] : array(),
				'has_more'      => isset( $result['has_more'] ) ? $result['has_more'] : false,
				'first_id'      => isset( $result['first_id'] ) ? $result['first_id'] : null,
				'last_id'       => isset( $result['last_id'] ) ? $result['last_id'] : null,
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'external-api',
			'requires-capability',
			'read-only',
			'cacheable',
		);
	}
}
