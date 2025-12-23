<?php
/**
 * Tool for checking if a HuggingFace dataset is valid.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_Huggingface_Dataset_Is_Valid' ) ) {
	/**
	 * Validates if a HuggingFace dataset exists and is accessible.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Tool_Huggingface_Dataset_Is_Valid {

		/**
		 * Get tool slug.
		 *
		 * @return string
		 */
		public function get_slug() {
			return 'huggingface_dataset_is_valid';
		}

		/**
		 * Get tool definition for MCP.
		 *
		 * @return array
		 */
		public function get_definition() {
			return array(
				'name'        => 'Validate HuggingFace Dataset',
				'description' => 'Check if a HuggingFace dataset exists and is accessible via the Dataset Viewer API',
				'parameters'  => array(
					'dataset' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'Dataset name (e.g., "squad", "imdb", "stanfordnlp/imdb")',
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
			$client = WP_MCP_AI_Container::get( 'client.huggingface_datasets' );

			// Check validity.
			$result = $client->is_valid( $dataset );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'dataset' => $dataset,
				'valid'   => true,
				'message' => sprintf(
					/* translators: %s: dataset name */
					__( 'Dataset "%s" is valid and accessible.', 'wp-mcp-ai' ),
					$dataset
				),
			);
		}
	}
}
