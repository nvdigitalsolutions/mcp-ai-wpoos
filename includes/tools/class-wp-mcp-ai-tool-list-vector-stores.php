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
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Lists OpenAI vector stores.
 */
class WP_MCP_AI_Tool_List_Vector_Stores implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Chat_Response;

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
		return __( 'List Vector Stores', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists all OpenAI vector stores with optional filtering and pagination. Use this to discover available knowledge bases.', 'mcp-ai-wpoos' );
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
					'description' => __( 'Maximum number of vector stores to return (1-100, default 20).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'order'  => array(
					'type'        => 'string',
					'description' => __( 'Sort order (asc or desc, default desc).', 'mcp-ai-wpoos' ),
					'enum'        => array( 'asc', 'desc' ),
					'default'     => 'desc',
				),
				'after'  => array(
					'type'        => 'string',
					'description' => __( 'Cursor for pagination (ID of the last item from previous page).', 'mcp-ai-wpoos' ),
				),
				'before' => array(
					'type'        => 'string',
					'description' => __( 'Cursor for reverse pagination (ID of the first item from previous page).', 'mcp-ai-wpoos' ),
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

		$vector_stores = isset( $result['data'] ) ? $result['data'] : array();
		$count         = count( $vector_stores );
		$has_more      = isset( $result['has_more'] ) ? $result['has_more'] : false;

		$message = $this->format_collection_response(
			$count,
			__( 'vector store', 'mcp-ai-wpoos' ),
			__( 'vector stores', 'mcp-ai-wpoos' ),
			$has_more
		);

		return array(
			'success' => true,
			'message' => $message,
			'text'    => $message,
			'data'    => array(
				'vector_stores' => $vector_stores,
				'has_more'      => $has_more,
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

			'toolkit'               => 'data_analytics',

			'pattern_compatibility' => array( 'orchestrator' ),

			'profession_tags'       => array( 'data_scientist' ),

			'risk_level'            => 'info',

		);
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
