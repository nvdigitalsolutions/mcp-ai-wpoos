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
		 * Commerce subtab groups (wp-admin settings dashboard).
		 *
		 * The four toolkit sections keep their own registry tabs for the
		 * settings schema / SPA contract, but in wp-admin they render as
		 * subtabs of the E-Commerce tab (Tools → Connections pattern).
		 *
		 * @return array
		 */
		protected function get_commerce_subtab_groups() {
			return array(
				'ecommerce'    => array(
					'id'    => 'ecommerce',
					'label' => __( 'E-Commerce Toolkit', 'mcp-ai-wpoos-pro' ),
					'icon'  => 'dashicons-cart',
				),
				'ezuite'       => array(
					'id'    => 'ezuite',
					'label' => __( 'EZuite ERP', 'mcp-ai-wpoos-pro' ),
					'icon'  => 'dashicons-networking',
				),
				'shopify_sync' => array(
					'id'    => 'shopify_sync',
					'label' => __( 'Shopify Sync', 'mcp-ai-wpoos-pro' ),
					'icon'  => 'dashicons-store',
				),
				'flowhub'      => array(
					'id'    => 'flowhub',
					'label' => __( 'FlowHub', 'mcp-ai-wpoos-pro' ),
					'icon'  => 'dashicons-shield-alt',
				),
			);
		}

		/**
		 * Get the active commerce subtab.
		 *
		 * Reads the posted `subtab` field first (form submission), then the
		 * URL `subtab`, then falls back to a legacy `?tab=<toolkit>` URL so
		 * pre-existing links land on the right toolkit.
		 *
		 * @return string
		 */
		protected function get_active_subtab() {
			$groups = $this->get_commerce_subtab_groups();
			$subtab = '';

			// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Read-only UI state parameter.
			if ( isset( $_POST['subtab'] ) && ! empty( $_POST['subtab'] ) ) {
				$subtab = sanitize_key( wp_unslash( $_POST['subtab'] ) );
			} elseif ( isset( $_GET['subtab'] ) ) {
				$subtab = sanitize_key( wp_unslash( $_GET['subtab'] ) );
			} elseif ( isset( $_GET['tab'] ) ) {
				$legacy_tab = sanitize_key( wp_unslash( $_GET['tab'] ) );
				if ( isset( $groups[ $legacy_tab ] ) ) {
					$subtab = $legacy_tab;
				}
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

			if ( empty( $subtab ) || ! isset( $groups[ $subtab ] ) ) {
				$subtab = 'ecommerce';
			}

			return $subtab;
		}

		/**
		 * Render section wrapper with the commerce subtab navigation.
		 *
		 * The E-Commerce section is the host: it always renders the subtab
		 * nav and renders its own fields only while its subtab is active.
		 * The sibling sections render only while their subtab is active.
		 */
		public function render_wrapper() {
			$slug        = $this->get_toolkit_slug();
			$groups      = $this->get_commerce_subtab_groups();
			$active      = $this->get_active_subtab();
			$is_host     = ( 'ecommerce' === $slug );
			$render_self = ( $active === $slug );

			if ( ! $is_host && ! $render_self ) {
				return;
			}

			$description       = $this->get_description();
			$documentation_url = $this->get_documentation_url();
			?>
			<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
				<h2><?php echo esc_html( $this->get_title() ); ?></h2>

				<?php if ( $is_host ) : ?>
					<div class="wp-mcp-ai-provider-subtabs">
						<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'Commerce settings sub-tabs', 'mcp-ai-wpoos-pro' ); ?>">
							<?php foreach ( $groups as $group ) : ?>
								<?php
								$subtab_url = add_query_arg(
									array(
										'page'   => 'wp-mcp-ai-dashboard',
										'tab'    => 'ecommerce',
										'subtab' => $group['id'],
									),
									admin_url( 'admin.php' )
								);
								$is_active  = ( $group['id'] === $active );
								?>
								<a href="<?php echo esc_url( $subtab_url ); ?>"
									class="wp-mcp-ai-subtab <?php echo esc_attr( $is_active ? 'wp-mcp-ai-subtab-active' : '' ); ?>"
									data-subtab="<?php echo esc_attr( $group['id'] ); ?>">
									<span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>"></span>
									<?php echo esc_html( $group['label'] ); ?>
								</a>
							<?php endforeach; ?>
						</nav>

						<!-- Hidden fields preserve the subtab during form submission. -->
						<input type="hidden" name="subtab_<?php echo esc_attr( $this->get_id() ); ?>" value="<?php echo esc_attr( $active ); ?>" />
						<input type="hidden" name="subtab" value="<?php echo esc_attr( $active ); ?>" />
					</div>
				<?php endif; ?>

				<?php if ( $render_self ) : ?>
					<?php if ( $description ) : ?>
						<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
					<?php endif; ?>
					<?php if ( $documentation_url ) : ?>
						<p class="section-documentation">
							<span class="dashicons dashicons-book-alt" style="color: #2271b1;"></span>
							<a href="<?php echo esc_url( $documentation_url ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'View Documentation', 'mcp-ai-wpoos' ); ?>
								<span class="dashicons dashicons-external" style="font-size: 14px; text-decoration: none;"></span>
							</a>
						</p>
					<?php endif; ?>
					<table class="form-table" role="presentation">
						<?php $this->render(); ?>
					</table>
				<?php endif; ?>
			</div>
			<?php
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
