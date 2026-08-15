<?php
/**
 * Analytics Service Status Admin Page
 *
 * Standalone admin dashboard for the Shared Analytics Service. Shows adapter
 * status, cache health, rate limit consumption, and manual cache invalidation
 * across all 7 platform adapters.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.7.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license  Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics Service status page.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Analytics_Service_Page {

	/**
	 * Admin page slug.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const PAGE_SLUG = 'nvoos-analytics-service';

	/**
	 * Constructor — registers hooks.
	 *
	 * @since 1.7.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 30 );
	}

	/**
	 * Register the admin page under NV oOS Pro.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function register_page() {
		add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'Analytics Service', 'mcp-ai-wpoos-pro' ),
			__( 'Analytics Service', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the analytics service status page.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function render_page() {
		$service_loaded = class_exists( 'WP_MCP_AI_Analytics_Service' );

		?>
		<div class="wrap">
			<h1>
				<span class="dashicons dashicons-chart-area" style="font-size:28px;vertical-align:middle;"></span>
				<?php esc_html_e( 'Shared Analytics Service', 'mcp-ai-wpoos-pro' ); ?>
			</h1>
			<p class="description">
				<?php esc_html_e( 'Status dashboard for the shared analytics infrastructure used by all pro-toolkits.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<?php if ( ! $service_loaded ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'The Shared Analytics Service is not loaded. Ensure the Pro addon is active and up to date.', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>

			<?php $this->render_adapter_dashboard(); ?>
			<?php $this->render_cache_dashboard(); ?>
			<?php $this->render_rate_limit_dashboard(); ?>
			<?php $this->render_cache_actions(); ?>
		</div>

		<style>
			.analytics-service-grid {
				display: grid;
				grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
				gap: 16px;
				margin: 20px 0;
			}
			.analytics-service-card {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				padding: 16px;
				box-shadow: 0 1px 2px rgba(0,0,0,0.04);
			}
			.analytics-service-card h3 {
				margin: 0 0 12px 0;
				font-size: 14px;
			}
			.analytics-service-card table {
				width: 100%;
				border-collapse: collapse;
			}
			.analytics-service-card table th,
			.analytics-service-card table td {
				padding: 6px 8px;
				text-align: left;
				border-bottom: 1px solid #f0f0f1;
				font-size: 13px;
			}
			.analytics-service-card .status-dot {
				display: inline-block;
				width: 10px;
				height: 10px;
				border-radius: 50%;
				margin-right: 6px;
			}
			.status-dot.green { background: #46b450; }
			.status-dot.orange { background: #f0ad4e; }
			.status-dot.red { background: #dc3232; }
			.status-dot.gray { background: #a0a5aa; }
		</style>
		<?php
	}

	/**
	 * Render the adapter status dashboard.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	private function render_adapter_dashboard() {
		$service  = WP_MCP_AI_Analytics_Service::instance();
		$adapters = array(
			'meta'             => __( 'Meta (Facebook + Instagram)', 'mcp-ai-wpoos-pro' ),
			'twitter'          => __( 'Twitter / X', 'mcp-ai-wpoos-pro' ),
			'linkedin'         => __( 'LinkedIn', 'mcp-ai-wpoos-pro' ),
			'tiktok'           => __( 'TikTok', 'mcp-ai-wpoos-pro' ),
			'woocommerce'      => __( 'WooCommerce', 'mcp-ai-wpoos-pro' ),
			'google_analytics' => __( 'Google Analytics (GA4)', 'mcp-ai-wpoos-pro' ),
			'cloudways'        => __( 'Cloudways', 'mcp-ai-wpoos-pro' ),
		);

		$connected = $service->get_connected_platforms();

		?>
		<h2><?php esc_html_e( 'Adapter Status', 'mcp-ai-wpoos-pro' ); ?></h2>
		<div class="analytics-service-grid">
			<?php foreach ( $adapters as $slug => $label ) : ?>
				<div class="analytics-service-card">
					<h3><?php echo esc_html( $label ); ?></h3>
					<?php
					$adapter    = $service->get_adapter( $slug );
					$configured = $adapter && $adapter->is_configured();
					$dot_class  = $configured ? 'green' : 'gray';
					$status     = $configured ? __( 'Configured', 'mcp-ai-wpoos-pro' ) : __( 'Not Configured', 'mcp-ai-wpoos-pro' );
					?>
					<p>
						<span class="status-dot <?php echo esc_attr( $dot_class ); ?>"></span>
						<strong><?php echo esc_html( $status ); ?></strong>
					</p>
					<?php if ( $configured && $adapter ) : ?>
						<?php
						$remaining = $adapter->get_rate_limit_remaining();
						if ( null !== $remaining ) :
							?>
							<p style="font-size:12px;color:#555;">
								<?php
								printf(
									/* translators: %d: remaining API requests */
									esc_html__( '~%d API requests remaining', 'mcp-ai-wpoos-pro' ),
									absint( $remaining )
								);
								?>
							</p>
						<?php endif; ?>
					<?php else : ?>
						<p style="font-size:12px;color:#888;">
							<?php esc_html_e( 'Configure credentials in NV oOS → Social Media Settings or Pro Integrations.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render the cache health dashboard.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	private function render_cache_dashboard() {
		if ( ! class_exists( 'WP_MCP_AI_Analytics_Cache' ) ) {
			return;
		}

		$cache = WP_MCP_AI_Analytics_Cache::instance();
		$stats = $cache->get_stats();

		$hit_rate = isset( $stats['hit_rate'] ) ? $stats['hit_rate'] : 0;
		$hits     = isset( $stats['hits'] ) ? $stats['hits'] : 0;
		$misses   = isset( $stats['misses'] ) ? $stats['misses'] : 0;
		$sets     = isset( $stats['sets'] ) ? $stats['sets'] : 0;

		$rate_color = $hit_rate > 70 ? '#46b450' : ( $hit_rate > 30 ? '#f0ad4e' : '#dc3232' );

		?>
		<h2><?php esc_html_e( 'Cache Health', 'mcp-ai-wpoos-pro' ); ?></h2>
		<div class="analytics-service-grid">
			<div class="analytics-service-card">
				<h3><?php esc_html_e( 'Cache Hit Rate', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p style="font-size:28px;font-weight:700;color:<?php echo esc_attr( $rate_color ); ?>;">
					<?php echo esc_html( $hit_rate ); ?>%
				</p>
			</div>
			<div class="analytics-service-card">
				<h3><?php esc_html_e( 'Cache Operations', 'mcp-ai-wpoos-pro' ); ?></h3>
				<table>
					<tr>
						<th><?php esc_html_e( 'Hits', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( $hits ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Misses', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( $misses ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Sets', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( $sets ); ?></td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the rate limit consumption dashboard.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	private function render_rate_limit_dashboard() {
		if ( ! class_exists( 'WP_MCP_AI_Analytics_Rate_Limiter' ) ) {
			return;
		}

		$limiter   = WP_MCP_AI_Analytics_Rate_Limiter::instance();
		$platforms = array( 'meta', 'twitter', 'linkedin', 'tiktok' );

		?>
		<h2><?php esc_html_e( 'Rate Limits', 'mcp-ai-wpoos-pro' ); ?></h2>
		<div class="analytics-service-grid">
			<?php foreach ( $platforms as $p ) : ?>
				<?php
				$pct    = $limiter->get_usage_pct( $p );
				$config = $limiter->get_limit_config( $p );
				$limit  = $config['limit'];
				$window = $config['window'];
				$hours  = round( $window / 3600, 1 );
				$color  = $pct >= 90 ? '#dc3232' : ( $pct >= 70 ? '#f0ad4e' : '#46b450' );

				$labels = array(
					'meta'     => __( 'Meta (FB/IG)', 'mcp-ai-wpoos-pro' ),
					'twitter'  => __( 'Twitter/X', 'mcp-ai-wpoos-pro' ),
					'linkedin' => __( 'LinkedIn', 'mcp-ai-wpoos-pro' ),
					'tiktok'   => __( 'TikTok', 'mcp-ai-wpoos-pro' ),
				);
				$name   = isset( $labels[ $p ] ) ? $labels[ $p ] : ucfirst( $p );
				?>
				<div class="analytics-service-card">
					<h3><?php echo esc_html( $name ); ?></h3>
					<div style="background:#f0f0f1;border-radius:4px;height:20px;margin:8px 0;overflow:hidden;">
						<div style="background:<?php echo esc_attr( $color ); ?>;height:100%;width:<?php echo esc_attr( min( $pct, 100 ) ); ?>%;transition:width 0.3s;"></div>
					</div>
					<p style="font-size:12px;margin:0;">
						<?php
						printf(
							/* translators: 1: usage percentage, 2: request limit, 3: hours */
							esc_html__( '%1$s%% of %2$d requests per %3$gh', 'mcp-ai-wpoos-pro' ),
							esc_html( $pct ),
							absint( $limit ),
							esc_html( $hours )
						);
						?>
					</p>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render manual cache invalidation actions.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	private function render_cache_actions() {
		if ( ! class_exists( 'WP_MCP_AI_Analytics_Cache' ) ) {
			return;
		}

		$cleared = false;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['clear_analytics_cache'] ) && current_user_can( 'manage_options' ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$platform = sanitize_text_field( wp_unslash( $_GET['clear_analytics_cache'] ) );
			$service  = WP_MCP_AI_Analytics_Service::instance();
			$service->invalidate_cache( $platform );
			$cleared = true;
		}

		?>
		<h2><?php esc_html_e( 'Cache Management', 'mcp-ai-wpoos-pro' ); ?></h2>

		<?php if ( $cleared ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Cache cleared successfully.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="analytics-service-card" style="max-width:600px;">
			<h3><?php esc_html_e( 'Invalidate Cache by Platform', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p class="description" style="margin-bottom:12px;">
				<?php esc_html_e( 'Clear cached analytics data for a specific platform. New data will be fetched on the next analytics request.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<?php
			$platforms = array( 'meta', 'twitter', 'linkedin', 'tiktok', 'facebook', 'instagram', 'woocommerce', 'google_analytics', 'cloudways' );
			$labels    = array(
				'meta'             => __( 'Meta (All)', 'mcp-ai-wpoos-pro' ),
				'facebook'         => __( 'Facebook', 'mcp-ai-wpoos-pro' ),
				'instagram'        => __( 'Instagram', 'mcp-ai-wpoos-pro' ),
				'twitter'          => __( 'Twitter/X', 'mcp-ai-wpoos-pro' ),
				'linkedin'         => __( 'LinkedIn', 'mcp-ai-wpoos-pro' ),
				'tiktok'           => __( 'TikTok', 'mcp-ai-wpoos-pro' ),
				'woocommerce'      => __( 'WooCommerce', 'mcp-ai-wpoos-pro' ),
				'google_analytics' => __( 'Google Analytics', 'mcp-ai-wpoos-pro' ),
				'cloudways'        => __( 'Cloudways', 'mcp-ai-wpoos-pro' ),
			);

			foreach ( $platforms as $p ) :
				$label = isset( $labels[ $p ] ) ? $labels[ $p ] : ucfirst( $p );
				$url   = add_query_arg(
					array(
						'page'                  => self::PAGE_SLUG,
						'clear_analytics_cache' => $p,
						'_wpnonce'              => wp_create_nonce( 'analytics_clear_cache_' . $p ),
					),
					admin_url( 'admin.php' )
				);
				?>
				<a href="<?php echo esc_url( $url ); ?>" class="button button-small" style="margin:2px 4px 4px 0;">
					<?php
					printf(
						/* translators: %s: platform name */
						esc_html__( 'Clear %s', 'mcp-ai-wpoos-pro' ),
						esc_html( $label )
					);
					?>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
