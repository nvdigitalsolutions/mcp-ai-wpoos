<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Descriptive file names follow WordPress kebab-case conventions for better readability.
/**
 * Commerce Toolkit Settings Section — shared base
 *
 * Proposal 089 Phase 2b: the four commerce toolkit option groups
 * (E-Commerce, EZuite, Shopify Sync, FlowHub) publish through the
 * schema-driven settings surface (`mcp-ai/v1/settings/schema`) so the
 * Complete Console renders them without per-toolkit form code.
 *
 * The enable toggle is the real toolkit switch stored in
 * `wp_mcp_ai_settings`; the remaining fields are advisory sync-guardrail
 * defaults (safety buffer, reconciliation cadence, per-channel rate cap)
 * that the sync engines read as they adopt the Proposal 089 contract.
 *
 * @package WP_MCP_AI_Pro
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

if ( ! class_exists( 'WP_MCP_AI_Commerce_Toolkit_Settings_Section' ) ) {
	/**
	 * Shared base for the four commerce toolkit settings sections.
	 */
	abstract class WP_MCP_AI_Commerce_Toolkit_Settings_Section extends WP_MCP_AI_Settings_Section {

		/**
		 * Toolkit slug used for advisory field keys (e.g. `ecommerce`).
		 *
		 * @return string
		 */
		abstract protected function get_toolkit_slug();

		/**
		 * Field definitions — the shared sync-guardrail fields, keyed with
		 * the toolkit slug so the schema stays flat.
		 *
		 * The enable toggle for each toolkit is NOT declared here: the base
		 * Tools section (tab `tools`) owns `enable_*_toolkit`, and the
		 * schema-driven settings surface dedupes by key (first registrant
		 * wins). The console commerce page pulls the toggle by key.
		 *
		 * @return array
		 */
		public function get_fields() {
			$slug = $this->get_toolkit_slug();

			return array(
				$slug . '_safety_buffer'           => array(
					'type'        => 'number',
					'label'       => __( 'Safety buffer (units per SKU)', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Units held back from the sellable quantity to absorb cross-channel sync lag. Industry guidance: 1–3 units.', 'mcp-ai-wpoos-pro' ),
					'default'     => 2,
					'min'         => 0,
					'max'         => 10,
					'step'        => 1,
				),
				$slug . '_reconcile_cadence_hours' => array(
					'type'        => 'select',
					'label'       => __( 'Reconciliation cadence', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Full-catalog reconciliation frequency — the safety net behind webhook sync. Active SKUs reconcile more often.', 'mcp-ai-wpoos-pro' ),
					'options'     => array(
						'6'  => __( 'Every 6 hours', 'mcp-ai-wpoos-pro' ),
						'12' => __( 'Every 12 hours', 'mcp-ai-wpoos-pro' ),
						'24' => __( 'Every 24 hours', 'mcp-ai-wpoos-pro' ),
					),
					'default'     => '12',
				),
				$slug . '_rate_limit_per_minute'   => array(
					'type'        => 'number',
					'label'       => __( 'API rate cap (requests/minute)', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Upper bound for sync-engine requests to the channel API, protecting against platform rate limits.', 'mcp-ai-wpoos-pro' ),
					'default'     => 40,
					'min'         => 1,
					'max'         => 120,
					'step'        => 1,
				),
			);
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 40;
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * @return string
		 */
		public function get_documentation_url() {
			return '';
		}

		/**
		 * Render section fields (wp-admin settings dashboard parity).
		 */
		public function render() {
			foreach ( $this->get_fields() as $key => $field ) {
				$this->render_field( $key, $field );
			}
		}

		/**
		 * Validate section input — passthrough; per-key sanitisation is the
		 * settings REST controller's job (schema-driven writes).
		 *
		 * @param array $input Raw input.
		 * @return array
		 */
		public function validate( $input ) {
			return $input;
		}
	}
}
