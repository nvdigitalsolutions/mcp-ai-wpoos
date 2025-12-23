<?php
/**
 * Tool for getting paginated rows from HuggingFace dataset.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_Huggingface_Dataset_Get_Rows' ) ) {
	/**
	 * Provides paginated access to dataset rows with offset and length control.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Tool_Huggingface_Dataset_Get_Rows implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

		/**
		 * Check if the tool is available.
		 *
		 * @return bool
		 */
		public static function is_available() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return ! empty( $settings['enable_huggingface_datasets'] );
		}

		/**
		 * Message explaining why the tool is unavailable.
		 *
		 * @return string
		 */
		public static function get_unavailable_reason() {
			return __( 'The HuggingFace Dataset Get Rows tool is disabled because HuggingFace Datasets integration is not enabled. Enable it in WP oOS → Providers settings.', 'wp-mcp-ai' );
		}

		/**
		 * Get tool slug.
		 *
		 * @return string
		 */
		public function get_slug() {
			return 'huggingface_dataset_get_rows';
		}

	/**
	 * Get tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'HuggingFace Dataset Get Rows', 'wp-mcp-ai' );
	}

	/**
	 * Get tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Retrieve rows from a HuggingFace dataset split', 'wp-mcp-ai' );
	}

	/**
	 * Get tool parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		$definition = $this->get_definition();
		return isset( $definition['parameters'] ) ? $definition['parameters'] : array();
	}

		/**
		 * Get tool definition for MCP.
		 *
		 * @return array
		 */
		public function get_definition() {
			return array(
				'name'        => 'Get Dataset Rows',
				'description' => 'Get paginated rows from a HuggingFace dataset split with offset and length control',
				'parameters'  => array(
					'dataset' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'Dataset name (e.g., "squad", "imdb")',
					),
					'config'  => array(
						'type'        => 'string',
						'required'    => false,
						'description' => 'Configuration name (default: "default")',
						'default'     => 'default',
					),
					'split'   => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'Split name (e.g., "train", "test", "validation")',
					),
					'offset'  => array(
						'type'        => 'integer',
						'required'    => false,
						'description' => 'Starting row (0-based index)',
						'default'     => 0,
						'minimum'     => 0,
					),
					'length'  => array(
						'type'        => 'integer',
						'required'    => false,
						'description' => 'Number of rows to return (max 100)',
						'default'     => 10,
						'minimum'     => 1,
						'maximum'     => 100,
					),
				),
			);
		}

		/**
		 * Get required capability.
		 *
		 * @return string
		 */
		public function get_required_capability() {
			return apply_filters( 'wp_mcp_ai_tool_huggingface_datasets_capability', 'read' );
		}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array<string> Array of capability flag strings.
	 */
	public function get_capability_flags() {
		return array(
			'external-api',        // Makes external API calls to HuggingFace.
			'network-dependent',   // Requires internet connectivity.
			'read-only',           // Only reads data, doesn't modify WordPress state.
			'cacheable',           // Results can be cached.
			'paginated',           // Supports pagination.
			'large-response',      // May return large datasets.
		);
	}

		/**
		 * Execute the tool.
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 * @return array|WP_Error
		 */
		public function execute( $arguments, $context ) {
			// Check if HuggingFace Datasets is enabled.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( empty( $settings['enable_huggingface_datasets'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_hf_datasets_disabled',
					__( 'HuggingFace Datasets integration is not enabled. Enable it in WP oOS → Providers settings.', 'wp-mcp-ai' )
				);
			}

			// Sanitize inputs.
			$dataset = sanitize_text_field( $arguments['dataset'] );
			$config  = isset( $arguments['config'] ) ? sanitize_text_field( $arguments['config'] ) : 'default';
			$split   = sanitize_text_field( $arguments['split'] );
			$offset  = isset( $arguments['offset'] ) ? absint( $arguments['offset'] ) : 0;
			$length  = isset( $arguments['length'] ) ? absint( $arguments['length'] ) : 10;
			$length  = max( 1, min( 100, $length ) );

			if ( empty( $dataset ) || empty( $split ) ) {
				return new WP_Error(
					'wp_mcp_ai_hf_datasets_missing_params',
					__( 'Dataset and split are required.', 'wp-mcp-ai' )
				);
			}

			// Get client.
			$client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );

			// Get rows.
			$result = $client->get_rows( $dataset, $config, $split, $offset, $length );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $result;
		}
	}
}
