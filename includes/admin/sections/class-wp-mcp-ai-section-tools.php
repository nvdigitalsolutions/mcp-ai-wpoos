<?php
/**
 * Tools & Features Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Tools' ) ) {
	/**
	 * Tools & Features settings section.
	 */
	class WP_MCP_AI_Section_Tools extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'tools';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Tools & Features Configuration', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'tools';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure tool limits, mesh computing, and federation settings.', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				'enable_mesh_computing' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Mesh Computing', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable distributed computing features', 'wp-mcp-ai' ),
					'description'    => __( 'Allows this instance to participate in mesh computing networks.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'enable_federation'     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Federation', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable federated discovery', 'wp-mcp-ai' ),
					'description'    => __( 'Allows this instance to be discovered by and connect to other WP oOS instances.', 'wp-mcp-ai' ),
					'default'        => false,
				),
			);
		}

		/**
		 * Get sub-tab groups configuration.
		 *
		 * @return array
		 */
		private function get_subtab_groups() {
			return array(
				'features' => array(
					'id'     => 'features',
					'label'  => __( 'Features', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-tools',
					'fields' => array( 'enable_mesh_computing', 'enable_federation' ),
				),
			);
		}

		/**
		 * Get active sub-tab.
		 *
		 * @return string
		 */
		private function get_active_subtab() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter.
			$subtab        = isset( $_GET['subtab'] ) ? sanitize_key( $_GET['subtab'] ) : 'features';
			$subtab_groups = $this->get_subtab_groups();

			if ( ! isset( $subtab_groups[ $subtab ] ) ) {
				$subtab = 'features';
			}

			return $subtab;
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$fields        = $this->get_fields();
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();

			// Get the active group.
			if ( ! isset( $subtab_groups[ $active_subtab ] ) ) {
				return;
			}

			$active_group = $subtab_groups[ $active_subtab ];

			// Render fields for the active sub-tab.
			foreach ( $active_group['fields'] as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$this->render_field( $key, $fields[ $key ] );
				}
			}
		}

		/**
		 * Override render_wrapper to include sub-tab navigation.
		 */
		public function render_wrapper() {
			$description   = $this->get_description();
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();
			?>
			<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
				<h2><?php echo esc_html( $this->get_title() ); ?></h2>
				<?php if ( $description ) : ?>
					<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>

				<div class="wp-mcp-ai-provider-subtabs">
					<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'Tools settings sub-tabs', 'wp-mcp-ai' ); ?>">
						<?php foreach ( $subtab_groups as $group ) : ?>
							<?php
							$subtab_url = add_query_arg(
								array(
									'page'   => 'wp-mcp-ai-dashboard',
									'tab'    => 'tools',
									'subtab' => $group['id'],
								),
								admin_url( 'admin.php' )
							);
							$is_active  = ( $group['id'] === $active_subtab );
							?>
							<a href="<?php echo esc_url( $subtab_url ); ?>" 
							   class="wp-mcp-ai-subtab <?php echo $is_active ? 'wp-mcp-ai-subtab-active' : ''; ?>"
							   data-subtab="<?php echo esc_attr( $group['id'] ); ?>">
								<span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>"></span>
								<?php echo esc_html( $group['label'] ); ?>
							</a>
						<?php endforeach; ?>
					</nav>

					<div class="wp-mcp-ai-subtab-content">
						<table class="form-table" role="presentation">
							<?php $this->render(); ?>
						</table>
					</div>
				</div>
			</div>
			<?php
		}
	}
}
