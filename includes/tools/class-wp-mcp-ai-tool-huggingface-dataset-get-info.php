<?php
/**
 * Tool for getting HuggingFace dataset information.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_Huggingface_Dataset_Get_Info' ) ) {
	/**
	 * Gets comprehensive dataset information and metadata.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Tool_Huggingface_Dataset_Get_Info implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'The HuggingFace Dataset Get Info tool is disabled because HuggingFace Datasets integration is not enabled. Enable it in WP oOS → Providers settings.', 'wp-mcp-ai' );
		}

		/**
		 * Get tool slug.
		 *
		 * @return string
		 */
		public function get_slug() {
			return 'huggingface_dataset_get_info';
		}

	/**
	 * Get tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'HuggingFace Dataset Get Info', 'wp-mcp-ai' );
	}

	/**
	 * Get tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Get metadata and information about a HuggingFace dataset', 'wp-mcp-ai' );
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
				'name'        => 'Get Dataset Information',
				'description' => 'Get comprehensive information about a HuggingFace dataset including description, features, and metadata',
				'parameters'  => array(
					'dataset' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'Dataset name (e.g., "squad", "imdb")',
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
		public function execute( array $arguments = array(), array $context = array() ) {
			// Check if HuggingFace Datasets is enabled.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( empty( $settings['enable_huggingface_datasets'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_hf_datasets_disabled',
					__( 'HuggingFace Datasets integration is not enabled. Enable it in WP oOS → Providers settings.', 'wp-mcp-ai' )
				);
			}

			// Get dataset name.
			$dataset = sanitize_text_field( $arguments['dataset'] );

			if ( empty( $dataset ) ) {
				return new WP_Error(
					'wp_mcp_ai_hf_datasets_empty_dataset',
					__( 'Dataset name cannot be empty.', 'wp-mcp-ai' )
				);
			}

			// Get client.
			$client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );

			// Get info.
			$result = $client->get_info( $dataset );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $result;
		}
	}
}
