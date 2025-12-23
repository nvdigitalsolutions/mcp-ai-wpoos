<?php
/**
 * Tool for previewing HuggingFace dataset rows.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_Huggingface_Dataset_Preview_Rows' ) ) {
	/**
	 * Previews first rows of a dataset split for quick inspection.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Tool_Huggingface_Dataset_Preview_Rows implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'The HuggingFace Dataset Preview Rows tool is disabled because HuggingFace Datasets integration is not enabled. Enable it in WP oOS → Providers settings.', 'wp-mcp-ai' );
		}

		/**
		 * Get tool slug.
		 *
		 * @return string
		 */
		public function get_slug() {
			return 'huggingface_dataset_preview_rows';
		}

	/**
	 * Get tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'HuggingFace Dataset Preview Rows', 'wp-mcp-ai' );
	}

	/**
	 * Get tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Preview the first few rows of a HuggingFace dataset split', 'wp-mcp-ai' );
	}

	/**
	 * Get tool parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'dataset' => array(
					'type'        => 'string',
					'description' => 'Dataset name (e.g., "squad", "imdb")',
				),
				'config'  => array(
					'type'        => 'string',
					'description' => 'Configuration name (default: "default")',
					'default'     => 'default',
				),
				'split'   => array(
					'type'        => 'string',
					'description' => 'Split name (e.g., "train", "test", "validation")',
				),
				'limit'   => array(
					'type'        => 'integer',
					'description' => 'Number of rows to return (max 100)',
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				),
			),
			'required'   => array( 'dataset', 'split' ),
			'additionalProperties' => false,
		);
	}

		/**
		 * Get tool definition for MCP.
		 *
		 * @return array
		 */
		public function get_definition() {
			return array(
				'name'        => 'Preview Dataset Rows',
				'description' => 'Get first rows of a HuggingFace dataset split for quick inspection',
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
					'limit'   => array(
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
		public function execute( array $arguments = array(), array $context = array() ) {
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
			$limit   = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
			$limit   = max( 1, min( 100, $limit ) );

			if ( empty( $dataset ) || empty( $split ) ) {
				return new WP_Error(
					'wp_mcp_ai_hf_datasets_missing_params',
					__( 'Dataset and split are required.', 'wp-mcp-ai' )
				);
			}

			// Get client.
			$client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );

			// Preview rows.
			$result = $client->preview_rows( $dataset, $config, $split, $limit );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $result;
		}
	}
}
