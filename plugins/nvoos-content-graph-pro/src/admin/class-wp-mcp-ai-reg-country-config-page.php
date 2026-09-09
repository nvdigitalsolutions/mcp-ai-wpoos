<?php
/**
 * class-wp-mcp-ai-reg-country-config-page.php (ecosystem port — Wave F2, regulatory-registration admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/class-wp-mcp-ai-reg-country-config-page.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps (the base-owned `WP_MCP_AI_URL`/`WP_MCP_AI_VERSION`
 * enqueue refs and the `class_exists`-guarded shortcode/tool seams stay byte-identical); the
 * `__DIR__` trait requires and the `cpt-settings-page-base` require resolve from the addon's
 * already-ported `src/admin/` copies.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Country Configuration Page class.
 */
class WP_MCP_AI_Reg_Country_Config_Page {
	/**
	 * Initialize the class.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 24 );
	}

	/**
	 * Add menu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_reg_product',
			__( 'Country Requirements', 'nvoos-content-graph-pro' ),
			__( 'Country Requirements', 'nvoos-content-graph-pro' ),
			'manage_options',
			'wp-mcp-ai-reg-country-config',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the country config page.
	 */
	public static function render_page() {
		// Get all countries.
		$countries = self::get_countries();
		?>
		<div class="wrap wp-mcp-ai-country-config">
			<h1><?php echo esc_html__( 'Country Requirements Configuration', 'nvoos-content-graph-pro' ); ?></h1>
			<p><?php echo esc_html__( 'Configure regulatory requirements for different countries and authorities.', 'nvoos-content-graph-pro' ); ?></p>

			<div class="country-config-section">
				<h2><?php esc_html_e( 'Supported Countries', 'nvoos-content-graph-pro' ); ?></h2>
				<p><?php esc_html_e( 'Manage countries and their regulatory authorities for product registration tracking.', 'nvoos-content-graph-pro' ); ?></p>

				<?php if ( ! empty( $countries ) ) : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Country', 'nvoos-content-graph-pro' ); ?></th>
								<th><?php esc_html_e( 'Code', 'nvoos-content-graph-pro' ); ?></th>
								<th><?php esc_html_e( 'Regulatory Authority', 'nvoos-content-graph-pro' ); ?></th>
								<th><?php esc_html_e( 'Registrations', 'nvoos-content-graph-pro' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'nvoos-content-graph-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $countries as $country ) : ?>
								<tr>
									<td>
										<strong><?php echo esc_html( $country['name'] ); ?></strong>
									</td>
									<td>
										<span class="country-code"><?php echo esc_html( $country['code'] ); ?></span>
									</td>
									<td>
										<?php echo esc_html( $country['authority'] ); ?>
									</td>
									<td>
										<?php echo esc_html( $country['reg_count'] ); ?>
									</td>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $country['id'] ) ); ?>" class="button button-small" title="<?php esc_attr_e( 'Edit', 'nvoos-content-graph-pro' ); ?>">
											<span class="dashicons dashicons-edit" aria-hidden="true"></span>
											<span class="screen-reader-text"><?php esc_html_e( 'Edit', 'nvoos-content-graph-pro' ); ?></span>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'No countries configured yet.', 'nvoos-content-graph-pro' ); ?></p>
					</div>
				<?php endif; ?>

				<p>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_reg_country' ) ); ?>" class="button button-primary">
						<span class="dashicons dashicons-plus-alt"></span>
						<?php esc_html_e( 'Add New Country', 'nvoos-content-graph-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_reg_country' ) ); ?>" class="button">
						<?php esc_html_e( 'View All Countries', 'nvoos-content-graph-pro' ); ?>
					</a>
				</p>
			</div>

			<div class="country-config-section">
				<h2><?php esc_html_e( 'Pre-configured Countries', 'nvoos-content-graph-pro' ); ?></h2>
				<p><?php esc_html_e( 'The following countries are pre-configured with default regulatory requirements:', 'nvoos-content-graph-pro' ); ?></p>

				<div class="preconfigured-countries">
					<div class="country-info-card">
						<h3><span class="country-flag">🇱🇰</span> Sri Lanka</h3>
						<p><strong><?php esc_html_e( 'Authority:', 'nvoos-content-graph-pro' ); ?></strong> NMRA (National Medicines Regulatory Authority)</p>
						<ul>
							<li><?php esc_html_e( 'Registration Certificate required', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'Certificate of Analysis (CoA)', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'INCI ingredient list', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'Product artwork approval', 'nvoos-content-graph-pro' ); ?></li>
						</ul>
					</div>

					<div class="country-info-card">
						<h3><span class="country-flag">🇦🇪</span> United Arab Emirates</h3>
						<p><strong><?php esc_html_e( 'Authority:', 'nvoos-content-graph-pro' ); ?></strong> MOHAP / Dubai Municipality</p>
						<ul>
							<li><?php esc_html_e( 'Product registration certificate', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'GMP certificate', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'Certificate of Free Sale', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'MSDS (Material Safety Data Sheet)', 'nvoos-content-graph-pro' ); ?></li>
						</ul>
					</div>

					<div class="country-info-card">
						<h3><span class="country-flag">🇸🇦</span> Saudi Arabia</h3>
						<p><strong><?php esc_html_e( 'Authority:', 'nvoos-content-graph-pro' ); ?></strong> SFDA (Saudi Food and Drug Authority)</p>
						<ul>
							<li><?php esc_html_e( 'SFDA product registration', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'Certificate of Free Sale', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'Product formula certificate', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'Authorized distributor letter', 'nvoos-content-graph-pro' ); ?></li>
						</ul>
					</div>

					<div class="country-info-card">
						<h3><span class="country-flag">🇶🇦</span> Qatar</h3>
						<p><strong><?php esc_html_e( 'Authority:', 'nvoos-content-graph-pro' ); ?></strong> Ministry of Public Health</p>
						<ul>
							<li><?php esc_html_e( 'Product registration certificate', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'Certificate of Origin', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'GMP certificate', 'nvoos-content-graph-pro' ); ?></li>
						</ul>
					</div>

					<div class="country-info-card">
						<h3><span class="country-flag">🇰🇼</span> Kuwait</h3>
						<p><strong><?php esc_html_e( 'Authority:', 'nvoos-content-graph-pro' ); ?></strong> Ministry of Health</p>
						<ul>
							<li><?php esc_html_e( 'Product registration approval', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'Certificate of Free Sale', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'Product specifications', 'nvoos-content-graph-pro' ); ?></li>
						</ul>
					</div>

					<div class="country-info-card">
						<h3><span class="country-flag">🇴🇲</span> Oman</h3>
						<p><strong><?php esc_html_e( 'Authority:', 'nvoos-content-graph-pro' ); ?></strong> Ministry of Health</p>
						<ul>
							<li><?php esc_html_e( 'Product registration', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'Certificate of Analysis', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'Manufacturing license', 'nvoos-content-graph-pro' ); ?></li>
						</ul>
					</div>

					<div class="country-info-card">
						<h3><span class="country-flag">🇮🇳</span> India</h3>
						<p><strong><?php esc_html_e( 'Authority:', 'nvoos-content-graph-pro' ); ?></strong> CDSCO (Central Drugs Standard Control Organisation)</p>
						<ul>
							<li><?php esc_html_e( 'Import license', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'Product registration certificate', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'Certificate of Free Sale', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'GMP certificate from manufacturer', 'nvoos-content-graph-pro' ); ?></li>
						</ul>
					</div>
				</div>
			</div>

			<div class="country-config-section">
				<h2><?php esc_html_e( 'Document Requirements', 'nvoos-content-graph-pro' ); ?></h2>
				<p><?php esc_html_e( 'Common document types required across countries:', 'nvoos-content-graph-pro' ); ?></p>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Document Type', 'nvoos-content-graph-pro' ); ?></th>
							<th><?php esc_html_e( 'Description', 'nvoos-content-graph-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$doc_types = self::get_document_types();
						foreach ( $doc_types as $doc_type ) :
							?>
							<tr>
								<td><strong><?php echo esc_html( $doc_type['name'] ); ?></strong></td>
								<td><?php echo esc_html( $doc_type['description'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=mcp_ai_doc_type&post_type=mcp_ai_reg_document' ) ); ?>" class="button">
						<?php esc_html_e( 'Manage Document Types', 'nvoos-content-graph-pro' ); ?>
					</a>
				</p>
			</div>
		</div>

		<style>
			.wp-mcp-ai-country-config .country-config-section {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				padding: 20px;
				margin: 20px 0;
			}
			.wp-mcp-ai-country-config .country-config-section h2 {
				margin-top: 0;
			}
			.wp-mcp-ai-country-config .country-code {
				display: inline-block;
				background: #f0f0f1;
				padding: 2px 8px;
				border-radius: 3px;
				font-family: monospace;
				font-weight: bold;
			}
			.wp-mcp-ai-country-config .button .dashicons {
				vertical-align: middle;
				margin-right: 5px;
			}
			.wp-mcp-ai-country-config .preconfigured-countries {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
				gap: 20px;
				margin: 20px 0;
			}
			.wp-mcp-ai-country-config .country-info-card {
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				padding: 15px;
				background: #f9f9f9;
			}
			.wp-mcp-ai-country-config .country-info-card h3 {
				margin-top: 0;
				color: #1d2327;
			}
			.wp-mcp-ai-country-config .country-flag {
				font-size: 24px;
				margin-right: 8px;
			}
			.wp-mcp-ai-country-config .country-info-card ul {
				list-style: disc;
				margin-left: 20px;
			}
			.wp-mcp-ai-country-config .country-info-card ul li {
				margin: 5px 0;
			}
		</style>
		<?php
	}

	/**
	 * Get all countries with registration counts.
	 *
	 * @return array Countries data.
	 */
	private static function get_countries() {
		$countries = array();

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_reg_country',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( ! $query->have_posts() ) {
			wp_reset_postdata();
			return $countries;
		}

		// Get all country IDs first.
		$country_ids = array();
		while ( $query->have_posts() ) {
			$query->the_post();
			$country_ids[] = get_the_ID();
		}
		wp_reset_postdata();

		// Fetch all registration counts in a single query grouped by country_id.
		global $wpdb;
		if ( ! empty( $country_ids ) ) {
			$placeholders        = implode( ',', array_fill( 0, count( $country_ids ), '%d' ) );
			$registration_counts = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- result of $wpdb->prepare() below.
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders built from array_fill with %d only.
					"SELECT pm.meta_value as country_id, COUNT(*) as total
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					WHERE p.post_type = 'mcp_ai_registration'
					AND pm.meta_key = 'country_id'
					AND pm.meta_value IN ($placeholders)
					GROUP BY pm.meta_value",
					// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					...$country_ids
				),
				ARRAY_A
			);
		} else {
			$registration_counts = array();
		}

		// Convert to associative array for quick lookup.
		$counts_by_country = array();
		foreach ( $registration_counts as $row ) {
			$counts_by_country[ $row['country_id'] ] = (int) $row['total'];
		}

		// Build countries array with fetched data.
		$query->rewind_posts();
		while ( $query->have_posts() ) {
			$query->the_post();
			$country_id = get_the_ID();

			$countries[] = array(
				'id'        => $country_id,
				'name'      => get_the_title(),
				'code'      => get_post_meta( $country_id, 'country_code', true ),
				'authority' => get_post_meta( $country_id, 'regulatory_authority', true ),
				'reg_count' => isset( $counts_by_country[ $country_id ] ) ? $counts_by_country[ $country_id ] : 0,
			);
		}
		wp_reset_postdata();

		return $countries;
	}

	/**
	 * Get common document types.
	 *
	 * @return array Document types.
	 */
	private static function get_document_types() {
		return array(
			array(
				'name'        => 'LOA',
				'description' => __( 'Letter of Authorization from manufacturer', 'nvoos-content-graph-pro' ),
			),
			array(
				'name'        => 'Certificate of Analysis',
				'description' => __( 'Laboratory analysis certificate for product composition', 'nvoos-content-graph-pro' ),
			),
			array(
				'name'        => 'Certificate of Free Sale',
				'description' => __( 'Document confirming product is legally sold in country of origin', 'nvoos-content-graph-pro' ),
			),
			array(
				'name'        => 'GMP Certificate',
				'description' => __( 'Good Manufacturing Practice certification', 'nvoos-content-graph-pro' ),
			),
			array(
				'name'        => 'MSDS',
				'description' => __( 'Material Safety Data Sheet for product safety information', 'nvoos-content-graph-pro' ),
			),
			array(
				'name'        => 'Product Artwork',
				'description' => __( 'Product labeling and packaging artwork for regulatory approval', 'nvoos-content-graph-pro' ),
			),
			array(
				'name'        => 'INCI List',
				'description' => __( 'International Nomenclature Cosmetic Ingredient list', 'nvoos-content-graph-pro' ),
			),
			array(
				'name'        => 'Formula Certificate',
				'description' => __( 'Product formula certification document', 'nvoos-content-graph-pro' ),
			),
			array(
				'name'        => 'ISO Certificate',
				'description' => __( 'ISO quality management certification', 'nvoos-content-graph-pro' ),
			),
			array(
				'name'        => 'Payment Receipt',
				'description' => __( 'Proof of payment for registration fees', 'nvoos-content-graph-pro' ),
			),
		);
	}
}

WP_MCP_AI_Reg_Country_Config_Page::init();
