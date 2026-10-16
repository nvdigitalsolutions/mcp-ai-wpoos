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

			<?php self::render_analytics(); ?>
		</div>
		<?php
	}

	/**
	 * Render the analytics KPI + recent-native-rows sections, mirroring the
	 * Medical Vitals dashboard anatomy (latest KPIs with a band status, plus a
	 * recent-readings table) backed by the metric engine and native CPT reads.
	 *
	 * @return void
	 */
	private static function render_analytics() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Metrics' ) ) {
			return; // Metrics engine not loaded (toolkit disabled).
		}

		// Latest month present in the sales table (spec table 7.0 carries months).
		$latest_month = self::latest_month();
		if ( '' === $latest_month ) {
			return;
		}

		$kpis = array(
			'food_cost'     => array(
				'label' => __( 'Food cost %', 'mcp-ai-wpoos-pro' ),
				'prop'  => 'm04_food_cost_pct',
				'band'  => array( 0.28, 0.35 ),
			),
			'beverage_cost' => array(
				'label' => __( 'Beverage cost %', 'mcp-ai-wpoos-pro' ),
				'prop'  => 'm05_beverage_cost_pct',
				'band'  => array( 0.18, 0.24 ),
			),
			'labour'        => array(
				'label' => __( 'Labour %', 'mcp-ai-wpoos-pro' ),
				'prop'  => 'm10_labour_pct',
				'band'  => array( 0.25, 0.35 ),
			),
			'waste'         => array(
				'label' => __( 'Waste value', 'mcp-ai-wpoos-pro' ),
				'prop'  => 'm18_waste_pct',
				'band'  => null,
			),
			'cost_cover'    => array(
				'label' => __( 'Cost per cover', 'mcp-ai-wpoos-pro' ),
				'prop'  => 'm06_cost_per_cover',
				'band'  => null,
			),
		);

		$values = array();
		foreach ( $kpis as $key => $kpi ) {
			$result = call_user_func( array( 'WP_MCP_AI_Fnb_Metrics', $kpi['prop'] ), $latest_month );
			if ( is_wp_error( $result ) ) {
				continue;
			}

			if ( 'm18_waste_pct' === $kpi['prop'] ) {
				$value = isset( $result['waste_value'] ) ? (float) $result['waste_value'] : 0.0;
			} else {
				$value = isset( $result['value'] ) ? (float) $result['value'] : 0.0;
			}

			$status         = self::band_status( $value, $kpi['band'] );
			$values[ $key ] = compact( 'value', 'status' );
		}
		?>
		<hr style="margin: 1.5em 0;">
		<h2><?php esc_html_e( 'Latest analytics', 'mcp-ai-wpoos-pro' ); ?> <small style="font-weight:400">(<?php echo esc_html( $latest_month ); ?>)</small></h2>

		<div class="fnb-kpi-grid" style="display:flex; flex-wrap:wrap; gap:12px; margin: 1em 0;">
			<?php foreach ( $kpis as $key => $kpi ) : ?>
				<?php
				if ( ! isset( $values[ $key ] ) ) {
					continue; }
				?>
				<div class="fnb-kpi" style="flex:1 1 160px; border:1px solid #c3c4c7; border-radius:4px; padding:12px; background:#fff;">
					<div class="fnb-kpi-value" style="font-size:22px; font-weight:600;"><?php echo esc_html( self::format_value( $values[ $key ]['value'] ) ); ?></div>
					<div class="fnb-kpi-label" style="color:#50575e;"><?php echo esc_html( $kpi['label'] ); ?></div>
					<?php if ( '' !== $values[ $key ]['status'] ) : ?>
						<div class="fnb-kpi-status" style="color:#2271b1; font-size:12px;"><?php echo esc_html( $values[ $key ]['status'] ); ?></div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<?php self::render_recent_rows(); ?>
		<?php
	}

	/**
	 * Determine the most recent month present in table 7.0 (daily covers).
	 *
	 * @return string Y-m or empty.
	 */
	private static function latest_month() {
		$table = WP_MCP_AI_Fnb_Data_Source::get_table( '7.0' );
		if ( is_wp_error( $table ) ) {
			return '';
		}
		$months = array();
		foreach ( $table['rows'] as $row ) {
			$month = WP_MCP_AI_Fnb_Data_Source::cell( '7.0', $table['header_index'], $row, 'month' );
			if ( '' !== (string) $month && preg_match( '/^\d{4}-\d{2}$/', (string) $month ) ) {
				$months[] = (string) $month;
			}
		}
		if ( empty( $months ) ) {
			return '';
		}
		rsort( $months );

		return $months[0];
	}

	/**
	 * Classify a value against a [low, high] band.
	 *
	 * @param float      $value Metric value.
	 * @param array|null $band  [low, high] or null (no band).
	 * @return string Human status or empty string.
	 */
	private static function band_status( $value, $band ) {
		if ( null === $band ) {
			return '';
		}
		if ( $value < $band[0] ) {
			return __( 'low', 'mcp-ai-wpoos-pro' );
		}
		if ( $value > $band[1] ) {
			return __( 'high', 'mcp-ai-wpoos-pro' );
		}

		return __( 'normal', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Format a metric value (percentages show a % suffix).
	 *
	 * @param float $value Metric value.
	 * @return string
	 */
	private static function format_value( $value ) {
		return number_format_i18n( $value, 2 );
	}

	/**
	 * Render the most recent native-storage rows (mirrors the vitals
	 * recent-readings table).
	 *
	 * @return void
	 */
	private static function render_recent_rows() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Cpt_Reader' ) ) {
			return;
		}
		$tables = WP_MCP_AI_Fnb_Cpt_Reader::native_tables();
		if ( empty( $tables ) ) {
			return;
		}
		$first     = $tables[0];
		$post_type = $first['post_type'];
		?>
		<h3><?php esc_html_e( 'Recent native-storage rows', 'mcp-ai-wpoos-pro' ); ?></h3>
		<?php if ( ! post_type_exists( $post_type ) ) : ?>
			<p class="description"><?php esc_html_e( 'Native storage is disabled — recent rows are unavailable.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped" style="max-width: 960px;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Title', 'mcp-ai-wpoos-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'mcp-ai-wpoos-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$posts = get_posts(
				array(
					'post_type'        => $post_type,
					'post_status'      => 'publish',
					'numberposts'      => 20,
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);
			if ( empty( $posts ) ) :
				?>
				<tr><td colspan="2"><?php esc_html_e( 'No rows yet.', 'mcp-ai-wpoos-pro' ); ?></td></tr>
				<?php
			else :
				foreach ( $posts as $post ) :
					?>
					<tr>
						<td><?php echo esc_html( $post->post_title ); ?></td>
						<td><?php echo esc_html( get_the_date( 'Y-m-d', $post ) ); ?></td>
					</tr>
					<?php
				endforeach;
			endif;
			?>
			</tbody>
		</table>
		<?php
	}
}
