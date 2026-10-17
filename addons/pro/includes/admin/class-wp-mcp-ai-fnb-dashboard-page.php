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
		<?php self::render_trends(); ?>
		<?php self::render_panels( $latest_month ); ?>
		<?php
	}

	/**
	 * Return the known months present in the operating tables (7.0 daily
	 * covers, 13.0 utilities, 6.0 daily sales), oldest-first, capped to a
	 * trailing window for trend charts.
	 *
	 * @param int $limit Max months to return (default 6).
	 * @return array<int,string> Y-m strings, ascending.
	 */
	private static function known_months( $limit = 6 ) {
		$months = self::available_months();

		return array_slice( $months, max( 0, count( $months ) - max( 1, $limit ) ) );
	}

	/**
	 * Merge the months present in the three month-hourly tables used by the
	 * analytics cards. 13.0 is the fallback when a client exports covers on
	 * the utilities sheet instead of a dedicated daily-covers sheet.
	 *
	 * @return array<int,string> Y-m strings, ascending.
	 */
	private static function available_months() {
		$months = array();
		foreach ( array( '6.0', '7.0', '13.0' ) as $table_id ) {
			$table = WP_MCP_AI_Fnb_Data_Source::get_table( $table_id );
			if ( is_wp_error( $table ) ) {
				continue;
			}
			$headers = $table['header_index'];
			foreach ( $table['rows'] as $row ) {
				$month = (string) WP_MCP_AI_Fnb_Data_Source::cell( $table_id, $headers, $row, 'month' );
				if ( '' === $month ) {
					$month = WP_MCP_AI_Fnb_Data_Source::month_of( WP_MCP_AI_Fnb_Data_Source::cell( $table_id, $headers, $row, 'date' ) );
				}
				if ( preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
					$months[ $month ] = true;
				}
			}
		}

		$months = array_keys( $months );
		sort( $months );

		return $months;
	}

	/**
	 * Build the month-series payload for the four trend charts, computed once
	 * from the metric engine so the values match every other F&B surface.
	 *
	 * @return array|null Trend payload or null when fewer than two months exist.
	 */
	public static function trend_payload() {
		$months = self::known_months( 6 );
		if ( count( $months ) < 2 ) {
			return null;
		}

		$revenue     = array();
		$covers      = array();
		$food_cost   = array();
		$waste_value = array();
		foreach ( $months as $month ) {
			$r         = WP_MCP_AI_Fnb_Metrics::m01_revenue( $month );
			$revenue[] = is_wp_error( $r ) ? 0.0 : round( (float) $r['value'], 2 );

			$c        = WP_MCP_AI_Fnb_Metrics::m02_covers( $month );
			$covers[] = is_wp_error( $c ) ? 0 : (int) round( (float) $c['value'] );

			$f           = WP_MCP_AI_Fnb_Metrics::m04_food_cost_pct( $month );
			$food_cost[] = is_wp_error( $f ) ? 0.0 : round( (float) $f['value'], 4 );

			$w             = WP_MCP_AI_Fnb_Metrics::m18_waste_pct( $month );
			$waste_value[] = is_wp_error( $w ) ? 0.0 : round( (float) $w['waste_value'], 2 );
		}

		return array(
			'labels'     => $months,
			'revenue'    => $revenue,
			'covers'     => $covers,
			'foodCost'   => $food_cost,
			'wasteValue' => $waste_value,
		);
	}

	/**
	 * Build the footer Chart.js initialiser for the trend cards. Emitted via
	 * wp_add_inline_script for the `wp-mcp-ai-chartjs` handle so it always runs
	 * after Chart.js has loaded (never as an inline body script). Reads its data
	 * from the `wpMcpAiFnbTrends` global injected as an inline-before block.
	 *
	 * @return string JavaScript source.
	 */
	public static function trend_init_script() {
		return '(function(){'
			. 'if(typeof Chart==="undefined"||typeof wpMcpAiFnbTrends==="undefined"){return;}'
			. 'var d=wpMcpAiFnbTrends;'
			. 'function line(id,data,color){var el=document.getElementById(id);if(!el){return;}'
			. 'new Chart(el,{type:"line",data:{labels:d.labels,datasets:[{label:"",data:data,fill:false,borderColor:color,tension:0.2}]},'
			. 'options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});}'
			. 'line("fnb-chart-revenue",d.revenue,"#2271b1");'
			. 'line("fnb-chart-covers",d.covers,"#00a32a");'
			. 'line("fnb-chart-food-cost",d.foodCost,"#d63638");'
			. 'line("fnb-chart-waste",d.wasteValue,"#996800");'
			. '})();';
	}

	/**
	 * Render trend chart cards (revenue, covers, food cost %, waste). Chart.js
	 * and the initialiser are enqueued by WP_MCP_AI_Fnb_Admin_Menu so the data
	 * series resolve once and are available in the footer.
	 *
	 * @return void
	 */
	private static function render_trends() {
		if ( null === self::trend_payload() ) {
			return;
		}
		?>
		<h3><?php esc_html_e( 'Trends', 'mcp-ai-wpoos-pro' ); ?></h3>
		<style>
			.fnb-chart-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
			@media (max-width: 782px) { .fnb-chart-grid { grid-template-columns:1fr; } }
			/* Chart.js with maintainAspectRatio:false needs a fixed-height parent,
			   otherwise the responsive resize loop grows the canvas indefinitely. */
			.fnb-chart-wrap { position:relative; height:220px; }
			.fnb-chart-wrap canvas { display:block; width:100% !important; height:100% !important; }
		</style>
		<div class="fnb-chart-grid">
			<div class="fnb-chart-card" style="border:1px solid #c3c4c7; border-radius:4px; padding:12px; background:#fff;">
				<h4 style="margin-top:0;"><?php esc_html_e( 'Revenue', 'mcp-ai-wpoos-pro' ); ?></h4>
				<div class="fnb-chart-wrap"><canvas id="fnb-chart-revenue"></canvas></div>
			</div>
			<div class="fnb-chart-card" style="border:1px solid #c3c4c7; border-radius:4px; padding:12px; background:#fff;">
				<h4 style="margin-top:0;"><?php esc_html_e( 'Covers', 'mcp-ai-wpoos-pro' ); ?></h4>
				<div class="fnb-chart-wrap"><canvas id="fnb-chart-covers"></canvas></div>
			</div>
			<div class="fnb-chart-card" style="border:1px solid #c3c4c7; border-radius:4px; padding:12px; background:#fff;">
				<h4 style="margin-top:0;"><?php esc_html_e( 'Food cost %', 'mcp-ai-wpoos-pro' ); ?></h4>
				<div class="fnb-chart-wrap"><canvas id="fnb-chart-food-cost"></canvas></div>
			</div>
			<div class="fnb-chart-card" style="border:1px solid #c3c4c7; border-radius:4px; padding:12px; background:#fff;">
				<h4 style="margin-top:0;"><?php esc_html_e( 'Waste value', 'mcp-ai-wpoos-pro' ); ?></h4>
				<div class="fnb-chart-wrap"><canvas id="fnb-chart-waste"></canvas></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render panel tables: menu-engineering quadrants and waste-by-reason.
	 *
	 * @param string $latest_month Latest Y-m.
	 * @return void
	 */
	private static function render_panels( $latest_month ) {
		$engineering = WP_MCP_AI_Fnb_Metrics::menu_engineering( $latest_month );
		$rows        = ( is_array( $engineering ) && ! empty( $engineering['rows'] ) ) ? $engineering['rows'] : array();

		$waste     = WP_MCP_AI_Fnb_Metrics::m18_waste_pct( $latest_month );
		$by_reason = ( is_array( $waste ) && isset( $waste['by_reason'] ) && is_array( $waste['by_reason'] ) ) ? $waste['by_reason'] : array();
		?>
		<h3><?php esc_html_e( 'Menu engineering', 'mcp-ai-wpoos-pro' ); ?></h3>
		<table class="widefat striped" style="max-width: 960px;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Item', 'mcp-ai-wpoos-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Margin', 'mcp-ai-wpoos-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Popularity', 'mcp-ai-wpoos-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Quadrant', 'mcp-ai-wpoos-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'mcp-ai-wpoos-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No menu items.', 'mcp-ai-wpoos-pro' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( isset( $row['item'] ) ? $row['item'] : '' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( isset( $row['margin'] ) ? (float) $row['margin'] : 0.0, 2 ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( isset( $row['popularity_share'] ) ? (float) $row['popularity_share'] : 0.0, 2 ) ); ?></td>
					<td><?php echo esc_html( isset( $row['quadrant'] ) ? $row['quadrant'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $row['action'] ) ? $row['action'] : '' ); ?></td>
				</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php if ( ! empty( $by_reason ) ) : ?>
		<h3><?php esc_html_e( 'Waste by reason', 'mcp-ai-wpoos-pro' ); ?></h3>
		<table class="widefat striped" style="max-width: 960px;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Reason', 'mcp-ai-wpoos-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Value', 'mcp-ai-wpoos-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $by_reason as $reason => $value ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $reason ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (float) $value, 2 ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Determine the most recent month present in the operating tables.
	 *
	 * @return string Y-m or empty.
	 */
	private static function latest_month() {
		$months = self::available_months();

		return empty( $months ) ? '' : (string) end( $months );
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
