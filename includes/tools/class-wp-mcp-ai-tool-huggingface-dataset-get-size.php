<?php
/**
 * Tool for getting HuggingFace dataset size information.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_Huggingface_Dataset_Get_Size' ) ) {
	/**
	 * Gets dataset size information including row counts and byte sizes.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Tool_Huggingface_Dataset_Get_Size {

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
			return __( 'The HuggingFace Dataset Get Size tool is disabled because HuggingFace Datasets integration is not enabled. Enable it in WP oOS → Providers settings.', 'wp-mcp-ai' );
		}

		/**
		 * Get tool slug.
		 *
		 * @return string
		 */
		public function get_slug() {
			return 'huggingface_dataset_get_size';
		}

	/**
	 * Get tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'HuggingFace Dataset Get Size', 'wp-mcp-ai' );
	}

	/**
	 * Get tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Get the size information of a HuggingFace dataset split', 'wp-mcp-ai' );
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
				'name'        => 'Get Dataset Size',
				'description' => 'Get size information for a HuggingFace dataset including row counts and storage size',
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

			// Get size.
			$result = $client->get_size( $dataset );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $result;
		}
	}
}
