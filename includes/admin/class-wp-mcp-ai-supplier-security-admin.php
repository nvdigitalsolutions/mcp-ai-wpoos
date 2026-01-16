<?php
/**
 * Supplier Security Admin UI.
 *
 * Admin interface for managing supplier security assessments and monitoring.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplier Security Admin class.
 */
class WP_MCP_AI_Supplier_Security_Admin {
	/**
	 * Initialize admin interface.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add admin menu item.
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'Supplier Security', 'mcp-ai-wpoos' ),
			__( 'Supplier Security', 'mcp-ai-wpoos' ),
			'manage_options',
			'nvoos-pro-dashboard-suppliers',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'nvoos-pro-dashboard_page_nvoos-pro-dashboard-suppliers' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wp-mcp-ai-supplier-security',
			WP_MCP_AI_URL . 'assets/css/supplier-security.css',
			array(),
			WP_MCP_AI_VERSION
		);

		wp_enqueue_script(
			'wp-mcp-ai-supplier-security',
			WP_MCP_AI_URL . 'assets/js/supplier-security.js',
			array( 'jquery', 'wp-api' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-supplier-security',
			'wpMcpAiSupplierSecurity',
			array(
				'restUrl' => rest_url( 'mcp-ai/v1/suppliers' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'strings' => array(
					'confirmDelete' => __( 'Are you sure you want to delete this supplier?', 'mcp-ai-wpoos' ),
					'updateSuccess' => __( 'Supplier updated successfully', 'mcp-ai-wpoos' ),
					'updateError'   => __( 'Failed to update supplier', 'mcp-ai-wpoos' ),
					'deleteSuccess' => __( 'Supplier deleted successfully', 'mcp-ai-wpoos' ),
					'deleteError'   => __( 'Failed to delete supplier', 'mcp-ai-wpoos' ),
				),
			)
		);
	}

	/**
	 * Render admin page.
	 */
	public function render_admin_page() {
		$supplier_security = WP_MCP_AI_Supplier_Security::get_instance();
		$suppliers         = $supplier_security->get_suppliers();
		$stats             = $supplier_security->get_statistics();
		$due_for_review    = $supplier_security->get_suppliers_due_for_review();
		?>
		<div class="wrap wp-mcp-ai-supplier-security">
			<h1>
				<?php esc_html_e( 'Supplier Security Management', 'mcp-ai-wpoos' ); ?>
				<span class="wp-mcp-ai-badge wp-mcp-ai-badge-iso">🛡️ ISO 27001</span>
			</h1>

			<p class="description">
				<?php esc_html_e( 'Manage third-party vendor security assessments, monitoring, and compliance. Implements ISO 27001:2022 controls A.5.19-A.5.22.', 'mcp-ai-wpoos' ); ?>
			</p>

			<!-- Statistics Dashboard -->
			<div class="wp-mcp-ai-stats-grid">
				<div class="wp-mcp-ai-stat-card">
					<div class="wp-mcp-ai-stat-icon">📊</div>
					<div class="wp-mcp-ai-stat-value"><?php echo esc_html( $stats['total'] ); ?></div>
					<div class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Total Suppliers', 'mcp-ai-wpoos' ); ?></div>
				</div>

				<div class="wp-mcp-ai-stat-card">
					<div class="wp-mcp-ai-stat-icon">🔴</div>
					<div class="wp-mcp-ai-stat-value"><?php echo esc_html( $stats['by_category']['critical'] ); ?></div>
					<div class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Critical Suppliers', 'mcp-ai-wpoos' ); ?></div>
				</div>

				<div class="wp-mcp-ai-stat-card">
					<div class="wp-mcp-ai-stat-icon">⚠️</div>
					<div class="wp-mcp-ai-stat-value"><?php echo esc_html( $stats['due_for_review'] ); ?></div>
					<div class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Due for Review', 'mcp-ai-wpoos' ); ?></div>
				</div>

				<div class="wp-mcp-ai-stat-card">
					<div class="wp-mcp-ai-stat-icon">📈</div>
					<div class="wp-mcp-ai-stat-value"><?php echo esc_html( $stats['avg_uptime'] ); ?>%</div>
					<div class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Avg Uptime', 'mcp-ai-wpoos' ); ?></div>
				</div>
			</div>

			<!-- Action Buttons -->
			<div class="wp-mcp-ai-actions">
				<button type="button" class="button button-primary" id="generate-sbom">
					<?php esc_html_e( '📦 Generate SBOM', 'mcp-ai-wpoos' ); ?>
				</button>
				<button type="button" class="button button-secondary" id="scan-dependencies">
					<?php esc_html_e( '🔍 Scan Dependencies', 'mcp-ai-wpoos' ); ?>
				</button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-pro-dashboard-suppliers&tab=add' ) ); ?>" class="button">
					<?php esc_html_e( '➕ Add Supplier', 'mcp-ai-wpoos' ); ?>
				</a>
			</div>

			<?php if ( ! empty( $due_for_review ) ) : ?>
				<!-- Review Alert -->
				<div class="notice notice-warning">
					<p>
						<strong><?php esc_html_e( 'Review Required:', 'mcp-ai-wpoos' ); ?></strong>
						<?php
						printf(
							/* translators: %d: Number of suppliers */
							esc_html( _n( '%d supplier is due for security review.', '%d suppliers are due for security review.', count( $due_for_review ), 'mcp-ai-wpoos' ) ),
							count( $due_for_review )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<!-- Suppliers Table -->
			<table class="wp-list-table widefat fixed striped wp-mcp-ai-suppliers-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Supplier', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Service', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Category', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Risk Level', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Next Review', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Uptime', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $suppliers ) ) : ?>
						<?php foreach ( $suppliers as $supplier ) : ?>
							<tr data-supplier-id="<?php echo esc_attr( $supplier['id'] ); ?>">
								<td>
									<strong><?php echo esc_html( $supplier['name'] ); ?></strong>
									<?php if ( ! empty( $supplier['certifications'] ) ) : ?>
										<br>
										<span class="wp-mcp-ai-certifications">
											<?php echo esc_html( implode( ', ', $supplier['certifications'] ) ); ?>
										</span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $supplier['service'] ); ?></td>
								<td>
									<span class="wp-mcp-ai-category wp-mcp-ai-category-<?php echo esc_attr( $supplier['category'] ); ?>">
										<?php echo esc_html( WP_MCP_AI_Supplier_Security::RISK_CATEGORIES[ $supplier['category'] ] ?? $supplier['category'] ); ?>
									</span>
								</td>
								<td>
									<span class="wp-mcp-ai-risk wp-mcp-ai-risk-<?php echo esc_attr( $supplier['risk_level'] ); ?>">
										<?php echo esc_html( WP_MCP_AI_Supplier_Security::RISK_LEVELS[ $supplier['risk_level'] ] ?? $supplier['risk_level'] ); ?>
									</span>
								</td>
								<td>
									<span class="wp-mcp-ai-status wp-mcp-ai-status-<?php echo esc_attr( $supplier['status'] ); ?>">
										<?php echo esc_html( WP_MCP_AI_Supplier_Security::ASSESSMENT_STATUS[ $supplier['status'] ] ?? $supplier['status'] ); ?>
									</span>
								</td>
								<td>
									<?php
									$next_review = isset( $supplier['next_review'] ) ? $supplier['next_review'] : '';
									$is_overdue  = $next_review && $next_review < current_time( 'Y-m-d' );
									?>
									<span class="<?php echo $is_overdue ? 'wp-mcp-ai-overdue' : ''; ?>">
										<?php echo $next_review ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $next_review ) ) ) : '—'; ?>
									</span>
								</td>
								<td>
									<?php
									$uptime       = isset( $supplier['performance']['uptime_actual'] ) ? $supplier['performance']['uptime_actual'] : 0;
									$uptime_class = $uptime >= 99.9 ? 'good' : ( $uptime >= 99 ? 'warning' : 'critical' );
									?>
									<span class="wp-mcp-ai-uptime wp-mcp-ai-uptime-<?php echo esc_attr( $uptime_class ); ?>">
										<?php echo esc_html( number_format( $uptime, 2 ) ); ?>%
									</span>
								</td>
								<td>
									<button type="button" class="button button-small wp-mcp-ai-view-supplier" data-supplier-id="<?php echo esc_attr( $supplier['id'] ); ?>">
										<?php esc_html_e( 'View', 'mcp-ai-wpoos' ); ?>
									</button>
									<button type="button" class="button button-small wp-mcp-ai-record-incident" data-supplier-id="<?php echo esc_attr( $supplier['id'] ); ?>">
										<?php esc_html_e( 'Incident', 'mcp-ai-wpoos' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr>
							<td colspan="8" style="text-align: center;">
								<?php esc_html_e( 'No suppliers found.', 'mcp-ai-wpoos' ); ?>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Risk Distribution -->
			<div class="wp-mcp-ai-risk-distribution">
				<h2><?php esc_html_e( 'Risk Distribution', 'mcp-ai-wpoos' ); ?></h2>
				<div class="wp-mcp-ai-risk-bars">
					<?php foreach ( $stats['by_risk'] as $risk => $count ) : ?>
						<div class="wp-mcp-ai-risk-bar">
							<div class="wp-mcp-ai-risk-bar-label">
								<?php echo esc_html( WP_MCP_AI_Supplier_Security::RISK_LEVELS[ $risk ] ?? $risk ); ?>
							</div>
							<div class="wp-mcp-ai-risk-bar-container">
								<div class="wp-mcp-ai-risk-bar-fill wp-mcp-ai-risk-<?php echo esc_attr( $risk ); ?>"
									style="width: <?php echo esc_attr( $stats['total'] > 0 ? ( $count / $stats['total'] * 100 ) : 0 ); ?>%;">
								</div>
							</div>
							<div class="wp-mcp-ai-risk-bar-count"><?php echo esc_html( $count ); ?></div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Compliance Controls Reference -->
			<div class="wp-mcp-ai-compliance-reference">
				<h2><?php esc_html_e( 'ISO 27001:2022 Controls Coverage', 'mcp-ai-wpoos' ); ?></h2>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Control', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Implementation', 'mcp-ai-wpoos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><strong>A.5.19</strong></td>
							<td><?php esc_html_e( 'Information Security in Supplier Relationships', 'mcp-ai-wpoos' ); ?></td>
							<td><span class="wp-mcp-ai-status wp-mcp-ai-status-approved">✅ <?php esc_html_e( 'Implemented', 'mcp-ai-wpoos' ); ?></span></td>
						</tr>
						<tr>
							<td><strong>A.5.20</strong></td>
							<td><?php esc_html_e( 'Addressing Information Security Within Supplier Agreements', 'mcp-ai-wpoos' ); ?></td>
							<td><span class="wp-mcp-ai-status wp-mcp-ai-status-approved">✅ <?php esc_html_e( 'Implemented', 'mcp-ai-wpoos' ); ?></span></td>
						</tr>
						<tr>
							<td><strong>A.5.21</strong></td>
							<td><?php esc_html_e( 'Managing Information Security in the ICT Supply Chain', 'mcp-ai-wpoos' ); ?></td>
							<td><span class="wp-mcp-ai-status wp-mcp-ai-status-approved">✅ <?php esc_html_e( 'Implemented', 'mcp-ai-wpoos' ); ?></span></td>
						</tr>
						<tr>
							<td><strong>A.5.22</strong></td>
							<td><?php esc_html_e( 'Monitoring, Review and Change Management of Supplier Services', 'mcp-ai-wpoos' ); ?></td>
							<td><span class="wp-mcp-ai-status wp-mcp-ai-status-approved">✅ <?php esc_html_e( 'Implemented', 'mcp-ai-wpoos' ); ?></span></td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Documentation Links -->
			<div class="wp-mcp-ai-documentation">
				<h3><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos' ); ?></h3>
				<ul>
					<li>
						<a href="<?php echo esc_url( 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs/compliance/iso27001/procedures/Vendor-Security.md' ); ?>" target="_blank">
							<?php esc_html_e( '📄 Vendor Security Assessment Procedure', 'mcp-ai-wpoos' ); ?>
						</a>
					</li>
					<li>
						<a href="<?php echo esc_url( 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs/compliance/iso27001/Statement-of-Applicability.md' ); ?>" target="_blank">
							<?php esc_html_e( '📋 Statement of Applicability', 'mcp-ai-wpoos' ); ?>
						</a>
					</li>
					<li>
						<a href="<?php echo esc_url( rest_url( 'mcp-ai/v1/suppliers' ) ); ?>" target="_blank">
							<?php esc_html_e( '🔌 REST API Documentation', 'mcp-ai-wpoos' ); ?>
						</a>
					</li>
				</ul>
			</div>
		</div>
		<?php
	}
}
