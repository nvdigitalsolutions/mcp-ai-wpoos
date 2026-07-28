<?php
/**
 * Maintenance Banner
 *
 * Renders a dismissible frontend banner during active maintenance windows.
 * Shows the maintenance message, affected services, and a countdown timer.
 *
 * Auto-injected via wp_footer when an active window exists, or placed
 * manually via [nvoos_maintenance_banner] shortcode.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Maintenance_Banner' ) ) {
	/**
	 * Maintenance Banner class.
	 *
	 * @since 1.3.0
	 */
	class WP_MCP_AI_Maintenance_Banner {

		/**
		 * Cookie name for dismissal.
		 *
		 * @since 1.3.0
		 * @var string
		 */
		const DISMISS_COOKIE = 'nvoos_maint_dismiss';

		/**
		 * Initialize hooks.
		 *
		 * @since 1.3.0
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'wp_footer', array( __CLASS__, 'maybe_render_banner' ), 999 );
			add_shortcode( 'nvoos_maintenance_banner', array( __CLASS__, 'render_shortcode' ) );
		}

		/**
		 * Render the banner if a maintenance window is active.
		 *
		 * @since 1.3.0
		 *
		 * @return void
		 */
		public static function maybe_render_banner(): void {
			if ( ! class_exists( 'WP_MCP_AI_Maintenance_CPT' ) ) {
				return;
			}

			$active = WP_MCP_AI_Maintenance_CPT::get_active_window();
			if ( ! $active ) {
				return;
			}

			// Check banner enabled flag.
			$banner_enabled = (bool) get_post_meta( $active->ID, '_mcp_ai_maintenance_banner_enabled', true );
			if ( ! $banner_enabled ) {
				return;
			}

			// Check if user dismissed this window.
			if ( self::is_dismissed( $active->ID ) ) {
				return;
			}

			self::render( $active );
		}

		/**
		 * Shortcode callback.
		 *
		 * @since 1.3.0
		 *
		 * @param array  $atts    Shortcode attributes (unused).
		 * @param string $content Enclosed content (unused).
		 * @return string
		 */
		public static function render_shortcode( $atts, string $content = '' ): string {
			unset( $atts, $content );

			if ( ! class_exists( 'WP_MCP_AI_Maintenance_CPT' ) ) {
				return '';
			}

			$active = WP_MCP_AI_Maintenance_CPT::get_active_window();
			if ( ! $active ) {
				return '';
			}

			ob_start();
			self::render( $active );
			return ob_get_clean();
		}

		/**
		 * Render the banner HTML.
		 *
		 * @since 1.3.0
		 *
		 * @param WP_Post $window Maintenance window post.
		 * @return void
		 */
		private static function render( WP_Post $window ): void {
			$end      = get_post_meta( $window->ID, '_mcp_ai_maintenance_end', true );
			$end_ts   = strtotime( $end );
			$services = get_post_meta( $window->ID, '_mcp_ai_maintenance_services', true );
			$services = is_array( $services ) ? $services : array();

			// Compute initial seconds remaining for countdown.
			$seconds_remaining = $end_ts ? max( 0, $end_ts - time() ) : 0;

			?>
			<div
				id="nvoos-maintenance-banner"
				class="nvoos-maintenance-banner"
				data-window-id="<?php echo esc_attr( (string) $window->ID ); ?>"
				data-end-timestamp="<?php echo esc_attr( (string) $end_ts ); ?>"
				data-seconds-remaining="<?php echo esc_attr( (string) $seconds_remaining ); ?>"
				role="alert"
			>
				<div class="nvoos-maintenance-banner-inner">
					<div class="nvoos-maintenance-banner-icon">&#9881;</div>
					<div class="nvoos-maintenance-banner-content">
						<strong class="nvoos-maintenance-banner-title">
							<?php echo esc_html( $window->post_title ); ?>
						</strong>
						<?php if ( ! empty( $window->post_content ) ) : ?>
							<p class="nvoos-maintenance-banner-message">
								<?php echo wp_kses_post( $window->post_content ); ?>
							</p>
						<?php endif; ?>
						<?php if ( ! empty( $services ) ) : ?>
							<p class="nvoos-maintenance-banner-services">
								<?php
								printf(
									/* translators: %s: comma-separated list of affected services */
									esc_html__( 'Affected services: %s', 'mcp-ai-wpoos-pro' ),
									esc_html( implode( ', ', $services ) )
								);
								?>
							</p>
						<?php endif; ?>
						<p class="nvoos-maintenance-banner-countdown">
							<?php if ( $seconds_remaining > 0 ) : ?>
								<span class="nvoos-maintenance-countdown-label">
									<?php esc_html_e( 'Estimated time remaining:', 'mcp-ai-wpoos-pro' ); ?>
								</span>
								<span class="nvoos-maintenance-countdown-timer" id="nvoos-maint-countdown">
									<?php echo esc_html( self::format_duration( $seconds_remaining ) ); ?>
								</span>
							<?php else : ?>
								<?php esc_html_e( 'Maintenance in progress. Please check back shortly.', 'mcp-ai-wpoos-pro' ); ?>
							<?php endif; ?>
						</p>
					</div>
					<button
						type="button"
						class="nvoos-maintenance-banner-dismiss"
						aria-label="<?php esc_attr_e( 'Dismiss', 'mcp-ai-wpoos-pro' ); ?>"
						onclick="document.getElementById('nvoos-maintenance-banner').style.display='none';document.cookie='<?php echo esc_js( self::DISMISS_COOKIE ); ?>=<?php echo esc_js( (string) $window->ID ); ?>;path=/;max-age=86400';"
					>
						&times;
					</button>
				</div>
			</div>
			<?php

			self::render_inline_styles();
			self::render_inline_script( $window->ID, $end_ts );
		}

		/**
		 * Check if the current user dismissed this window.
		 *
		 * @since 1.3.0
		 *
		 * @param int $window_id Window post ID.
		 * @return bool
		 */
		private static function is_dismissed( int $window_id ): bool {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie comparison only; value is cast for safety.
			$cookie = isset( $_COOKIE[ self::DISMISS_COOKIE ] ) ? wp_unslash( $_COOKIE[ self::DISMISS_COOKIE ] ) : '';
			return (string) $window_id === (string) $cookie;
		}

		/**
		 * Format seconds into a human-readable duration.
		 *
		 * @since 1.3.0
		 *
		 * @param int $seconds Total seconds.
		 * @return string
		 */
		private static function format_duration( int $seconds ): string {
			$hours   = floor( $seconds / 3600 );
			$minutes = floor( ( $seconds % 3600 ) / 60 );
			$secs    = $seconds % 60;

			if ( $hours > 0 ) {
				return sprintf(
					/* translators: 1: hours, 2: minutes */
					__( '%1$dh %2$dm', 'mcp-ai-wpoos-pro' ),
					$hours,
					$minutes
				);
			}

			if ( $minutes > 0 ) {
				return sprintf(
					/* translators: 1: minutes, 2: seconds */
					__( '%1$dm %2$ds', 'mcp-ai-wpoos-pro' ),
					$minutes,
					$secs
				);
			}

			return sprintf(
				/* translators: %d: seconds */
				__( '%ds', 'mcp-ai-wpoos-pro' ),
				$secs
			);
		}

		/**
		 * Render minimal inline styles.
		 *
		 * @since 1.3.0
		 *
		 * @return void
		 */
		private static function render_inline_styles(): void {
			?>
			<style>
			.nvoos-maintenance-banner {
				position: fixed;
				bottom: 0;
				left: 0;
				right: 0;
				z-index: 99999;
				background: #1a1a2e;
				color: #e0e0e0;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
				font-size: 14px;
				box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.3);
			}
			.nvoos-maintenance-banner-inner {
				display: flex;
				align-items: flex-start;
				gap: 14px;
				max-width: 960px;
				margin: 0 auto;
				padding: 14px 20px;
			}
			.nvoos-maintenance-banner-icon {
				font-size: 22px;
				flex-shrink: 0;
				margin-top: 2px;
			}
			.nvoos-maintenance-banner-content {
				flex: 1;
			}
			.nvoos-maintenance-banner-title {
				font-size: 15px;
				color: #ffc107;
			}
			.nvoos-maintenance-banner-message {
				margin: 4px 0 6px;
				font-size: 13px;
				line-height: 1.5;
				color: #ccc;
			}
			.nvoos-maintenance-banner-services {
				font-size: 12px;
				color: #999;
				margin: 0 0 6px;
			}
			.nvoos-maintenance-banner-countdown {
				font-size: 13px;
				color: #aaa;
				margin: 0;
			}
			.nvoos-maintenance-countdown-timer {
				font-weight: 700;
				color: #ffc107;
			}
			.nvoos-maintenance-banner-dismiss {
				background: none;
				border: none;
				color: #888;
				font-size: 22px;
				line-height: 1;
				cursor: pointer;
				padding: 0 4px;
				flex-shrink: 0;
				margin-top: -2px;
			}
			.nvoos-maintenance-banner-dismiss:hover {
				color: #fff;
			}
			</style>
			<?php
		}

		/**
		 * Render inline countdown script.
		 *
		 * @since 1.3.0
		 *
		 * @param int       $window_id Window post ID.
		 * @param int|false $end_ts    End timestamp or false.
		 * @return void
		 */
		private static function render_inline_script( int $window_id, $end_ts ): void {
			if ( ! $end_ts ) {
				return;
			}
			?>
			<script>
			(function() {
				var endTimestamp = <?php echo absint( $end_ts ); ?>;
				var timerEl = document.getElementById('nvoos-maint-countdown');
				if (!timerEl || !endTimestamp) return;

				function updateCountdown() {
					var remaining = Math.max(0, endTimestamp - Math.floor(Date.now() / 1000));
					if (remaining <= 0) {
						timerEl.textContent = '<?php echo esc_js( __( 'Finishing soon...', 'mcp-ai-wpoos-pro' ) ); ?>';
						return;
					}
					var h = Math.floor(remaining / 3600);
					var m = Math.floor((remaining % 3600) / 60);
					var s = remaining % 60;
					timerEl.textContent = h > 0
						? h + 'h ' + m + 'm'
						: (m > 0 ? m + 'm ' + s + 's' : s + 's');
				}

				updateCountdown();
				setInterval(updateCountdown, 1000);
			})();
			</script>
			<?php
		}
	}

	// Bootstrap.
	WP_MCP_AI_Maintenance_Banner::init();
}
