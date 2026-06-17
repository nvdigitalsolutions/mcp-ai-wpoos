<?php
/**
 * Pro Packages Settings Page
 *
 * Displays status and availability of pro npm packages including Sharp, Canvas,
 * Chart.js, and other dependencies.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-node-package-hints.php';

/**
 * Pro Packages Settings Page Class
 */
class WP_MCP_AI_Pro_Packages_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'pro-packages';
		$this->toolkit_name     = __( 'Pro Packages', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_pro_packages_settings';
		$this->page_slug        = 'wp-mcp-ai-pro-packages-settings';
		$this->has_research     = false;
		$this->has_remote_sites = false;
		$this->icon             = 'dashicons-admin-plugins';

		parent::__construct();

		// Add AJAX handler for package testing.
		add_action( 'wp_ajax_wp_mcp_ai_test_pro_package', array( $this, 'ajax_test_package' ) );

		// Add AJAX handler for Canvas addon installation.
		add_action( 'wp_ajax_wp_mcp_ai_install_canvas_addon', array( $this, 'ajax_install_canvas_addon' ) );
	}

	/**
	 * Get toolkit slug
	 *
	 * @return string
	 */
	protected function get_toolkit_slug() {
		return $this->toolkit_slug;
	}

	/**
	 * Get toolkit name
	 *
	 * @return string
	 */
	protected function get_toolkit_name() {
		return $this->toolkit_name;
	}

	/**
	 * Render overview tab
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-overview">
			<h2><?php esc_html_e( 'Pro Packages Status', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'View the status and availability of Node.js packages used by Pro features. These packages enable advanced functionality like image processing, document generation, and data visualization.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<?php $this->render_canvas_addon_section(); ?>
			<?php $this->render_nodejs_status(); ?>
			<?php $this->render_packages_table(); ?>
		</div>
		<?php
	}

	/**
	 * Render Node.js status section
	 */
	protected function render_nodejs_status() {
		$nodejs_available = $this->check_nodejs_available();
		$nodejs_version   = $this->get_nodejs_version();

		?>
		<div class="nodejs-status" style="background: #f9f9f9; padding: 15px; border-left: 4px solid <?php echo $nodejs_available ? '#46b450' : '#dc3232'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded color hex values. ?>; margin: 20px 0;">
			<h3 style="margin-top: 0;">
				<?php echo $nodejs_available ? '✅' : '❌'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded emoji indicators. ?>
				<?php esc_html_e( 'Node.js Runtime', 'mcp-ai-wpoos-pro' ); ?>
			</h3>
			
			<?php if ( $nodejs_available ) : ?>
				<p>
					<strong><?php esc_html_e( 'Version:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<code><?php echo esc_html( $nodejs_version ); ?></code>
				</p>
				<?php
				$min_version = '18.17.0';
				if ( version_compare( $this->parse_node_version( $nodejs_version ), $min_version, '<' ) ) :
					?>
					<p style="color: #dc3232;">
						<strong><?php esc_html_e( 'Warning:', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php
						printf(
							/* translators: 1: Current version, 2: Minimum required version */
							esc_html__( 'Your Node.js version (%1$s) is below the recommended minimum (%2$s). Some packages like Sharp may not work correctly.', 'mcp-ai-wpoos-pro' ),
							esc_html( $nodejs_version ),
							esc_html( $min_version )
						);
						?>
					</p>
				<?php else : ?>
					<p style="color: #46b450;">
						<?php esc_html_e( 'Node.js version meets all requirements for Pro packages.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
				<?php endif; ?>
			<?php else : ?>
				<p style="color: #dc3232;">
					<?php esc_html_e( 'Node.js is not installed or not accessible. Pro packages requiring Node.js will not work.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Installation:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<a href="https://nodejs.org/" target="_blank"><?php esc_html_e( 'Download Node.js', 'mcp-ai-wpoos-pro' ); ?></a>
					(<?php esc_html_e( 'Requires v18.17.0 or higher', 'mcp-ai-wpoos-pro' ); ?>)
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render packages status table
	 */
	protected function render_packages_table() {
		$packages = $this->get_package_definitions();

		?>
		<h3><?php esc_html_e( 'Package Availability', 'mcp-ai-wpoos-pro' ); ?></h3>
		<p style="font-size: 12px; color: #555;">
			<?php
			printf(
				wp_kses(
					/* translators: %s: link to the canonical CREDITS.md file. */
					__( 'Each package below links back to its upstream maintainer and license. The full repository-wide attribution index lives in <a href="%s" target="_blank" rel="noopener noreferrer">CREDITS.md</a>.', 'mcp-ai-wpoos-pro' ),
					array(
						'a' => array(
							'href'   => true,
							'target' => true,
							'rel'    => true,
						),
					)
				),
				esc_url( 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/CREDITS.md' )
			);
			?>
		</p>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width: 22%;"><?php esc_html_e( 'Package', 'mcp-ai-wpoos-pro' ); ?></th>
					<th style="width: 12%;"><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
					<th style="width: 10%;"><?php esc_html_e( 'Source', 'mcp-ai-wpoos-pro' ); ?></th>
					<th style="width: 18%;"><?php esc_html_e( 'Upstream / License', 'mcp-ai-wpoos-pro' ); ?></th>
					<th style="width: 28%;"><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></th>
					<th style="width: 10%;"><?php esc_html_e( 'Test', 'mcp-ai-wpoos-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $packages as $package ) : ?>
					<?php
					$status = $this->check_package_status( $package['name'] );
					$icon   = $status['available'] ? '✅' : ( $package['required'] ? '❌' : '⚠️' );
					$color  = $status['available'] ? 'green' : ( $package['required'] ? 'red' : 'orange' );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $package['label'] ); ?></strong>
							<br>
							<code style="font-size: 11px;"><?php echo esc_html( $package['name'] ); ?></code>
						</td>
						<td>
							<span style="color: <?php echo esc_attr( $color ); ?>;">
								<?php echo esc_html( $icon ); ?>
								<?php echo $status['available'] ? esc_html__( 'Available', 'mcp-ai-wpoos-pro' ) : esc_html__( 'Missing', 'mcp-ai-wpoos-pro' ); ?>
							</span>
						</td>
						<td>
							<?php if ( $status['available'] ) : ?>
								<span style="font-size: 11px;">
									<?php echo esc_html( ucfirst( $status['source'] ) ); ?>
								</span>
							<?php else : ?>
								<span style="color: #666; font-size: 11px;">
									<?php echo $package['required'] ? esc_html__( 'Required', 'mcp-ai-wpoos-pro' ) : esc_html__( 'Optional', 'mcp-ai-wpoos-pro' ); ?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $package['homepage'] ) ) : ?>
								<a href="<?php echo esc_url( $package['homepage'] ); ?>" target="_blank" rel="noopener noreferrer" style="font-size: 11px;">
									<?php echo esc_html( $package['homepage'] ); ?>
								</a>
								<br>
							<?php endif; ?>
							<?php if ( ! empty( $package['license'] ) ) : ?>
								<span style="font-size: 11px; color: #555;">
									<strong><?php esc_html_e( 'License:', 'mcp-ai-wpoos-pro' ); ?></strong>
									<?php echo esc_html( $package['license'] ); ?>
								</span>
								<br>
							<?php endif; ?>
							<?php if ( ! empty( $package['copyright'] ) ) : ?>
								<span style="font-size: 11px; color: #555;">
									<strong>©</strong>
									<?php echo esc_html( $package['copyright'] ); ?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( $package['description'] ); ?>
							<?php if ( ! $status['available'] && ! empty( $package['install_hint'] ) ) : ?>
								<br>
								<span style="font-size: 11px; color: #666;">
									<em><?php echo esc_html( $package['install_hint'] ); ?></em>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $status['available'] && ! empty( $package['testable'] ) ) : ?>
								<button 
									type="button" 
									class="button button-small wp-mcp-ai-test-package" 
									data-package="<?php echo esc_attr( $package['name'] ); ?>"
									data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_mcp_ai_test_package_' . $package['name'] ) ); ?>"
								>
									<span class="dashicons dashicons-yes" style="margin-top: 3px;"></span>
									<?php esc_html_e( 'Test', 'mcp-ai-wpoos-pro' ); ?>
								</button>
								<div class="test-result" style="display: none; margin-top: 5px; font-size: 11px;"></div>
							<?php else : ?>
								<span style="color: #999; font-size: 11px;">—</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div style="margin-top: 20px; padding: 15px; background: #fff; border: 1px solid #ddd;">
			<h4><?php esc_html_e( 'Installation Instructions', 'mcp-ai-wpoos-pro' ); ?></h4>
			<p><?php esc_html_e( 'Most packages are pre-packaged in the plugin. To install missing packages:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ol>
				<li>
					<?php esc_html_e( 'Ensure Node.js 18.17.0+ is installed:', 'mcp-ai-wpoos-pro' ); ?>
					<code>node --version</code>
				</li>
				<li>
					<?php esc_html_e( 'Navigate to the pro addon directory:', 'mcp-ai-wpoos-pro' ); ?>
					<br><code>cd <?php echo esc_html( WP_MCP_AI_PRO_PATH ); ?></code>
				</li>
				<li>
					<?php esc_html_e( 'Install dependencies:', 'mcp-ai-wpoos-pro' ); ?>
					<br><code>npm install --legacy-peer-deps</code>
				</li>
				<li>
					<?php esc_html_e( 'Build vendor bundles:', 'mcp-ai-wpoos-pro' ); ?>
					<br><code>npm run build</code>
				</li>
			</ol>
			<p>
				<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos-pro' ); ?></strong>
				<?php esc_html_e( 'Sharp requires platform-specific binaries and is pre-packaged for Linux x64. Other platforms need to run the install command above.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('.wp-mcp-ai-test-package').on('click', function(e) {
				e.preventDefault();
				var $button = $(this);
				var $result = $button.siblings('.test-result');
				var packageName = $button.data('package');
				var nonce = $button.data('nonce');

				// Disable button and show loading
				$button.prop('disabled', true);
				$button.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-update').css('animation', 'rotation 2s infinite linear');
				$result.hide().html('');

				// Make AJAX request
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'wp_mcp_ai_test_pro_package',
						package: packageName,
						nonce: nonce
					},
					success: function(response) {
						if (response.success) {
							$result.html('<span style="color: green;">✓ ' + response.data.message + '</span>').show();
						} else {
							$result.html('<span style="color: red;">✗ ' + response.data.message + '</span>').show();
						}
					},
					error: function() {
						$result.html('<span style="color: red;">✗ Test failed - network error</span>').show();
					},
					complete: function() {
						// Re-enable button
						$button.prop('disabled', false);
						$button.find('.dashicons').removeClass('dashicons-update').addClass('dashicons-yes').css('animation', '');
					}
				});
			});
		});
		</script>
		<style>
		@keyframes rotation {
			from { transform: rotate(0deg); }
			to { transform: rotate(359deg); }
		}
		</style>
		<?php
	}

	/**
	 * Get package definitions
	 *
	 * @return array
	 */
	protected function get_package_definitions() {
		return array(
			// Image Processing.
			array(
				'name'         => 'sharp',
				'label'        => 'Sharp',
				'description'  => __( 'High-performance image processing (resize, convert, optimize). Pre-packaged for Linux x64.', 'mcp-ai-wpoos-pro' ),
				'required'     => true,
				'testable'     => true,
				'install_hint' => __( 'Requires Node.js 18.17.0+. Pre-packaged for Linux x64, other platforms need npm install.', 'mcp-ai-wpoos-pro' ),
				'homepage'     => 'https://github.com/lovell/sharp',
				'license'      => 'Apache-2.0',
				'copyright'    => 'Lovell Fuller and contributors',
			),
			array(
				'name'         => 'canvas',
				'label'        => 'Canvas',
				'description'  => __( 'HTML5 Canvas implementation for server-side image generation and manipulation.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'testable'     => true,
				'install_hint' => WP_MCP_AI_Node_Package_Hints::get_canvas_install_hint(),
				'homepage'     => 'https://github.com/Automattic/node-canvas',
				'license'      => 'MIT',
				'copyright'    => 'Automattic and contributors',
			),

			// Document Generation.
			array(
				'name'         => 'pdfkit',
				'label'        => 'PDFKit',
				'description'  => __( 'PDF document generation with full layout control and styling.', 'mcp-ai-wpoos-pro' ),
				'required'     => true,
				'testable'     => true,
				'install_hint' => __( 'Core package for PDF generation tools.', 'mcp-ai-wpoos-pro' ),
				'homepage'     => 'https://github.com/foliojs/pdfkit',
				'license'      => 'MIT',
				'copyright'    => 'Devon Govett and contributors',
			),
			array(
				'name'         => 'docx',
				'label'        => 'Docx',
				'description'  => __( 'Create and modify Microsoft Word documents (.docx format).', 'mcp-ai-wpoos-pro' ),
				'required'     => true,
				'testable'     => true,
				'install_hint' => __( 'Core package for Word document generation.', 'mcp-ai-wpoos-pro' ),
				'homepage'     => 'https://github.com/dolanmiu/docx',
				'license'      => 'MIT',
				'copyright'    => 'Dolan Miu and contributors',
			),
			array(
				'name'         => 'exceljs',
				'label'        => 'ExcelJS',
				'description'  => __( 'Excel spreadsheet generation and manipulation with formulas and charts.', 'mcp-ai-wpoos-pro' ),
				'required'     => true,
				'testable'     => true,
				'install_hint' => __( 'Core package for Excel generation tools.', 'mcp-ai-wpoos-pro' ),
				'homepage'     => 'https://github.com/exceljs/exceljs',
				'license'      => 'MIT',
				'copyright'    => 'Guyon Roche and contributors',
			),
			array(
				'name'         => 'pdf-lib',
				'label'        => 'PDF-Lib',
				'description'  => __( 'PDF manipulation (merge, split, modify existing PDFs).', 'mcp-ai-wpoos-pro' ),
				'required'     => true,
				'testable'     => true,
				'install_hint' => __( 'Used for advanced PDF operations.', 'mcp-ai-wpoos-pro' ),
				'homepage'     => 'https://github.com/Hopding/pdf-lib',
				'license'      => 'MIT',
				'copyright'    => 'Andrew Dillon (Hopding) and contributors',
			),

			// Data Visualization.
			array(
				'name'         => 'chart.js',
				'label'        => 'Chart.js',
				'description'  => __( 'Data visualization and chart generation (line, bar, pie, etc.).', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'testable'     => true,
				'install_hint' => __( 'Enhances data visualization capabilities.', 'mcp-ai-wpoos-pro' ),
				'homepage'     => 'https://github.com/chartjs/Chart.js',
				'license'      => 'MIT',
				'copyright'    => 'Chart.js Contributors',
			),
			array(
				'name'         => 'd3',
				'label'        => 'D3.js',
				'description'  => __( 'Advanced data visualization and custom chart generation.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'testable'     => true,
				'install_hint' => __( 'For complex custom visualizations.', 'mcp-ai-wpoos-pro' ),
				'homepage'     => 'https://github.com/d3/d3',
				'license'      => 'ISC',
				'copyright'    => 'Mike Bostock and contributors',
			),

			// Math & Science.
			array(
				'name'         => 'katex',
				'label'        => 'KaTeX',
				'description'  => __( 'Fast math typesetting for rendering LaTeX equations.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'testable'     => true,
				'install_hint' => __( 'For mathematical content rendering.', 'mcp-ai-wpoos-pro' ),
				'homepage'     => 'https://github.com/KaTeX/KaTeX',
				'license'      => 'MIT',
				'copyright'    => 'Khan Academy and contributors',
			),
			array(
				'name'         => 'mathjs',
				'label'        => 'Math.js',
				'description'  => __( 'Advanced mathematics library for complex calculations.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'testable'     => true,
				'install_hint' => __( 'For mathematical computation tools.', 'mcp-ai-wpoos-pro' ),
				'homepage'     => 'https://github.com/josdejong/mathjs',
				'license'      => 'Apache-2.0',
				'copyright'    => 'Jos de Jong and contributors',
			),

			// OCR & Computer Vision.
			array(
				'name'         => 'tesseract.js',
				'label'        => 'Tesseract.js',
				'description'  => __( 'Optical Character Recognition (OCR) for extracting text from images.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'testable'     => true,
				'install_hint' => __( 'For OCR functionality in document tools.', 'mcp-ai-wpoos-pro' ),
				'homepage'     => 'https://github.com/naptha/tesseract.js',
				'license'      => 'Apache-2.0',
				'copyright'    => 'naptha and contributors',
			),

			// Optional Advanced Packages.
			array(
				'name'         => 'puppeteer-core',
				'label'        => 'Puppeteer Core',
				'description'  => __( 'Headless browser automation for HTML to PDF conversion and screenshots.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'testable'     => true,
				'install_hint' => __( 'Optional - enables advanced HTML rendering features.', 'mcp-ai-wpoos-pro' ),
				'homepage'     => 'https://github.com/puppeteer/puppeteer',
				'license'      => 'Apache-2.0',
				'copyright'    => 'Google LLC and contributors',
			),
			array(
				'name'         => 'ffmpeg-static',
				'label'        => 'FFmpeg Static',
				'description'  => __( 'Static FFmpeg binary for video processing and conversion.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'testable'     => true,
				'install_hint' => __( 'Optional - for video processing tools.', 'mcp-ai-wpoos-pro' ),
				'homepage'     => 'https://github.com/eugeneware/ffmpeg-static',
				'license'      => 'GPL-3.0',
				'copyright'    => 'Eugene Ware (ffmpeg-static wrapper); FFmpeg © Fabrice Bellard and the FFmpeg developers',
			),
		);
	}

	/**
	 * Check package status
	 *
	 * @param string $package_name Package name.
	 * @return array
	 */
	protected function check_package_status( $package_name ) {
		// Use the centralized helper function.
		if ( function_exists( 'wp_mcp_ai_get_npm_package_status' ) ) {
			return wp_mcp_ai_get_npm_package_status( $package_name );
		}

		// Fallback if helper not available.
		$vendor_path = WP_MCP_AI_PRO_PATH . 'assets/vendor/' . $package_name;
		if ( is_dir( $vendor_path ) || file_exists( $vendor_path . '/package.json' ) ) {
			return array(
				'available' => true,
				'source'    => 'vendor',
			);
		}

		$node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/' . $package_name;
		if ( is_dir( $node_modules_path ) || file_exists( $node_modules_path . '/package.json' ) ) {
			return array(
				'available' => true,
				'source'    => 'node_modules',
			);
		}

		return array(
			'available' => false,
			'source'    => '',
		);
	}

	/**
	 * Check if Node.js is available
	 *
	 * @return bool
	 */
	protected function check_nodejs_available() {
		$output = array();
		$return = null;
		@exec( 'node --version 2>&1', $output, $return ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

		return 0 === $return && ! empty( $output );
	}

	/**
	 * Get Node.js version
	 *
	 * @return string
	 */
	protected function get_nodejs_version() {
		$output = array();
		$return = null;
		@exec( 'node --version 2>&1', $output, $return ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

		if ( 0 === $return && ! empty( $output ) ) {
			return trim( $output[0] );
		}

		return __( 'Not Available', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Parse Node.js version string
	 *
	 * @param string $version Version string (e.g., 'v18.17.0').
	 * @return string Parsed version (e.g., '18.17.0').
	 */
	protected function parse_node_version( $version ) {
		// Remove 'v' prefix if present.
		return ltrim( $version, 'v' );
	}

	/**
	 * Get list of tools for this toolkit
	 *
	 * @return array Array of tool slugs and names.
	 */
	protected function get_tools_list() {
		// Pro Packages is a status/diagnostics page with no specific tools.
		// It manages npm packages that support other toolkits.
		return array();
	}

	/**
	 * Render configuration tab (not used for this page)
	 */
	protected function render_configuration_tab() {
		echo '<p>' . esc_html__( 'No configuration needed. This page shows package status only.', 'mcp-ai-wpoos-pro' ) . '</p>';
	}

	/**
	 * Override render_tabs to exclude tools and configuration tabs
	 *
	 * @param string $active_tab Active tab slug.
	 */
	protected function render_tabs( $active_tab ) {
		// Pro Packages page only needs Overview and Help tabs.
		$tabs = array(
			'overview' => __( 'Overview', 'mcp-ai-wpoos-pro' ),
			'help'     => __( 'Help & Documentation', 'mcp-ai-wpoos-pro' ),
		);

		?>
		<nav class="toolkit-settings-nav nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_slug => $tab_title ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', $tab_slug, admin_url( 'admin.php?page=' . $this->page_slug ) ) ); ?>"
					class="nav-tab <?php echo $active_tab === $tab_slug ? 'nav-tab-active' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded CSS class. ?>"
				>
					<?php echo esc_html( $tab_title ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * AJAX handler for testing packages.
	 */
	public function ajax_test_package() {
		// Get package name from request.
		$package = isset( $_POST['package'] ) ? sanitize_text_field( wp_unslash( $_POST['package'] ) ) : '';

		if ( empty( $package ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid package name.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Verify nonce.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wp_mcp_ai_test_package_' . $package ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Check user capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Test the package.
		$result = $this->test_package( $package );

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * Test a specific package.
	 *
	 * @param string $package Package name.
	 * @return array Test result with 'success' and 'message' keys.
	 */
	protected function test_package( $package ) {
		// First check if package is available.
		$status = $this->check_package_status( $package );
		if ( ! $status['available'] ) {
			return array(
				'success' => false,
				'message' => __( 'Package is not installed.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Perform package-specific tests.
		switch ( $package ) {
			case 'sharp':
				return $this->test_sharp();

			case 'canvas':
				return $this->test_canvas();

			case 'pdfkit':
				return $this->test_pdfkit();

			case 'docx':
				return $this->test_docx();

			case 'exceljs':
				return $this->test_exceljs();

			case 'pdf-lib':
				return $this->test_pdf_lib();

			case 'chart.js':
				return $this->test_chartjs();

			case 'd3':
				return $this->test_d3();

			case 'katex':
				return $this->test_katex();

			case 'mathjs':
				return $this->test_mathjs();

			case 'tesseract.js':
				return $this->test_tesseract();

			case 'puppeteer-core':
				return $this->test_puppeteer_core();

			case 'ffmpeg-static':
				return $this->test_ffmpeg_static();

			default:
				return array(
					'success' => true,
					'message' => __( 'Package is available but testing is not implemented.', 'mcp-ai-wpoos-pro' ),
				);
		}
	}

	/**
	 * Test Sharp package.
	 *
	 * @return array Test result.
	 */
	protected function test_sharp() {
		try {
			// Use the helper function if available.
			if ( function_exists( 'wp_mcp_ai_get_npm_package_status' ) ) {
				$status = wp_mcp_ai_get_npm_package_status( 'sharp' );
				if ( $status['available'] ) {
					return array(
						'success' => true,
						'message' => sprintf(
							/* translators: %s: Package source (vendor or node_modules) */
							__( 'Sharp is installed and available from %s.', 'mcp-ai-wpoos-pro' ),
							$status['source']
						),
					);
				}
			}

			return array(
				'success' => true,
				'message' => __( 'Sharp package found and appears functional.', 'mcp-ai-wpoos-pro' ),
			);
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: Error message */
					__( 'Sharp test failed: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Test Canvas package.
	 *
	 * @return array Test result.
	 */
	protected function test_canvas() {
		try {
			if ( function_exists( 'wp_mcp_ai_get_npm_package_status' ) ) {
				$status = wp_mcp_ai_get_npm_package_status( 'canvas' );
				if ( $status['available'] ) {
					return array(
						'success' => true,
						'message' => sprintf(
							/* translators: %s: Package source (vendor or node_modules) */
							__( 'Canvas is installed and available from %s.', 'mcp-ai-wpoos-pro' ),
							$status['source']
						),
					);
				}
				return array(
					'success' => false,
					'message' => __( 'Canvas package is not installed. Install the NV oOS Canvas Addon plugin to enable canvas support.', 'mcp-ai-wpoos-pro' ),
				);
			}

			// Fallback: check node_modules directly.
			$canvas_path = WP_MCP_AI_PRO_PATH . 'node_modules/canvas';
			if ( is_dir( $canvas_path ) ) {
				return array(
					'success' => true,
					'message' => __( 'Canvas package found in node_modules.', 'mcp-ai-wpoos-pro' ),
				);
			}

			return array(
				'success' => false,
				'message' => __( 'Canvas package is not installed. Install the NV oOS Canvas Addon plugin to enable canvas support.', 'mcp-ai-wpoos-pro' ),
			);
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: Error message */
					__( 'Canvas test failed: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Test PDFKit package.
	 *
	 * @return array Test result.
	 */
	protected function test_pdfkit() {
		return array(
			'success' => true,
			'message' => __( 'PDFKit package is installed and available.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Test Docx package.
	 *
	 * @return array Test result.
	 */
	protected function test_docx() {
		return array(
			'success' => true,
			'message' => __( 'Docx package is installed and available.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Test ExcelJS package.
	 *
	 * @return array Test result.
	 */
	protected function test_exceljs() {
		return array(
			'success' => true,
			'message' => __( 'ExcelJS package is installed and available.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Test PDF-Lib package.
	 *
	 * @return array Test result.
	 */
	protected function test_pdf_lib() {
		return array(
			'success' => true,
			'message' => __( 'PDF-Lib package is installed and available.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Test Chart.js package.
	 *
	 * @return array Test result.
	 */
	protected function test_chartjs() {
		return array(
			'success' => true,
			'message' => __( 'Chart.js package is installed and available.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Test D3.js package.
	 *
	 * @return array Test result.
	 */
	protected function test_d3() {
		return array(
			'success' => true,
			'message' => __( 'D3.js package is installed and available.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Test KaTeX package.
	 *
	 * @return array Test result.
	 */
	protected function test_katex() {
		return array(
			'success' => true,
			'message' => __( 'KaTeX package is installed and available.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Test Math.js package.
	 *
	 * @return array Test result.
	 */
	protected function test_mathjs() {
		return array(
			'success' => true,
			'message' => __( 'Math.js package is installed and available.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Test Tesseract.js package.
	 *
	 * @return array Test result.
	 */
	protected function test_tesseract() {
		return array(
			'success' => true,
			'message' => __( 'Tesseract.js package is installed and available.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Test Puppeteer Core package.
	 *
	 * @return array Test result.
	 */
	protected function test_puppeteer_core() {
		try {
			// Check if package files exist.
			if ( function_exists( 'wp_mcp_ai_get_npm_package_status' ) ) {
				$status = wp_mcp_ai_get_npm_package_status( 'puppeteer-core' );
				if ( $status['available'] ) {
					return array(
						'success' => true,
						'message' => sprintf(
							/* translators: %s: Package source (vendor or node_modules) */
							__( 'Puppeteer Core is installed and available from %s.', 'mcp-ai-wpoos-pro' ),
							$status['source']
						),
					);
				}
			}

			return array(
				'success' => true,
				'message' => __( 'Puppeteer Core package found and appears functional.', 'mcp-ai-wpoos-pro' ),
			);
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: Error message */
					__( 'Puppeteer Core test failed: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				),
			);
		}
	}

	// -------------------------------------------------------------------------
	// Canvas Addon (WordPress plugin) section.
	// -------------------------------------------------------------------------

	/**
	 * Return status information about the NV oOS Canvas Addon plugin.
	 *
	 * @return array {
	 *     @type bool   $active     Whether the plugin is currently active.
	 *     @type bool   $installed  Whether the plugin is installed (may be inactive).
	 *     @type bool   $zip_found  Whether an install ZIP is available on disk.
	 *     @type string $zip_path   Absolute path to the ZIP, or '' if none found.
	 * }
	 */
	protected function get_canvas_addon_status() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_file = 'nvoos-canvas/nvoos-canvas.php';
		$active      = is_plugin_active( $plugin_file );
		$installed   = defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/' . $plugin_file );
		$zip_path    = $this->get_canvas_zip_path();
		$zip_found   = ! empty( $zip_path ) && file_exists( $zip_path );

		return array(
			'active'    => $active,
			'installed' => $installed,
			'zip_found' => $zip_found,
			'zip_path'  => $zip_path,
		);
	}

	/**
	 * Locate the canvas addon ZIP that matches the current server platform.
	 *
	 * Looks inside the main plugin's build/ directory for a file matching
	 * nvoos-canvas-linux-{arch}-v*.zip, returning the newest version found.
	 *
	 * @return string Absolute path to the ZIP, or '' if not found.
	 */
	protected function get_canvas_zip_path() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			return '';
		}

		// Map uname -m to the slug used in ZIP filenames.
		$machine = php_uname( 'm' );
		if ( false !== strpos( $machine, 'aarch64' ) || false !== strpos( $machine, 'arm64' ) ) {
			$arch = 'arm64';
		} else {
			$arch = 'x64';
		}

		$pattern = WP_MCP_AI_PATH . 'build/nvoos-canvas-linux-' . $arch . '-v*.zip';
		$matches = glob( $pattern );

		if ( ! empty( $matches ) ) {
			usort( $matches, 'strnatcmp' );
			return end( $matches );
		}

		return '';
	}

	/**
	 * Render the Canvas Addon status card with a one-click install button.
	 */
	protected function render_canvas_addon_section() {
		$canvas = $this->get_canvas_addon_status();

		$status_color = $canvas['active'] ? '#46b450' : ( $canvas['installed'] ? '#f0b849' : '#dc3232' );
		if ( $canvas['active'] ) {
			$status_icon  = '✅';
			$status_label = __( 'Active', 'mcp-ai-wpoos-pro' );
		} elseif ( $canvas['installed'] ) {
			$status_icon  = '⚠️';
			$status_label = __( 'Installed (Inactive)', 'mcp-ai-wpoos-pro' );
		} else {
			$status_icon  = '❌';
			$status_label = __( 'Not Installed', 'mcp-ai-wpoos-pro' );
		}
		?>
		<div class="canvas-addon-status" style="background:#fff;border:1px solid #ddd;border-left:4px solid <?php echo esc_attr( $status_color ); ?>;padding:20px;margin:20px 0;">
			<h3 style="margin-top:0;">
				🖼️ <?php esc_html_e( 'NV oOS Canvas Addon', 'mcp-ai-wpoos-pro' ); ?>
				<span style="font-weight:normal;font-size:14px;color:<?php echo esc_attr( $status_color ); ?>;margin-left:10px;">
					<?php echo esc_html( $status_icon . ' ' . $status_label ); ?>
				</span>
			</h3>

			<p><?php esc_html_e( 'PDF OCR with Tesseract requires the canvas library. The NV oOS Canvas Addon provides pre-compiled native binaries bundled for your platform — no system library installation required.', 'mcp-ai-wpoos-pro' ); ?></p>

			<?php if ( $canvas['active'] ) : ?>
				<?php if ( function_exists( 'nvoos_canvas_is_available' ) && nvoos_canvas_is_available() ) : ?>
					<p style="color:#46b450;margin:0;">
						<strong><?php esc_html_e( '✅ Canvas is active and ready. Tesseract PDF OCR is enabled.', 'mcp-ai-wpoos-pro' ); ?></strong>
					</p>
					<?php if ( class_exists( 'NV_oOS_Canvas' ) ) : ?>
						<p style="font-size:12px;color:#666;margin-top:6px;">
							<?php
							printf(
								/* translators: %s: Platform label e.g. linux-x64 (Node 20) */
								esc_html__( 'Platform: %s', 'mcp-ai-wpoos-pro' ),
								esc_html( NV_oOS_Canvas::get_platform_label() )
							);
							?>
						</p>
					<?php endif; ?>
				<?php else : ?>
					<p style="color:#f0b849;">
						<?php esc_html_e( '⚠️ Plugin is active but native binaries are missing. Re-install the canvas addon ZIP for your platform.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
				<?php endif; ?>

			<?php else : ?>
				<div style="background:#f9f9f9;padding:12px;border-radius:3px;margin:10px 0;">
					<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'One-click install will:', 'mcp-ai-wpoos-pro' ); ?></strong></p>
					<ol style="margin:0 0 0 20px;padding:0;">
						<li><?php esc_html_e( 'Install the NV oOS Canvas WordPress plugin', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Provide pre-compiled canvas native binaries for your platform (no system libraries needed)', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Activate the plugin and enable Tesseract PDF OCR', 'mcp-ai-wpoos-pro' ); ?></li>
					</ol>
				</div>

				<?php if ( $canvas['installed'] ) : ?>
					<button
						type="button"
						id="wp-mcp-ai-install-canvas-btn"
						class="button button-primary"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_mcp_ai_install_canvas_addon' ) ); ?>"
					>
						<span class="dashicons dashicons-yes" style="margin-top:3px;"></span>
						<?php esc_html_e( 'Activate Canvas', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				<?php elseif ( $canvas['zip_found'] ) : ?>
					<button
						type="button"
						id="wp-mcp-ai-install-canvas-btn"
						class="button button-primary"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_mcp_ai_install_canvas_addon' ) ); ?>"
					>
						<span class="dashicons dashicons-download" style="margin-top:3px;"></span>
						<?php esc_html_e( 'Install Canvas', 'mcp-ai-wpoos-pro' ); ?>
					</button>
					<p style="color:#666;font-size:12px;margin-top:6px;">
						<?php
						printf(
							/* translators: %s: ZIP filename */
							esc_html__( 'ZIP found: %s', 'mcp-ai-wpoos-pro' ),
							esc_html( basename( $canvas['zip_path'] ) )
						);
						?>
					</p>
				<?php else : ?>
					<a
						href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=upload' ) ); ?>"
						class="button button-primary"
					>
						<span class="dashicons dashicons-upload" style="margin-top:3px;"></span>
						<?php esc_html_e( 'Upload Canvas Plugin', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<p style="color:#666;font-size:12px;margin-top:6px;">
						<?php esc_html_e( 'Download the canvas ZIP for your platform from the NV oOS releases and upload via Plugins → Add New → Upload Plugin.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
				<?php endif; ?>

				<div id="wp-mcp-ai-canvas-install-result" style="display:none;margin-top:10px;"></div>

				<p style="color:#666;font-size:12px;margin-bottom:0;margin-top:8px;">
					<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php esc_html_e( 'The canvas addon includes platform-specific native binaries. No Node.js or system library installation required.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<?php if ( ! $canvas['active'] && ( $canvas['installed'] || $canvas['zip_found'] ) ) : ?>
		<script type="text/javascript">
		jQuery( document ).ready( function( $ ) {
			$( '#wp-mcp-ai-install-canvas-btn' ).on( 'click', function( e ) {
				e.preventDefault();
				var $button = $( this );
				var $result = $( '#wp-mcp-ai-canvas-install-result' );
				var nonce   = $button.data( 'nonce' );

				$button.prop( 'disabled', true );
				$button.find( '.dashicons' )
					.removeClass( 'dashicons-download dashicons-yes' )
					.addClass( 'dashicons-update' )
					.css( 'animation', 'rotation 2s infinite linear' );
				$result.hide().html( '' );

				$.ajax( {
					url:     ajaxurl,
					type:    'POST',
					timeout: 120000,
					data: {
						action: 'wp_mcp_ai_install_canvas_addon',
						nonce:  nonce
					},
					success: function( response ) {
						if ( response.success ) {
							$result.html( '<span style="color:green;font-weight:bold;">✅ ' + response.data.message + '</span>' ).show();
							setTimeout( function() { location.reload(); }, 2000 );
						} else {
							$result.html( '<span style="color:red;">✗ ' + response.data.message + '</span>' ).show();
							$button.prop( 'disabled', false );
							$button.find( '.dashicons' )
								.removeClass( 'dashicons-update' )
								.addClass( 'dashicons-download dashicons-yes' )
								.css( 'animation', '' );
						}
					},
					error: function() {
						$result.html( '<span style="color:red;">✗ <?php echo esc_js( __( 'Installation failed — network error.', 'mcp-ai-wpoos-pro' ) ); ?></span>' ).show();
						$button.prop( 'disabled', false );
						$button.find( '.dashicons' )
							.removeClass( 'dashicons-update' )
							.addClass( 'dashicons-download dashicons-yes' )
							.css( 'animation', '' );
					}
				} );
			} );
		} );
		</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * AJAX handler — install and activate the NV oOS Canvas Addon plugin.
	 */
	public function ajax_install_canvas_addon() {
		// Verify nonce.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wp_mcp_ai_install_canvas_addon' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Require install_plugins capability.
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$canvas = $this->get_canvas_addon_status();

		// Already active — nothing to do.
		if ( $canvas['active'] ) {
			wp_send_json_success( array( 'message' => __( 'Canvas addon is already installed and active.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Installed but inactive — just activate.
		if ( $canvas['installed'] ) {
			if ( ! function_exists( 'activate_plugin' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$activated = activate_plugin( 'nvoos-canvas/nvoos-canvas.php' );
			if ( is_wp_error( $activated ) ) {
				wp_send_json_error( array( 'message' => $activated->get_error_message() ) );
			}
			wp_send_json_success( array( 'message' => __( 'Canvas addon activated successfully.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// ZIP not available.
		if ( ! $canvas['zip_found'] ) {
			wp_send_json_error(
				array(
					'message' => __( 'Canvas addon ZIP not found. Please upload the nvoos-canvas plugin ZIP manually via Plugins → Add New → Upload Plugin.', 'mcp-ai-wpoos-pro' ),
				)
			);
		}

		// Install from the bundled ZIP.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

		WP_Filesystem();

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $canvas['zip_path'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( ! $result ) {
			$errors = $skin->get_errors();
			$msg    = is_wp_error( $errors ) && $errors->has_errors()
				? $errors->get_error_message()
				: __( 'Installation failed. Check file permissions.', 'mcp-ai-wpoos-pro' );
			wp_send_json_error( array( 'message' => $msg ) );
		}

		// Activate after successful install.
		$activated = activate_plugin( 'nvoos-canvas/nvoos-canvas.php' );
		if ( is_wp_error( $activated ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: Error message */
						__( 'Canvas addon installed but activation failed: %s', 'mcp-ai-wpoos-pro' ),
						$activated->get_error_message()
					),
				)
			);
		}

		wp_send_json_success( array( 'message' => __( 'Canvas addon installed and activated successfully.', 'mcp-ai-wpoos-pro' ) ) );
	}

	/**
	 * Test FFmpeg Static package.
	 *
	 * @return array Test result.
	 */
	protected function test_ffmpeg_static() {
		try {
			// Check if package files exist.
			if ( function_exists( 'wp_mcp_ai_get_npm_package_status' ) ) {
				$status = wp_mcp_ai_get_npm_package_status( 'ffmpeg-static' );
				if ( $status['available'] ) {
					return array(
						'success' => true,
						'message' => sprintf(
							/* translators: %s: Package source (vendor or node_modules) */
							__( 'FFmpeg Static is installed and available from %s.', 'mcp-ai-wpoos-pro' ),
							$status['source']
						),
					);
				}
			}

			return array(
				'success' => true,
				'message' => __( 'FFmpeg Static package found and appears functional.', 'mcp-ai-wpoos-pro' ),
			);
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: Error message */
					__( 'FFmpeg Static test failed: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				),
			);
		}
	}
}

// Instantiate the settings page.
new WP_MCP_AI_Pro_Packages_Settings_Page();
