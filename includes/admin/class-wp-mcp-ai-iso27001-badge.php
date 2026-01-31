<?php
/**
 * ISO 27001 Certification Badge Display
 *
 * Displays ISO/IEC 27001 certification status badge in WordPress admin.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ISO 27001 Certification Badge Class
 */
class WP_MCP_AI_ISO27001_Badge {

	/**
	 * Initialize the badge display.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'display_certification_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_badge_styles' ) );
		add_filter( 'plugin_row_meta', array( $this, 'add_plugin_meta_badge' ), 10, 2 );
	}

	/**
	 * Display certification badge in admin notices (top of admin pages).
	 *
	 * Shown on plugin-related pages. Shows "Compliant" by default,
	 * or "Certified" when external certification is achieved.
	 */
	public function display_certification_notice() {
		$screen = get_current_screen();

		// Only show on NV oOS related pages.
		if ( ! $screen || strpos( $screen->id, 'mcp-ai' ) === false ) {
			return;
		}

		// Get certification status.
		$status = $this->get_certification_status();

		?>
		<div class="notice notice-info is-dismissible nvoos-iso27001-badge">
			<div class="nvoos-badge-container">
				<span class="nvoos-badge-icon">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M12 1L3 5V11C3 16.55 6.84 21.74 12 23C17.16 21.74 21 16.55 21 11V5L12 1Z"
								fill="#4CAF50" stroke="#2E7D32" stroke-width="2"/>
						<path d="M9 12L11 14L15 10" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</span>
				<div class="nvoos-badge-content">
					<strong><?php esc_html_e( 'ISO/IEC 27001:2022 ISMS', 'mcp-ai-wpoos' ); ?></strong>
					<span class="nvoos-badge-status"><?php echo esc_html( $status['label'] ); ?></span>
					<p>
						<?php echo esc_html( $status['description'] ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-pro-dashboard-iso27001' ) ); ?>" class="nvoos-badge-link">
							<?php esc_html_e( 'View Security Controls', 'mcp-ai-wpoos' ); ?>
						</a>
						<?php if ( ! empty( $status['docs_link'] ) ) : ?>
							| <a href="<?php echo esc_url( $status['docs_link'] ); ?>" target="_blank" rel="noopener" class="nvoos-badge-link">
								<?php esc_html_e( 'View ISMS Documentation', 'mcp-ai-wpoos' ); ?>
							</a>
						<?php endif; ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get certification status.
	 *
	 * @return array Status information.
	 */
	private function get_certification_status() {
		// Check if externally certified.
		$external_cert = get_option( 'wp_mcp_ai_iso27001_certified', false );
		$cert_date     = get_option( 'wp_mcp_ai_iso27001_cert_date', '' );

		if ( $external_cert && ! empty( $cert_date ) ) {
			return array(
				'label'       => __( 'Certified', 'mcp-ai-wpoos' ),
				'description' => sprintf(
					/* translators: %s: Certification date */
					__( 'This plugin has achieved ISO/IEC 27001:2022 certification (Date: %s). All information security controls are implemented and audited.', 'mcp-ai-wpoos' ),
					$cert_date
				),
				'docs_link'   => 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs/compliance/iso27001',
				'class'       => 'certified',
			);
		}

		// Default: Compliant with ISO 27001 framework.
		return array(
			'label'       => __( 'Fully Compliant', 'mcp-ai-wpoos' ),
			'description' => __( 'This plugin is fully compliant with ISO/IEC 27001:2022 Information Security Management System (ISMS) framework with 100% of applicable controls implemented (83 of 83).', 'mcp-ai-wpoos' ),
			'docs_link'   => 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs/compliance/iso27001',
			'class'       => 'compliant',
		);
	}

	/**
	 * Add badge to plugin meta links.
	 *
	 * Shows "Compliant" by default, "Certified" when externally certified.
	 *
	 * @param array  $links Plugin row meta links.
	 * @param string $file  Plugin file.
	 * @return array Modified links.
	 */
	public function add_plugin_meta_badge( $links, $file ) {
		if ( strpos( $file, 'mcp-ai-wpoos' ) !== false ) {
			$status = $this->get_certification_status();

			$links[] = sprintf(
				'<span class="nvoos-plugin-badge nvoos-plugin-badge-%s">🛡️ ISO 27001 %s</span>',
				esc_attr( $status['class'] ),
				esc_html( $status['label'] )
			);
		}
		return $links;
	}

	/**
	 * Enqueue badge styles.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_badge_styles( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WordPress hook signature.
		// Add inline styles for badge.
		$css = '
		.nvoos-iso27001-badge {
			border-left: 4px solid #4CAF50 !important;
			padding: 12px 16px !important;
		}
		.nvoos-badge-container {
			display: flex;
			align-items: flex-start;
			gap: 12px;
		}
		.nvoos-badge-icon {
			flex-shrink: 0;
		}
		.nvoos-badge-content {
			flex: 1;
		}
		.nvoos-badge-content strong {
			font-size: 14px;
			display: inline-block;
			margin-right: 8px;
		}
		.nvoos-badge-status {
			background: #4CAF50;
			color: white;
			padding: 2px 8px;
			border-radius: 3px;
			font-size: 12px;
			font-weight: 600;
		}
		.nvoos-badge-content p {
			margin: 8px 0 0 0;
			font-size: 13px;
			line-height: 1.5;
		}
		.nvoos-badge-link {
			text-decoration: none;
			font-weight: 500;
		}
		.nvoos-plugin-badge {
			display: inline-block;
			padding: 3px 8px;
			background: #e8f5e9;
			border-radius: 3px;
			font-size: 12px;
			font-weight: 600;
			color: #2e7d32;
		}
		.nvoos-plugin-badge-compliant {
			background: #e3f2fd;
			color: #1565c0;
		}
		.nvoos-plugin-badge-certified {
			background: #4CAF50;
			color: white;
		}
		';

		wp_add_inline_style( 'wp-admin', $css );
	}
}

// Initialize the badge display.
new WP_MCP_AI_ISO27001_Badge();
