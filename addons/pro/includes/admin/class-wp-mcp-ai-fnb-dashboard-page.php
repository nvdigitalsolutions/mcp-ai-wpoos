<?php
/**
 * F&B Management Toolkit — Dashboard Page.
 *
 * Landing page for the top-level "F&B Management" admin menu: toolkit and
 * native-storage status, per-table row counts, and shortcuts to the CPT
 * screens and the toolkit settings page.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * F&B Management dashboard page handler.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Dashboard_Page {

	/**
	 * Render the dashboard page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$toolkit_enabled = class_exists( 'WP_MCP_AI_Fnb_Settings' ) && WP_MCP_AI_Fnb_Settings::is_enabled();
		$native_on       = class_exists( 'WP_MCP_AI_Fnb_Settings' ) && WP_MCP_AI_Fnb_Settings::is_native_storage_enabled();
		$adapter         = class_exists( 'WP_MCP_AI_Fnb_Settings' ) ? WP_MCP_AI_Fnb_Settings::get( 'adapter', 'drive' ) : 'drive';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'F&B Management', 'mcp-ai-wpoos-pro' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Restaurant operating metrics (M-01…M-21) and reports (R-01…R-13) with optional WordPress native storage for the transactional tables.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<table class="widefat striped" style="max-width: 720px; margin: 1em 0;">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Toolkit', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php if ( $toolkit_enabled ) : ?>
								<span style="color: #2271b1;">&#9679;</span> <?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?>
							<?php else : ?>
								<span style="color: #b32d2e;">&#9679;</span> <?php esc_html_e( 'Disabled — enable it under NV oOS Settings → Tools → Pro Features.', 'mcp-ai-wpoos-pro' ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Data adapter', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( 'drive' === $adapter ? __( 'Google Drive', 'mcp-ai-wpoos-pro' ) : __( 'CSV (local files)', 'mcp-ai-wpoos-pro' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Native storage', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php if ( $native_on ) : ?>
								<span style="color: #2271b1;">&#9679;</span> <?php esc_html_e( 'Enabled — transactional tables resolve from WordPress posts.', 'mcp-ai-wpoos-pro' ); ?>
							<?php else : ?>
								<span style="color: #b32d2e;">&#9679;</span> <?php esc_html_e( 'Disabled — transactional tables resolve from the Drive/CSV adapter.', 'mcp-ai-wpoos-pro' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Native tables', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php if ( class_exists( 'WP_MCP_AI_Fnb_Cpt_Reader' ) ) : ?>
				<table class="widefat striped" style="max-width: 720px;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Table', 'mcp-ai-wpoos-pro' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Storage', 'mcp-ai-wpoos-pro' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Rows', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( WP_MCP_AI_Fnb_Cpt_Reader::native_tables() as $native_table ) {
							$registered = post_type_exists( $native_table['post_type'] );
							$counts     = $registered ? wp_count_posts( $native_table['post_type'] ) : null;
							$count      = null !== $counts ? (int) $counts->publish : 0;
							$edit_url   = $registered ? admin_url( 'edit.php?post_type=' . $native_table['post_type'] ) : '';
							?>
							<tr>
								<td>
									<?php
									if ( '' !== $edit_url ) {
										echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $native_table['id'] . ' ' . $native_table['post_type'] ) . '</a>';
									} else {
										echo esc_html( $native_table['id'] . ' ' . $native_table['post_type'] );
									}
									?>
								</td>
								<td><?php $registered ? esc_html_e( 'Registered', 'mcp-ai-wpoos-pro' ) : esc_html_e( 'Not registered', 'mcp-ai-wpoos-pro' ); ?></td>
								<td><?php echo esc_html( (string) $count ); ?></td>
							</tr>
							<?php
						}
						?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'Native storage classes are not loaded (toolkit disabled).', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php endif; ?>

			<p style="margin-top: 1em;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-fnb' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Toolkit settings', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
