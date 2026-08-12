<?php
/**
 * Media Worker Sidecar Settings
 *
 * Admin UI for configuring and testing the optional Design Stack
 * Media Worker sidecar connection. When configured, heavy NPM-package
 * operations are offloaded to the sidecar via HTTP instead of running
 * local Node.js subprocesses.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media Worker Settings Page Class
 *
 * Registers an admin settings page under Settings that allows
 * site administrators to configure and test the sidecar connection.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Media_Worker_Settings {
	const OPTION_URL       = 'wp_mcp_ai_media_worker_url';
	const OPTION_TOKEN     = 'wp_mcp_ai_media_worker_token';
	const HEALTH_TRANSIENT = 'wp_mcp_ai_media_worker_health';
	const HEALTH_CACHE_TTL = 60;

	/**
	 * Constructor.
	 *
	 * Hooks into WordPress admin.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_page' ), 30 );
		add_action( 'admin_post_wp_mcp_ai_save_media_worker_settings', array( $this, 'save_settings' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_media_worker', array( $this, 'ajax_test_connection' ) );
	}

	/**
	 * Register the submenu page under the Settings menu.
	 */
	public function add_page() {
		add_options_page(
			__( 'Media Worker Sidecar', 'mcp-ai-wpoos-pro' ),
			__( 'Media Worker', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'wp-mcp-ai-media-worker',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render() {
		$url                = $this->get_url();
		$token              = get_option( self::OPTION_TOKEN, '' );
		$has_constant       = defined( 'WP_MEDIA_WORKER_URL' ) && WP_MEDIA_WORKER_URL;
		$has_token_constant = defined( 'WP_MEDIA_WORKER_TOKEN' ) && WP_MEDIA_WORKER_TOKEN;
		$insecure_url       = ( is_ssl() && $url && 0 === strpos( $url, 'http://' ) );
		$health             = $this->get_cached_health();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Media Worker Sidecar', 'mcp-ai-wpoos-pro' ); ?></h1>
			<div class="notice notice-info"><p><?php esc_html_e( 'The Media Worker sidecar offloads heavy NPM-package operations (PDF, OCR, video, email, code formatting) from WordPress to a dedicated Node.js container. When available, service classes route to the sidecar via HTTP. When unavailable, existing local fallbacks continue to work.', 'mcp-ai-wpoos-pro' ); ?></p></div>
			<?php if ( $has_constant ) : ?>
				<div class="notice notice-success inline"><p><strong><?php esc_html_e( 'Docker Mode:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'URL set via WP_MEDIA_WORKER_URL constant.', 'mcp-ai-wpoos-pro' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $has_token_constant ) : ?>
				<div class="notice notice-success inline"><p><strong><?php esc_html_e( 'Auth Token:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Set via WP_MEDIA_WORKER_TOKEN constant.', 'mcp-ai-wpoos-pro' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $insecure_url ) : ?>
				<div class="notice notice-error inline"><p><strong><?php esc_html_e( 'Insecure URL:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'The sidecar URL uses plain HTTP while this site uses HTTPS. The auth token would be sent in cleartext — use an HTTPS URL (e.g. a Cloudways Velocity app URL).', 'mcp-ai-wpoos-pro' ); ?></p></div>
			<?php endif; ?>
			<div class="card" style="max-width:800px;margin-bottom:20px;">
				<h2><?php esc_html_e( 'Connection Status', 'mcp-ai-wpoos-pro' ); ?></h2>
				<?php if ( $health && isset( $health['status'] ) && 'ok' === $health['status'] ) : ?>
					<p><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#46b450;"></span> <strong style="color:#46b450;"><?php esc_html_e( 'Connected', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php echo esc_html( isset( $health['version'] ) ? 'v' . $health['version'] : '' ); ?></p>
					<?php if ( isset( $health['capabilities'] ) ) : ?>
						<h3><?php esc_html_e( 'Document Pipeline', 'mcp-ai-wpoos-pro' ); ?></h3>
						<?php $this->render_capabilities( $health['capabilities'] ); ?>
					<?php endif; ?>
				<?php elseif ( $health && isset( $health['error'] ) ) : ?>
					<p><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#dc3232;"></span> <strong style="color:#dc3232;"><?php esc_html_e( 'Connection Failed', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php echo esc_html( $health['error'] ); ?></p>
				<?php elseif ( ! empty( $url ) ) : ?>
					<p><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#ffb900;"></span> <strong style="color:#ffb900;"><?php esc_html_e( 'Unknown', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Click Test Connection to verify.', 'mcp-ai-wpoos-pro' ); ?></p>
				<?php else : ?>
					<p><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#ccc;"></span> <strong style="color:#666;"><?php esc_html_e( 'Not Configured', 'mcp-ai-wpoos-pro' ); ?></strong></p>
				<?php endif; ?>
				<p><button type="button" class="button button-secondary" id="wp-mcp-ai-test-media-worker"><?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos-pro' ); ?></button> <span id="wp-mcp-ai-test-result" style="margin-left:10px;"></span></p>
			</div>
			<div class="card" style="max-width:800px;">
				<h2><?php esc_html_e( 'Settings', 'mcp-ai-wpoos-pro' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wp_mcp_ai_media_worker_settings', 'wp_mcp_ai_media_worker_nonce' ); ?>
					<input type="hidden" name="action" value="wp_mcp_ai_save_media_worker_settings">
					<table class="form-table">
						<tr><th><label for="mw_url"><?php esc_html_e( 'Sidecar URL', 'mcp-ai-wpoos-pro' ); ?></label></th>
							<td><input type="url" id="mw_url" name="wp_mcp_ai_media_worker_url" value="<?php echo esc_attr( $url ); ?>" class="regular-text" placeholder="http://media-worker:3100" <?php echo $has_constant ? 'readonly style="background:#f0f0f0;"' : ''; ?>>
							<p class="description"><?php echo $has_constant ? esc_html__( 'Set by WP_MEDIA_WORKER_URL constant.', 'mcp-ai-wpoos-pro' ) : esc_html__( 'Base URL of the Media Worker sidecar.', 'mcp-ai-wpoos-pro' ); ?></p></td></tr>
						<tr><th><label for="mw_token"><?php esc_html_e( 'Auth Token', 'mcp-ai-wpoos-pro' ); ?></label></th>
							<td><input type="text" id="mw_token" name="wp_mcp_ai_media_worker_token" value="<?php echo esc_attr( $token ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Optional shared secret', 'mcp-ai-wpoos-pro' ); ?>" <?php echo $has_token_constant ? 'readonly style="background:#f0f0f0;"' : ''; ?>>
							<p class="description"><?php echo $has_token_constant ? esc_html__( 'Set by WP_MEDIA_WORKER_TOKEN constant.', 'mcp-ai-wpoos-pro' ) : esc_html__( 'Optional. Leave blank to auto-generate from WordPress salts. Must match the worker\'s WORKER_API_TOKEN environment variable.', 'mcp-ai-wpoos-pro' ); ?></p></td></tr>
					</table>
					<?php
					if ( ! $has_constant ) :
						?>
						<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'mcp-ai-wpoos-pro' ); ?></button></p><?php endif; ?>
				</form>
			</div>
			<div class="card" style="max-width:800px;">
				<h2><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h2>
				<ul>
					<li><a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/project/proposals/media-worker-sidecar-proposal.md" target="_blank">Sidecar Architecture &amp; Implementation Report</a> — full endpoint map, service cascade, test results</li>
					<li><a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/operations/deployment/media-worker-docker-setup.md" target="_blank">Docker Setup Guide</a> — WSL2 commands, troubleshooting, architecture diagram</li>
					<li><a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/operations/deployment/media-worker-velocity-setup.md" target="_blank">Cloudways Velocity Setup Guide</a> — managed Node.js deployment, security hardening, ops runbook</li>
				</ul>
			</div>
		</div>
		<script>jQuery(function($){ $('#wp-mcp-ai-test-media-worker').on('click',function(){ var b=$(this),r=$('#wp-mcp-ai-test-result');b.prop('disabled',true).text('Testing...');r.html('');$.post(ajaxurl,{action:'wp_mcp_ai_test_media_worker',_wpnonce:'<?php echo esc_js( wp_create_nonce( 'wp_mcp_ai_media_worker_test' ) ); ?>'},function(d){b.prop('disabled',false).text('Test Connection');r.html(d.success?'<span style="color:#46b450;">\u2705 '+d.data.message+'</span>':'<span style="color:#dc3232;">\u274c '+(d.data&&d.data.message?d.data.message:"Connection failed.")+'</span>');if(d.success)setTimeout(function(){location.reload();},1500);}).fail(function(){b.prop('disabled',false).text('Test Connection');r.html('<span style="color:#dc3232;">\u274c AJAX failed.</span>');});});});</script>
		<?php
	}

	/**
	 * Render capabilities table.
	 *
	 * @param array $caps Health-check capabilities data.
	 */
	private function render_capabilities( $caps ) {
		$rows = array(
			'pdf_extraction'     => 'PDF Extraction',
			'pdf_generation'     => 'PDF Generation',
			'pdf_rendering'      => 'PDF Rendering',
			'document_excel'     => 'Excel',
			'document_word'      => 'Word',
			'document_ocr'       => 'OCR',
			'code_formatting'    => 'Code Formatting',
			'email'              => 'Email (Nodemailer + MJML)',
			'email_parsing'      => 'Email Parsing',
			'translation'        => 'Translation',
			'language_detection' => 'Language Detection',
			'phone_formatting'   => 'Phone Formatting',
			'qrcode'             => 'QR Code',
			'math_rendering'     => 'Math (KaTeX)',
			'math_eval'          => 'Math Evaluation',
			'calendar_ics'       => 'Calendar ICS',
			'chart_rendering'    => 'Charts (Chart.js)',
			'geospatial'         => 'Geospatial (Turf)',
			'csv_processing'     => 'CSV Processing',
			'markdown'           => 'Markdown',
			'regression'         => 'Regression',
			'currency'           => 'Currency',
			'validation'         => 'Validation',
			'browser_automation' => 'Browser',
			'image_optimization' => 'Image Optimization',
			'video_processing'   => 'Video Processing',
		);
		echo '<table class="widefat striped" style="max-width:600px;"><thead><tr><th>Capability</th><th>Status</th></tr></thead><tbody>';
		foreach ( $rows as $k => $l ) {
			$a = isset( $caps[ $k ] ) && $caps[ $k ];
			echo '<tr><td>' . esc_html( $l ) . '</td><td>' . ( $a ? '<span style="color:#46b450;">Available</span>' : '<span style="color:#ccc;">Unavailable</span>' ) . '</td></tr>'; }
		echo '</tbody></table>';
	}

	/**
	 * Save settings from the form POST.
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied.' );
		}
		check_admin_referer( 'wp_mcp_ai_media_worker_settings', 'wp_mcp_ai_media_worker_nonce' );
		if ( ! defined( 'WP_MEDIA_WORKER_URL' ) ) {
			$u = isset( $_POST['wp_mcp_ai_media_worker_url'] ) ? esc_url_raw( wp_unslash( $_POST['wp_mcp_ai_media_worker_url'] ) ) : '';
			update_option( self::OPTION_URL, $u );
		}
		if ( ! defined( 'WP_MEDIA_WORKER_TOKEN' ) ) {
			$t = isset( $_POST['wp_mcp_ai_media_worker_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_media_worker_token'] ) ) : '';
			update_option( self::OPTION_TOKEN, $t );
		}
		delete_transient( self::HEALTH_TRANSIENT );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => 'wp-mcp-ai-media-worker',
					'saved' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * AJAX handler: Test the sidecar connection.
	 */
	public function ajax_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}
		check_ajax_referer( 'wp_mcp_ai_media_worker_test', '_wpnonce' );
		$url = $this->get_url();
		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => 'Sidecar URL not configured.' ) );
		}
		$r = wp_remote_get( rtrim( $url, '/' ) . '/api/health', array( 'timeout' => 5 ) );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => 'Connection failed: ' . $r->get_error_message() ) );
		}
		$s = wp_remote_retrieve_response_code( $r );
		$b = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( 200 === $s && isset( $b['status'] ) && 'ok' === $b['status'] ) {
			set_transient( self::HEALTH_TRANSIENT, $b, self::HEALTH_CACHE_TTL );
			wp_send_json_success( array( 'message' => 'Connected! v' . ( isset( $b['version'] ) ? $b['version'] : '?' ) ) );
		}
		wp_send_json_error( array( 'message' => isset( $b['error'] ) ? $b['error'] : 'HTTP ' . $s ) );
	}

	/**
	 * Get the sidecar URL from constant or option.
	 *
	 * @return string
	 */
	private function get_url() {
		if ( defined( 'WP_MEDIA_WORKER_URL' ) && WP_MEDIA_WORKER_URL ) {
			return rtrim( WP_MEDIA_WORKER_URL, '/' );
		}
		$u = get_option( self::OPTION_URL, '' );
		return $u ? rtrim( $u, '/' ) : '';
	}

	/**
	 * Get cached health check result.
	 *
	 * @return array|null
	 */
	private function get_cached_health() {
		$c = get_transient( self::HEALTH_TRANSIENT );
		if ( false !== $c ) {
			return $c;
		}
		$url = $this->get_url();
		if ( empty( $url ) ) {
			return null;
		}
		$r = wp_remote_get( rtrim( $url, '/' ) . '/api/health', array( 'timeout' => 3 ) );
		if ( is_wp_error( $r ) ) {
			$d = array( 'error' => $r->get_error_message() );
			set_transient( self::HEALTH_TRANSIENT, $d, self::HEALTH_CACHE_TTL );
			return $d;
		}
		$s = wp_remote_retrieve_response_code( $r );
		$b = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( 200 !== $s || ! is_array( $b ) ) {
			$d = array( 'error' => 'HTTP ' . $s . ': Unexpected response.' );
			set_transient( self::HEALTH_TRANSIENT, $d, self::HEALTH_CACHE_TTL );
			return $d;
		}
		set_transient( self::HEALTH_TRANSIENT, $b, self::HEALTH_CACHE_TTL );
		return $b;
	}
}
new WP_MCP_AI_Media_Worker_Settings();
