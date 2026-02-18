<?php
/**
 * Pro Packages Settings Page
 *
 * Displays status and availability of pro npm packages including Sharp, Canvas,
 * Chart.js, and other dependencies.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

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
		<div class="nodejs-status" style="background: #f9f9f9; padding: 15px; border-left: 4px solid <?php echo $nodejs_available ? '#46b450' : '#dc3232'; ?>; margin: 20px 0;">
			<h3 style="margin-top: 0;">
				<?php echo $nodejs_available ? '✅' : '❌'; ?>
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
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width: 25%;"><?php esc_html_e( 'Package', 'mcp-ai-wpoos-pro' ); ?></th>
					<th style="width: 15%;"><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
					<th style="width: 15%;"><?php esc_html_e( 'Source', 'mcp-ai-wpoos-pro' ); ?></th>
					<th style="width: 45%;"><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></th>
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
							<?php echo esc_html( $package['description'] ); ?>
							<?php if ( ! $status['available'] && ! empty( $package['install_hint'] ) ) : ?>
								<br>
								<span style="font-size: 11px; color: #666;">
									<em><?php echo esc_html( $package['install_hint'] ); ?></em>
								</span>
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
				'install_hint' => __( 'Requires Node.js 18.17.0+. Pre-packaged for Linux x64, other platforms need npm install.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'name'         => 'canvas',
				'label'        => 'Canvas',
				'description'  => __( 'HTML5 Canvas implementation for server-side image generation and manipulation.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'install_hint' => __( 'Requires system dependencies (cairo, pango, etc.) for compilation.', 'mcp-ai-wpoos-pro' ),
			),

			// Document Generation.
			array(
				'name'         => 'pdfkit',
				'label'        => 'PDFKit',
				'description'  => __( 'PDF document generation with full layout control and styling.', 'mcp-ai-wpoos-pro' ),
				'required'     => true,
				'install_hint' => __( 'Core package for PDF generation tools.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'name'         => 'docx',
				'label'        => 'Docx',
				'description'  => __( 'Create and modify Microsoft Word documents (.docx format).', 'mcp-ai-wpoos-pro' ),
				'required'     => true,
				'install_hint' => __( 'Core package for Word document generation.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'name'         => 'exceljs',
				'label'        => 'ExcelJS',
				'description'  => __( 'Excel spreadsheet generation and manipulation with formulas and charts.', 'mcp-ai-wpoos-pro' ),
				'required'     => true,
				'install_hint' => __( 'Core package for Excel generation tools.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'name'         => 'pdf-lib',
				'label'        => 'PDF-Lib',
				'description'  => __( 'PDF manipulation (merge, split, modify existing PDFs).', 'mcp-ai-wpoos-pro' ),
				'required'     => true,
				'install_hint' => __( 'Used for advanced PDF operations.', 'mcp-ai-wpoos-pro' ),
			),

			// Data Visualization.
			array(
				'name'         => 'chart.js',
				'label'        => 'Chart.js',
				'description'  => __( 'Data visualization and chart generation (line, bar, pie, etc.).', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'install_hint' => __( 'Enhances data visualization capabilities.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'name'         => 'd3',
				'label'        => 'D3.js',
				'description'  => __( 'Advanced data visualization and custom chart generation.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'install_hint' => __( 'For complex custom visualizations.', 'mcp-ai-wpoos-pro' ),
			),

			// Math & Science.
			array(
				'name'         => 'katex',
				'label'        => 'KaTeX',
				'description'  => __( 'Fast math typesetting for rendering LaTeX equations.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'install_hint' => __( 'For mathematical content rendering.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'name'         => 'mathjs',
				'label'        => 'Math.js',
				'description'  => __( 'Advanced mathematics library for complex calculations.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'install_hint' => __( 'For mathematical computation tools.', 'mcp-ai-wpoos-pro' ),
			),

			// OCR & Computer Vision.
			array(
				'name'         => 'tesseract.js',
				'label'        => 'Tesseract.js',
				'description'  => __( 'Optical Character Recognition (OCR) for extracting text from images.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'install_hint' => __( 'For OCR functionality in document tools.', 'mcp-ai-wpoos-pro' ),
			),

			// Optional Advanced Packages.
			array(
				'name'         => 'puppeteer-core',
				'label'        => 'Puppeteer Core',
				'description'  => __( 'Headless browser automation for HTML to PDF conversion and screenshots.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'install_hint' => __( 'Optional - enables advanced HTML rendering features.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'name'         => 'ffmpeg-static',
				'label'        => 'FFmpeg Static',
				'description'  => __( 'Static FFmpeg binary for video processing and conversion.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'install_hint' => __( 'Optional - for video processing tools.', 'mcp-ai-wpoos-pro' ),
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
	 * Render configuration tab (not used for this page)
	 */
	protected function render_configuration_tab() {
		echo '<p>' . esc_html__( 'No configuration needed. This page shows package status only.', 'mcp-ai-wpoos-pro' ) . '</p>';
	}
}

// Instantiate the settings page.
new WP_MCP_AI_Pro_Packages_Settings_Page();
