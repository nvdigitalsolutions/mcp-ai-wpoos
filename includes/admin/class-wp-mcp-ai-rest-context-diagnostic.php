<?php
/**
 * REST API Context Parameter Diagnostic Tool
 *
 * Helps diagnose and fix issues with WordPress REST API context parameter handling.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API Context Diagnostic Admin Page
 *
 * Provides a diagnostic interface in the WordPress admin to check for
 * REST API context parameter issues.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_REST_Context_Diagnostic {

	/**
	 * Initialize the diagnostic page
	 *
	 * @return void
	 */
	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'add_diagnostic_page' ), 100 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		}
	}

	/**
	 * Add diagnostic page to Tools menu
	 *
	 * @return void
	 */
	public static function add_diagnostic_page() {
		add_submenu_page(
			'tools.php',
			__( 'REST API Context Diagnostic', 'wp-mcp-ai' ),
			__( 'REST API Context', 'wp-mcp-ai' ),
			'manage_options',
			'wp-mcp-ai-rest-context-diagnostic',
			array( __CLASS__, 'render_diagnostic_page' )
		);
	}

	/**
	 * Enqueue scripts for the diagnostic page
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_scripts( $hook ) {
		if ( 'tools_page_wp-mcp-ai-rest-context-diagnostic' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wp-mcp-ai-diagnostic', false );
		wp_add_inline_style(
			'wp-mcp-ai-diagnostic',
			'
			.wp-mcp-ai-diagnostic .status-ok { color: #46b450; font-weight: bold; }
			.wp-mcp-ai-diagnostic .status-warning { color: #ffb900; font-weight: bold; }
			.wp-mcp-ai-diagnostic .status-error { color: #dc3232; font-weight: bold; }
			.wp-mcp-ai-diagnostic .test-result { padding: 10px; margin: 10px 0; background: #f0f0f1; border-left: 4px solid #72aee6; }
			.wp-mcp-ai-diagnostic .test-result.ok { border-left-color: #46b450; }
			.wp-mcp-ai-diagnostic .test-result.warning { border-left-color: #ffb900; }
			.wp-mcp-ai-diagnostic .test-result.error { border-left-color: #dc3232; }
			.wp-mcp-ai-diagnostic .code-block { background: #1e1e1e; color: #d4d4d4; padding: 15px; font-family: monospace; font-size: 13px; overflow-x: auto; }
			.wp-mcp-ai-diagnostic .recommendation { padding: 15px; background: #fff; border: 1px solid #c3c4c7; margin: 10px 0; }
			.wp-mcp-ai-diagnostic .recommendation h4 { margin-top: 0; }
			'
		);
	}

	/**
	 * Render the diagnostic page
	 *
	 * @return void
	 */
	public static function render_diagnostic_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-mcp-ai' ) );
		}

		$diagnostics = WP_MCP_AI_REST_API_Context_Fix::get_diagnostics();

		?>
		<div class="wrap wp-mcp-ai-diagnostic">
			<h1><?php esc_html_e( 'REST API Context Parameter Diagnostic', 'wp-mcp-ai' ); ?></h1>
			
			<p>
				<?php
				esc_html_e(
					'This diagnostic tool helps identify issues with WordPress REST API context parameter handling. The context parameter (e.g., ?context=edit) is critical for the block editor and many plugins.',
					'wp-mcp-ai'
				);
				?>
			</p>

			<h2><?php esc_html_e( 'System Checks', 'wp-mcp-ai' ); ?></h2>

			<!-- Pretty Permalinks -->
			<div class="test-result <?php echo $diagnostics['rest_url_rewrite_enabled'] ? 'ok' : 'warning'; ?>">
				<h3>
					<span class="dashicons dashicons-<?php echo $diagnostics['rest_url_rewrite_enabled'] ? 'yes' : 'warning'; ?>"></span>
					<?php esc_html_e( 'Pretty Permalinks', 'wp-mcp-ai' ); ?>
				</h3>
				<p>
					<strong><?php esc_html_e( 'Status:', 'wp-mcp-ai' ); ?></strong>
					<span class="status-<?php echo $diagnostics['rest_url_rewrite_enabled'] ? 'ok' : 'warning'; ?>">
						<?php echo $diagnostics['rest_url_rewrite_enabled'] ? esc_html__( 'Enabled', 'wp-mcp-ai' ) : esc_html__( 'Disabled', 'wp-mcp-ai' ); ?>
					</span>
				</p>
				<?php if ( ! $diagnostics['rest_url_rewrite_enabled'] ) : ?>
					<p><?php esc_html_e( 'Pretty permalinks are not enabled. This may cause issues with REST API requests.', 'wp-mcp-ai' ); ?></p>
					<p><?php esc_html_e( 'Go to Settings → Permalinks and select any option other than "Plain".', 'wp-mcp-ai' ); ?></p>
				<?php endif; ?>
			</div>

			<!-- Server Software -->
			<div class="test-result ok">
				<h3>
					<span class="dashicons dashicons-info"></span>
					<?php esc_html_e( 'Server Software', 'wp-mcp-ai' ); ?>
				</h3>
				<p>
					<strong><?php esc_html_e( 'Detected:', 'wp-mcp-ai' ); ?></strong>
					<?php echo esc_html( $diagnostics['server_software'] ); ?>
				</p>
			</div>

			<!-- Caching Plugins -->
			<div class="test-result <?php echo empty( $diagnostics['caching_plugins'] ) ? 'ok' : 'warning'; ?>">
				<h3>
					<span class="dashicons dashicons-<?php echo empty( $diagnostics['caching_plugins'] ) ? 'yes' : 'warning'; ?>"></span>
					<?php esc_html_e( 'Caching Plugins', 'wp-mcp-ai' ); ?>
				</h3>
				<p>
					<strong><?php esc_html_e( 'Status:', 'wp-mcp-ai' ); ?></strong>
					<?php
					if ( empty( $diagnostics['caching_plugins'] ) ) {
						echo '<span class="status-ok">' . esc_html__( 'None detected', 'wp-mcp-ai' ) . '</span>';
					} else {
						echo '<span class="status-warning">' . esc_html( implode( ', ', $diagnostics['caching_plugins'] ) ) . '</span>';
					}
					?>
				</p>
				<?php if ( ! empty( $diagnostics['caching_plugins'] ) ) : ?>
					<p><?php esc_html_e( 'The following caching plugins are active. Make sure to exclude /wp-json/* from caching.', 'wp-mcp-ai' ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $diagnostics['recommendations'] ) ) : ?>
				<h2><?php esc_html_e( 'Recommendations', 'wp-mcp-ai' ); ?></h2>
				
				<?php foreach ( $diagnostics['recommendations'] as $recommendation ) : ?>
					<div class="recommendation">
						<p><?php echo esc_html( $recommendation ); ?></p>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Server Configuration Examples', 'wp-mcp-ai' ); ?></h2>

			<?php if ( stripos( $diagnostics['server_software'], 'nginx' ) !== false ) : ?>
				<!-- Nginx Configuration -->
				<h3><?php esc_html_e( 'Nginx Configuration', 'wp-mcp-ai' ); ?></h3>
				<p><?php esc_html_e( 'Add this to your Nginx configuration file:', 'wp-mcp-ai' ); ?></p>
				<div class="code-block">location ~* ^/wp-json/ {<br>
	add_header Cache-Control "no-store, no-cache, must-revalidate, max-age=0" always;<br>
	add_header Pragma "no-cache" always;<br>
	add_header Expires "0" always;<br>
	try_files $uri $uri/ /index.php?$args;<br>
}</div>
			<?php endif; ?>

			<?php if ( stripos( $diagnostics['server_software'], 'apache' ) !== false ) : ?>
				<!-- Apache Configuration -->
				<h3><?php esc_html_e( 'Apache .htaccess Configuration', 'wp-mcp-ai' ); ?></h3>
				<p><?php esc_html_e( 'Add this to your .htaccess file:', 'wp-mcp-ai' ); ?></p>
				<div class="code-block">&lt;FilesMatch "^(wp-json)"&gt;<br>
	&lt;IfModule mod_headers.c&gt;<br>
		Header set Cache-Control "no-store, no-cache, must-revalidate, max-age=0"<br>
		Header set Pragma "no-cache"<br>
		Header set Expires "0"<br>
	&lt;/IfModule&gt;<br>
&lt;/FilesMatch&gt;<br>
<br>
# Preserve query strings (QSA flag)<br>
RewriteRule ^wp-json/(.*)$ /index.php?rest_route=/$1 [QSA,L]</div>
			<?php endif; ?>

			<!-- Cloudflare Configuration -->
			<h3><?php esc_html_e( 'Cloudflare Configuration', 'wp-mcp-ai' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Go to Cloudflare → Caching → Configuration', 'wp-mcp-ai' ); ?></li>
				<li><?php esc_html_e( 'Create a Cache Rule for URI Path contains "/wp-json/"', 'wp-mcp-ai' ); ?></li>
				<li><?php esc_html_e( 'Set Cache Level to "Bypass"', 'wp-mcp-ai' ); ?></li>
				<li><?php esc_html_e( 'Go to Security → WAF', 'wp-mcp-ai' ); ?></li>
				<li><?php esc_html_e( 'Add Exception to skip all rules for /wp-json/* paths', 'wp-mcp-ai' ); ?></li>
			</ol>

			<h2><?php esc_html_e( 'Testing', 'wp-mcp-ai' ); ?></h2>
			<p><?php esc_html_e( 'Use these commands to test if the context parameter is working:', 'wp-mcp-ai' ); ?></p>
			
			<h4><?php esc_html_e( 'Test 1: Check cache headers', 'wp-mcp-ai' ); ?></h4>
			<div class="code-block">curl -I "<?php echo esc_url( rest_url( 'wp/v2/posts' ) ); ?>?context=edit" | grep -i "cache-control"</div>
			<p><strong><?php esc_html_e( 'Expected:', 'wp-mcp-ai' ); ?></strong> Cache-Control: no-store, no-cache, must-revalidate, max-age=0</p>

			<h4><?php esc_html_e( 'Test 2: Verify query string preservation', 'wp-mcp-ai' ); ?></h4>
			<div class="code-block">curl -v "<?php echo esc_url( rest_url( 'wp/v2/types' ) ); ?>?context=edit" 2>&1 | grep -i "context"</div>
			<p><strong><?php esc_html_e( 'Expected:', 'wp-mcp-ai' ); ?></strong> <?php esc_html_e( 'Should show context=edit in the request', 'wp-mcp-ai' ); ?></p>

			<h2><?php esc_html_e( 'Documentation', 'wp-mcp-ai' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: link to documentation */
					esc_html__( 'For comprehensive server configuration instructions, see the %s documentation.', 'wp-mcp-ai' ),
					'<a href="https://github.com/nvdigitalsolutions/wp-mcp-ai/blob/main/docs/deployment-troubleshooting.md#rest-api-context-parameter-issues" target="_blank">' . esc_html__( 'deployment troubleshooting', 'wp-mcp-ai' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
