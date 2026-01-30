<?php
/**
 * Registration Dashboard Page for Regulatory Registration Toolkit.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registration Dashboard Page class.
 */
class WP_MCP_AI_Registration_Dashboard_Page {
	/**
	 * Initialize the class.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 22 );
	}

	/**
	 * Add menu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_reg_product',
			__( 'Registration Dashboard', 'mcp-ai-wpoos-pro' ),
			__( 'Dashboard', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			'wp-mcp-ai-registration-dashboard',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the dashboard page.
	 */
	public static function render_page() {
		// Get registration statistics.
		$stats = self::get_registration_stats();
		?>
		<div class="wrap wp-mcp-ai-registration-dashboard">
			<h1><?php echo esc_html__( 'Registration Dashboard', 'mcp-ai-wpoos-pro' ); ?></h1>
			<p><?php echo esc_html__( 'Track registration status across all countries and products.', 'mcp-ai-wpoos-pro' ); ?></p>

			<!-- Statistics Overview -->
			<div class="dashboard-stats">
				<div class="stat-card">
					<div class="stat-icon dashicons dashicons-products"></div>
					<div class="stat-content">
						<div class="stat-number"><?php echo esc_html( $stats['total_products'] ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Total Products', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>

				<div class="stat-card">
					<div class="stat-icon dashicons dashicons-shield-alt"></div>
					<div class="stat-content">
						<div class="stat-number"><?php echo esc_html( $stats['total_registrations'] ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Total Registrations', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>

				<div class="stat-card status-approved">
					<div class="stat-icon dashicons dashicons-yes-alt"></div>
					<div class="stat-content">
						<div class="stat-number"><?php echo esc_html( $stats['approved'] ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Approved', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>

				<div class="stat-card status-pending">
					<div class="stat-icon dashicons dashicons-clock"></div>
					<div class="stat-content">
						<div class="stat-number"><?php echo esc_html( $stats['under_review'] ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Under Review', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>

				<div class="stat-card status-warning">
					<div class="stat-icon dashicons dashicons-warning"></div>
					<div class="stat-content">
						<div class="stat-number"><?php echo esc_html( $stats['expiring_soon'] ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Expiring Soon', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>

				<div class="stat-card status-draft">
					<div class="stat-icon dashicons dashicons-edit"></div>
					<div class="stat-content">
						<div class="stat-number"><?php echo esc_html( $stats['draft'] ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Draft', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>
			</div>

			<!-- Country Breakdown -->
			<div class="dashboard-section">
				<h2><?php esc_html_e( 'Registrations by Country', 'mcp-ai-wpoos-pro' ); ?></h2>
				<div class="country-breakdown">
					<?php
					$countries = self::get_countries_stats();
					if ( ! empty( $countries ) ) {
						foreach ( $countries as $country ) {
							?>
							<div class="country-card">
								<h3><?php echo esc_html( $country['name'] ); ?></h3>
								<div class="country-stats">
									<div class="country-stat">
										<span class="stat-value"><?php echo esc_html( $country['total'] ); ?></span>
										<span class="stat-label"><?php esc_html_e( 'Total', 'mcp-ai-wpoos-pro' ); ?></span>
									</div>
									<div class="country-stat approved">
										<span class="stat-value"><?php echo esc_html( $country['approved'] ); ?></span>
										<span class="stat-label"><?php esc_html_e( 'Approved', 'mcp-ai-wpoos-pro' ); ?></span>
									</div>
									<div class="country-stat pending">
										<span class="stat-value"><?php echo esc_html( $country['pending'] ); ?></span>
										<span class="stat-label"><?php esc_html_e( 'Pending', 'mcp-ai-wpoos-pro' ); ?></span>
									</div>
								</div>
								<div class="country-authority">
									<small><?php echo esc_html( $country['authority'] ); ?></small>
								</div>
							</div>
							<?php
						}
					} else {
						?>
						<p class="no-data"><?php esc_html_e( 'No country data available. Add countries in Country Requirements settings.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php
					}
					?>
				</div>
			</div>

			<!-- Recent Activity -->
			<div class="dashboard-section">
				<h2><?php esc_html_e( 'Recent Activity', 'mcp-ai-wpoos-pro' ); ?></h2>
				<?php self::render_recent_activity(); ?>
			</div>

			<!-- Quick Actions -->
			<div class="dashboard-section">
				<h2><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h2>
				<div class="quick-actions">
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_reg_product' ) ); ?>" class="button button-primary">
						<span class="dashicons dashicons-plus-alt"></span>
						<?php esc_html_e( 'Add New Product', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_reg_product' ) ); ?>" class="button">
						<span class="dashicons dashicons-list-view"></span>
						<?php esc_html_e( 'View All Products', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_reg_product&page=wp-mcp-ai-reg-migration' ) ); ?>" class="button">
						<span class="dashicons dashicons-upload"></span>
						<?php esc_html_e( 'Import from Excel', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_reg_product&page=wp-mcp-ai-reg-country-config' ) ); ?>" class="button">
						<span class="dashicons dashicons-admin-site-alt3"></span>
						<?php esc_html_e( 'Manage Countries', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</div>
			</div>
		</div>

		<style>
			.wp-mcp-ai-registration-dashboard .dashboard-stats {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
				gap: 20px;
				margin: 30px 0;
			}
			.wp-mcp-ai-registration-dashboard .stat-card {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				padding: 20px;
				display: flex;
				align-items: center;
				gap: 15px;
			}
			.wp-mcp-ai-registration-dashboard .stat-icon {
				font-size: 40px;
				width: 40px;
				height: 40px;
				color: #2271b1;
			}
			.wp-mcp-ai-registration-dashboard .stat-number {
				font-size: 32px;
				font-weight: bold;
				color: #1d2327;
			}
			.wp-mcp-ai-registration-dashboard .stat-label {
				font-size: 14px;
				color: #646970;
			}
			.wp-mcp-ai-registration-dashboard .stat-card.status-approved .stat-icon {
				color: #00a32a;
			}
			.wp-mcp-ai-registration-dashboard .stat-card.status-pending .stat-icon {
				color: #dba617;
			}
			.wp-mcp-ai-registration-dashboard .stat-card.status-warning .stat-icon {
				color: #d63638;
			}
			.wp-mcp-ai-registration-dashboard .stat-card.status-draft .stat-icon {
				color: #646970;
			}
			.wp-mcp-ai-registration-dashboard .dashboard-section {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				padding: 20px;
				margin: 20px 0;
			}
			.wp-mcp-ai-registration-dashboard .dashboard-section h2 {
				margin-top: 0;
			}
			.wp-mcp-ai-registration-dashboard .country-breakdown {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
				gap: 20px;
			}
			.wp-mcp-ai-registration-dashboard .country-card {
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				padding: 15px;
			}
			.wp-mcp-ai-registration-dashboard .country-card h3 {
				margin: 0 0 10px 0;
				font-size: 16px;
			}
			.wp-mcp-ai-registration-dashboard .country-stats {
				display: flex;
				gap: 15px;
				margin: 15px 0;
			}
			.wp-mcp-ai-registration-dashboard .country-stat {
				text-align: center;
			}
			.wp-mcp-ai-registration-dashboard .country-stat .stat-value {
				display: block;
				font-size: 24px;
				font-weight: bold;
				color: #1d2327;
			}
			.wp-mcp-ai-registration-dashboard .country-stat.approved .stat-value {
				color: #00a32a;
			}
			.wp-mcp-ai-registration-dashboard .country-stat.pending .stat-value {
				color: #dba617;
			}
			.wp-mcp-ai-registration-dashboard .country-stat .stat-label {
				display: block;
				font-size: 12px;
				color: #646970;
			}
			.wp-mcp-ai-registration-dashboard .country-authority {
				margin-top: 10px;
				padding-top: 10px;
				border-top: 1px solid #f0f0f1;
			}
			.wp-mcp-ai-registration-dashboard .no-data {
				color: #646970;
				font-style: italic;
			}
			.wp-mcp-ai-registration-dashboard .quick-actions {
				display: flex;
				gap: 10px;
				flex-wrap: wrap;
			}
			.wp-mcp-ai-registration-dashboard .quick-actions .button .dashicons {
				vertical-align: middle;
				margin-right: 5px;
			}
			.wp-mcp-ai-registration-dashboard .recent-activity-list {
				list-style: none;
				margin: 0;
				padding: 0;
			}
			.wp-mcp-ai-registration-dashboard .activity-item {
				padding: 10px;
				border-bottom: 1px solid #f0f0f1;
			}
			.wp-mcp-ai-registration-dashboard .activity-item:last-child {
				border-bottom: none;
			}
			.wp-mcp-ai-registration-dashboard .activity-time {
				color: #646970;
				font-size: 12px;
			}
		</style>
		<?php
	}

	/**
	 * Get registration statistics.
	 *
	 * @return array Statistics data.
	 */
	private static function get_registration_stats() {
		$stats = array(
			'total_products'      => 0,
			'total_registrations' => 0,
			'approved'            => 0,
			'under_review'        => 0,
			'draft'               => 0,
			'expiring_soon'       => 0,
		);

		// Count products.
		$products_query          = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_reg_product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$stats['total_products'] = $products_query->found_posts;
		wp_reset_postdata();

		// Count registrations.
		$registrations_query          = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_registration',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$stats['total_registrations'] = $registrations_query->found_posts;
		wp_reset_postdata();

		// Count by status (using taxonomy if available).
		$approved_term = get_term_by( 'name', 'Approved', 'mcp_ai_reg_status' );
		if ( $approved_term ) {
			$approved_query    = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_registration',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'tax_query'      => array(
						array(
							'taxonomy' => 'mcp_ai_reg_status',
							'field'    => 'term_id',
							'terms'    => $approved_term->term_id,
						),
					),
				)
			);
			$stats['approved'] = $approved_query->found_posts;
			wp_reset_postdata();
		}

		$review_term = get_term_by( 'name', 'Under Review', 'mcp_ai_reg_status' );
		if ( $review_term ) {
			$review_query          = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_registration',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'tax_query'      => array(
						array(
							'taxonomy' => 'mcp_ai_reg_status',
							'field'    => 'term_id',
							'terms'    => $review_term->term_id,
						),
					),
				)
			);
			$stats['under_review'] = $review_query->found_posts;
			wp_reset_postdata();
		}

		$draft_term = get_term_by( 'name', 'Draft', 'mcp_ai_reg_status' );
		if ( $draft_term ) {
			$draft_query    = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_registration',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'tax_query'      => array(
						array(
							'taxonomy' => 'mcp_ai_reg_status',
							'field'    => 'term_id',
							'terms'    => $draft_term->term_id,
						),
					),
				)
			);
			$stats['draft'] = $draft_query->found_posts;
			wp_reset_postdata();
		}

		// Count expiring soon (within 30 days).
		$expiring_query         = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_registration',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => 'expiry_date',
						'value'   => array(
							current_time( 'Y-m-d' ),
							gmdate( 'Y-m-d', strtotime( '+30 days', current_time( 'timestamp' ) ) ),
						),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
				),
			)
		);
		$stats['expiring_soon'] = $expiring_query->found_posts;
		wp_reset_postdata();

		return $stats;
	}

	/**
	 * Get countries statistics.
	 *
	 * @return array Countries data.
	 */
	private static function get_countries_stats() {
		$countries = array();

		$countries_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_reg_country',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		if ( ! $countries_query->have_posts() ) {
			wp_reset_postdata();
			return $countries;
		}

		// Get all country IDs first.
		$country_ids = array();
		while ( $countries_query->have_posts() ) {
			$countries_query->the_post();
			$country_ids[] = get_the_ID();
		}
		wp_reset_postdata();

		// Fetch all registration counts in a single query grouped by country_id.
		global $wpdb;
		$country_ids_safe    = array_map( 'intval', $country_ids );
		$placeholders        = implode( ',', array_fill( 0, count( $country_ids_safe ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$query               = "SELECT pm.meta_value as country_id, COUNT(*) as total
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'mcp_ai_registration'
			AND pm.meta_key = 'country_id'
			AND pm.meta_value IN ($placeholders)
			GROUP BY pm.meta_value";
		$registration_counts = $wpdb->get_results(
			$wpdb->prepare( $query, $country_ids_safe ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// Convert to associative array for quick lookup.
		$counts_by_country = array();
		foreach ( $registration_counts as $row ) {
			$counts_by_country[ $row['country_id'] ] = (int) $row['total'];
		}

		// Get approved term once.
		$approved_term              = get_term_by( 'name', 'Approved', 'mcp_ai_reg_status' );
		$approved_counts_by_country = array();

		if ( $approved_term ) {
			// Fetch approved registration counts in a single query.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$approved_query  = "SELECT pm.meta_value as country_id, COUNT(*) as total
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				WHERE p.post_type = 'mcp_ai_registration'
				AND pm.meta_key = 'country_id'
				AND pm.meta_value IN ($placeholders)
				AND tr.term_taxonomy_id = %d
				GROUP BY pm.meta_value";
			$approved_counts = $wpdb->get_results(
				$wpdb->prepare( $approved_query, array_merge( $country_ids_safe, array( $approved_term->term_taxonomy_id ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			foreach ( $approved_counts as $row ) {
				$approved_counts_by_country[ $row['country_id'] ] = (int) $row['total'];
			}
		}

		// Now build the countries array with the fetched data.
		$countries_query->rewind_posts();
		while ( $countries_query->have_posts() ) {
			$countries_query->the_post();
			$country_id = get_the_ID();

			$total    = isset( $counts_by_country[ $country_id ] ) ? $counts_by_country[ $country_id ] : 0;
			$approved = isset( $approved_counts_by_country[ $country_id ] ) ? $approved_counts_by_country[ $country_id ] : 0;

			$countries[] = array(
				'name'      => get_the_title(),
				'authority' => get_post_meta( $country_id, 'regulatory_authority', true ),
				'total'     => $total,
				'approved'  => $approved,
				'pending'   => $total - $approved,
			);
		}
		wp_reset_postdata();

		return $countries;
	}

	/**
	 * Render recent activity.
	 */
	private static function render_recent_activity() {
		$recent_posts = new WP_Query(
			array(
				'post_type'      => array( 'mcp_ai_reg_product', 'mcp_ai_registration', 'mcp_ai_reg_document' ),
				'post_status'    => 'any',
				'posts_per_page' => 10,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		if ( $recent_posts->have_posts() ) {
			?>
			<ul class="recent-activity-list">
				<?php
				while ( $recent_posts->have_posts() ) {
					$recent_posts->the_post();
					$post_type_object = get_post_type_object( get_post_type() );
					?>
					<li class="activity-item">
						<strong><?php the_title(); ?></strong>
						<span class="activity-type"> - <?php echo esc_html( $post_type_object->labels->singular_name ); ?></span>
						<div class="activity-time">
							<?php
							/* translators: %s: Time elapsed since the post was modified (e.g., "2 hours", "3 days") */
							echo esc_html( sprintf( __( 'Modified %s ago', 'mcp-ai-wpoos-pro' ), human_time_diff( get_the_modified_time( 'U' ), current_time( 'timestamp' ) ) ) );
							?>
						</div>
					</li>
					<?php
				}
				?>
			</ul>
			<?php
			wp_reset_postdata();
		} else {
			?>
			<p class="no-data"><?php esc_html_e( 'No recent activity.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php
		}
	}
}

WP_MCP_AI_Registration_Dashboard_Page::init();
