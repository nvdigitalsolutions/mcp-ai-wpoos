<?php
/**
 * Model Pricing Checker - Monthly cron job to check for pricing updates.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages monthly pricing checks for AI models.
 */
class WP_MCP_AI_Model_Pricing_Checker {
	const CRON_HOOK            = 'wp_mcp_ai_check_model_pricing';
	const OPTION_LAST_CHECK    = 'wp_mcp_ai_last_pricing_check';
	const OPTION_PRICE_CHANGES = 'wp_mcp_ai_price_changes';

	/**
	 * Bootstrap the pricing checker.
	 */
	public static function bootstrap() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'check_pricing' ) );
		// Register admin_notices on init to avoid early translation loading (WordPress 6.7.0+).
		add_action( 'init', array( __CLASS__, 'register_admin_notices' ) );
		add_action( 'wp_ajax_wp_mcp_ai_dismiss_price_notice', array( __CLASS__, 'dismiss_price_notice' ) );

		// Schedule cron job if not already scheduled.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'monthly', self::CRON_HOOK );
		}
	}

	/**
	 * Register admin notices on init action.
	 *
	 * WordPress 6.7.0+ requires translations to be loaded at init or later.
	 */
	public static function register_admin_notices() {
		add_action( 'admin_notices', array( __CLASS__, 'show_price_change_notice' ) );
	}

	/**
	 * Check current pricing from the CCT and log any changes.
	 */
	public static function check_pricing() {
		if ( ! class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
			return;
		}

		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$current_models = $method->invoke( null );

		// Get stored pricing data from previous check.
		$previous_pricing = get_option( self::OPTION_LAST_CHECK, array() );
		$price_changes    = array();

		foreach ( $current_models as $model ) {
			if ( ! isset( $model['model_name'] ) ) {
				continue;
			}

			$model_name  = $model['model_name'];
			$input_cost  = isset( $model['cost_per_1k_input_tokens'] ) ? $model['cost_per_1k_input_tokens'] : 0;
			$output_cost = isset( $model['cost_per_1k_output_tokens'] ) ? $model['cost_per_1k_output_tokens'] : 0;

			// Check if we have previous data for this model.
			if ( isset( $previous_pricing[ $model_name ] ) ) {
				$prev_input  = $previous_pricing[ $model_name ]['input'];
				$prev_output = $previous_pricing[ $model_name ]['output'];

				// Detect price changes.
				if ( $prev_input != $input_cost || $prev_output != $output_cost ) {
					$price_changes[] = array(
						'model'       => $model_name,
						'provider'    => isset( $model['provider'] ) ? $model['provider'] : 'unknown',
						'old_input'   => $prev_input,
						'new_input'   => $input_cost,
						'old_output'  => $prev_output,
						'new_output'  => $output_cost,
						'detected_at' => current_time( 'mysql' ),
					);
				}
			}

			// Update stored pricing.
			$previous_pricing[ $model_name ] = array(
				'input'  => $input_cost,
				'output' => $output_cost,
			);
		}

		// Save updated pricing data.
		update_option( self::OPTION_LAST_CHECK, $previous_pricing );

		// If there are price changes, store them for admin notification.
		if ( ! empty( $price_changes ) ) {
			$existing_changes = get_option( self::OPTION_PRICE_CHANGES, array() );
			$all_changes      = array_merge( $existing_changes, $price_changes );
			update_option( self::OPTION_PRICE_CHANGES, $all_changes );

			// Log the event.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'model_pricing_changed',
					sprintf( 'Detected %d pricing changes for AI models.', count( $price_changes ) ),
					array( 'changes' => $price_changes )
				);
			}
		}
	}

	/**
	 * Show admin notice if there are price changes.
	 */
	public static function show_price_change_notice() {
		$price_changes = get_option( self::OPTION_PRICE_CHANGES, array() );

		if ( empty( $price_changes ) ) {
			return;
		}

		// Check if user has dismissed this notice.
		$dismissed = get_user_meta( get_current_user_id(), 'wp_mcp_ai_dismissed_price_notice', true );
		if ( $dismissed && $dismissed >= count( $price_changes ) ) {
			return;
		}

		?>
		<div class="notice notice-warning is-dismissible wp-mcp-ai-price-notice">
			<p>
				<strong><?php esc_html_e( 'WP oOS: AI Model Pricing Updates Detected', 'wp-mcp-ai' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: %d: number of pricing changes */
					esc_html__( '%d AI model pricing changes have been detected. Please review the Model Rate Limits CCT to update your cost estimates.', 'wp-mcp-ai' ),
					count( $price_changes )
				);
				?>
			</p>
			<ul>
				<?php foreach ( array_slice( $price_changes, -5 ) as $change ) : ?>
					<li>
						<strong><?php echo esc_html( $change['model'] ); ?></strong> (<?php echo esc_html( $change['provider'] ); ?>):
						Input: $<?php echo esc_html( number_format( $change['old_input'], 4 ) ); ?> → $<?php echo esc_html( number_format( $change['new_input'], 4 ) ); ?> per 1K,
						Output: $<?php echo esc_html( number_format( $change['old_output'], 4 ) ); ?> → $<?php echo esc_html( number_format( $change['new_output'], 4 ) ); ?> per 1K
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( count( $price_changes ) > 5 ) : ?>
				<p><em><?php esc_html_e( '(Showing last 5 changes)', 'wp-mcp-ai' ); ?></em></p>
			<?php endif; ?>
		</div>
		<script>
		jQuery(document).ready(function($) {
			$('.wp-mcp-ai-price-notice').on('click', '.notice-dismiss', function() {
				$.post(ajaxurl, {
					action: 'wp_mcp_ai_dismiss_price_notice',
					nonce: '<?php echo esc_js( wp_create_nonce( 'wp_mcp_ai_dismiss_price_notice' ) ); ?>',
					count: <?php echo count( $price_changes ); ?>
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Handle AJAX request to dismiss price notice.
	 */
	public static function dismiss_price_notice() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_dismiss_price_notice', 'nonce' );

		// Check if user is logged in.
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in to dismiss notices.', 'wp-mcp-ai' ) ) );
			return;
		}

		$count = isset( $_POST['count'] ) ? absint( $_POST['count'] ) : 0;
		update_user_meta( get_current_user_id(), 'wp_mcp_ai_dismissed_price_notice', $count );
		wp_send_json_success();
	}

	/**
	 * Clear price change notices (admin utility).
	 */
	public static function clear_price_changes() {
		delete_option( self::OPTION_PRICE_CHANGES );
		return true;
	}

	/**
	 * Get current price changes.
	 *
	 * @return array
	 */
	public static function get_price_changes() {
		return get_option( self::OPTION_PRICE_CHANGES, array() );
	}

	/**
	 * Manually trigger a pricing check (for testing/admin use).
	 */
	public static function trigger_check() {
		self::check_pricing();
	}
}

WP_MCP_AI_Model_Pricing_Checker::bootstrap();
