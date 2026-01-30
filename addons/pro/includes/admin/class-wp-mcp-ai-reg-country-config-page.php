<?php
/**
 * Country Configuration Page for Regulatory Registration Toolkit.
 *
 * @package WP_MCP_AI
 */

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
			__( 'Country Requirements', 'mcp-ai-wpoos-pro' ),
			__( 'Country Requirements', 'mcp-ai-wpoos-pro' ),
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
			<h1><?php echo esc_html__( 'Country Requirements Configuration', 'mcp-ai-wpoos-pro' ); ?></h1>
			<p><?php echo esc_html__( 'Configure regulatory requirements for different countries and authorities.', 'mcp-ai-wpoos-pro' ); ?></p>

			<div class="country-config-section">
				<h2><?php esc_html_e( 'Supported Countries', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p><?php esc_html_e( 'Manage countries and their regulatory authorities for product registration tracking.', 'mcp-ai-wpoos-pro' ); ?></p>

				<?php if ( ! empty( $countries ) ) : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Country', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Code', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Regulatory Authority', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Registrations', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
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
										<a href="<?php echo esc_url( get_edit_post_link( $country['id'] ) ); ?>" class="button button-small">
											<?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'No countries configured yet.', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
				<?php endif; ?>

				<p>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_reg_country' ) ); ?>" class="button button-primary">
						<span class="dashicons dashicons-plus-alt"></span>
						<?php esc_html_e( 'Add New Country', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_reg_country' ) ); ?>" class="button">
						<?php esc_html_e( 'View All Countries', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>

			<div class="country-config-section">
				<h2><?php esc_html_e( 'Pre-configured Countries', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p><?php esc_html_e( 'The following countries are pre-configured with default regulatory requirements:', 'mcp-ai-wpoos-pro' ); ?></p>

				<div class="preconfigured-countries">
					<div class="country-info-card">
						<h3><span class="country-flag">🇱🇰</span> Sri Lanka</h3>
						<p><strong><?php esc_html_e( 'Authority:', 'mcp-ai-wpoos-pro' ); ?></strong> NMRA (National Medicines Regulatory Authority)</p>
						<ul>
							<li><?php esc_html_e( 'Registration Certificate required', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Certificate of Analysis (CoA)', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'INCI ingredient list', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Product artwork approval', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="country-info-card">
						<h3><span class="country-flag">🇦🇪</span> United Arab Emirates</h3>
						<p><strong><?php esc_html_e( 'Authority:', 'mcp-ai-wpoos-pro' ); ?></strong> MOHAP / Dubai Municipality</p>
						<ul>
							<li><?php esc_html_e( 'Product registration certificate', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'GMP certificate', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Certificate of Free Sale', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'MSDS (Material Safety Data Sheet)', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="country-info-card">
						<h3><span class="country-flag">🇸🇦</span> Saudi Arabia</h3>
						<p><strong><?php esc_html_e( 'Authority:', 'mcp-ai-wpoos-pro' ); ?></strong> SFDA (Saudi Food and Drug Authority)</p>
						<ul>
							<li><?php esc_html_e( 'SFDA product registration', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Certificate of Free Sale', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Product formula certificate', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Authorized distributor letter', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="country-info-card">
						<h3><span class="country-flag">🇶🇦</span> Qatar</h3>
						<p><strong><?php esc_html_e( 'Authority:', 'mcp-ai-wpoos-pro' ); ?></strong> Ministry of Public Health</p>
						<ul>
							<li><?php esc_html_e( 'Product registration certificate', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Certificate of Origin', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'GMP certificate', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="country-info-card">
						<h3><span class="country-flag">🇰🇼</span> Kuwait</h3>
						<p><strong><?php esc_html_e( 'Authority:', 'mcp-ai-wpoos-pro' ); ?></strong> Ministry of Health</p>
						<ul>
							<li><?php esc_html_e( 'Product registration approval', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Certificate of Free Sale', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Product specifications', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="country-info-card">
						<h3><span class="country-flag">🇴🇲</span> Oman</h3>
						<p><strong><?php esc_html_e( 'Authority:', 'mcp-ai-wpoos-pro' ); ?></strong> Ministry of Health</p>
						<ul>
							<li><?php esc_html_e( 'Product registration', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Certificate of Analysis', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Manufacturing license', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="country-info-card">
						<h3><span class="country-flag">🇮🇳</span> India</h3>
						<p><strong><?php esc_html_e( 'Authority:', 'mcp-ai-wpoos-pro' ); ?></strong> CDSCO (Central Drugs Standard Control Organisation)</p>
						<ul>
							<li><?php esc_html_e( 'Import license', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Product registration certificate', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Certificate of Free Sale', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'GMP certificate from manufacturer', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>
				</div>
			</div>

			<div class="country-config-section">
				<h2><?php esc_html_e( 'Document Requirements', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p><?php esc_html_e( 'Common document types required across countries:', 'mcp-ai-wpoos-pro' ); ?></p>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Document Type', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></th>
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
						<?php esc_html_e( 'Manage Document Types', 'mcp-ai-wpoos-pro' ); ?>
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
		$registration_counts = $wpdb->get_results(
			"SELECT pm.meta_value as country_id, COUNT(*) as total
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'mcp_ai_registration'
			AND pm.meta_key = 'country_id'
			AND pm.meta_value IN (" . implode( ',', array_map( 'intval', $country_ids ) ) . ')
			GROUP BY pm.meta_value',
			ARRAY_A
		);

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
				'description' => __( 'Letter of Authorization from manufacturer', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'name'        => 'Certificate of Analysis',
				'description' => __( 'Laboratory analysis certificate for product composition', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'name'        => 'Certificate of Free Sale',
				'description' => __( 'Document confirming product is legally sold in country of origin', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'name'        => 'GMP Certificate',
				'description' => __( 'Good Manufacturing Practice certification', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'name'        => 'MSDS',
				'description' => __( 'Material Safety Data Sheet for product safety information', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'name'        => 'Product Artwork',
				'description' => __( 'Product labeling and packaging artwork for regulatory approval', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'name'        => 'INCI List',
				'description' => __( 'International Nomenclature Cosmetic Ingredient list', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'name'        => 'Formula Certificate',
				'description' => __( 'Product formula certification document', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'name'        => 'ISO Certificate',
				'description' => __( 'ISO quality management certification', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'name'        => 'Payment Receipt',
				'description' => __( 'Proof of payment for registration fees', 'mcp-ai-wpoos-pro' ),
			),
		);
	}
}

WP_MCP_AI_Reg_Country_Config_Page::init();
