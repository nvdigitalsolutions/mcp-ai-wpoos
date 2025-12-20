<?php
/**
 * Tool that manages files in OpenAI vector stores.
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
 * Manages files in OpenAI vector stores.
 */
class WP_MCP_AI_Tool_Manage_Vector_Store_Files implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'manage_vector_store_files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage Vector Store Files', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Add, remove, or list files in an OpenAI vector store. Manages the knowledge base contents for RAG applications.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'vector_store_id' => array(
					'type'        => 'string',
					'description' => __( 'The ID of the vector store to manage.', 'wp-mcp-ai' ),
				),
				'action'          => array(
					'type'        => 'string',
					'description' => __( 'The action to perform: add, remove, or list.', 'wp-mcp-ai' ),
					'enum'        => array( 'add', 'remove', 'list' ),
				),
				'file_ids'        => array(
					'type'        => 'array',
					'description' => __( 'Array of OpenAI file IDs (required for add/remove actions).', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'limit'           => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of files to return when listing (1-100, default 20).', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'order'           => array(
					'type'        => 'string',
					'description' => __( 'Sort order when listing (asc or desc).', 'wp-mcp-ai' ),
					'enum'        => array( 'asc', 'desc' ),
					'default'     => 'desc',
				),
			),
			'required'   => array( 'vector_store_id', 'action' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate vector_store_id.
		if ( empty( $arguments['vector_store_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'The vector_store_id parameter is required.', 'wp-mcp-ai' ),
			);
		}

		// Validate action.
		if ( empty( $arguments['action'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'The action parameter is required.', 'wp-mcp-ai' ),
			);
		}

		$vector_store_id = sanitize_text_field( $arguments['vector_store_id'] );
		$action          = sanitize_key( $arguments['action'] );

		$client = new WP_MCP_AI_OpenAI_Client();

		switch ( $action ) {
			case 'add':
				return $this->add_files( $client, $vector_store_id, $arguments );

			case 'remove':
				return $this->remove_files( $client, $vector_store_id, $arguments );

			case 'list':
				return $this->list_files( $client, $vector_store_id, $arguments );

			default:
				return array(
					'success' => false,
					'error'   => __( 'Invalid action. Must be add, remove, or list.', 'wp-mcp-ai' ),
				);
		}
	}

	/**
	 * Add files to vector store.
	 *
	 * @param WP_MCP_AI_OpenAI_Client $client OpenAI client.
	 * @param string                  $vector_store_id Vector store ID.
	 * @param array                   $arguments Arguments.
	 * @return array Result.
	 */
	private function add_files( $client, $vector_store_id, $arguments ) {
		if ( empty( $arguments['file_ids'] ) || ! is_array( $arguments['file_ids'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'The file_ids parameter is required for add action.', 'wp-mcp-ai' ),
			);
		}

		$file_ids = array_map( 'sanitize_text_field', $arguments['file_ids'] );
		$results  = array();
		$errors   = array();

		// Add files one at a time (OpenAI API limitation).
		foreach ( $file_ids as $file_id ) {
			$result = $client->add_vector_store_files( $vector_store_id, array( $file_id ) );

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'file_id' => $file_id,
					'error'   => $result->get_error_message(),
				);
			} else {
				$results[] = array(
					'file_id' => $file_id,
					'status'  => isset( $result['status'] ) ? $result['status'] : 'added',
				);
			}
		}

		return array(
			'success' => empty( $errors ),
			'data'    => array(
				'added'  => $results,
				'errors' => $errors,
				'total'  => count( $file_ids ),
			),
		);
	}

	/**
	 * Remove files from vector store.
	 *
	 * @param WP_MCP_AI_OpenAI_Client $client OpenAI client.
	 * @param string                  $vector_store_id Vector store ID.
	 * @param array                   $arguments Arguments.
	 * @return array Result.
	 */
	private function remove_files( $client, $vector_store_id, $arguments ) {
		if ( empty( $arguments['file_ids'] ) || ! is_array( $arguments['file_ids'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'The file_ids parameter is required for remove action.', 'wp-mcp-ai' ),
			);
		}

		$file_ids = array_map( 'sanitize_text_field', $arguments['file_ids'] );
		$results  = array();
		$errors   = array();

		foreach ( $file_ids as $file_id ) {
			$result = $client->remove_vector_store_file( $vector_store_id, $file_id );

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'file_id' => $file_id,
					'error'   => $result->get_error_message(),
				);
			} else {
				$results[] = array(
					'file_id' => $file_id,
					'deleted' => isset( $result['deleted'] ) ? $result['deleted'] : true,
				);
			}
		}

		return array(
			'success' => empty( $errors ),
			'data'    => array(
				'removed' => $results,
				'errors'  => $errors,
				'total'   => count( $file_ids ),
			),
		);
	}

	/**
	 * List files in vector store.
	 *
	 * @param WP_MCP_AI_OpenAI_Client $client OpenAI client.
	 * @param string                  $vector_store_id Vector store ID.
	 * @param array                   $arguments Arguments.
	 * @return array Result.
	 */
	private function list_files( $client, $vector_store_id, $arguments ) {
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

		$result = $client->list_vector_store_files( $vector_store_id, $options );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'files'     => isset( $result['data'] ) ? $result['data'] : array(),
				'has_more'  => isset( $result['has_more'] ) ? $result['has_more'] : false,
				'first_id'  => isset( $result['first_id'] ) ? $result['first_id'] : null,
				'last_id'   => isset( $result['last_id'] ) ? $result['last_id'] : null,
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'external-api',
			'requires-capability',
			'modifies-state',
		);
	}
}
