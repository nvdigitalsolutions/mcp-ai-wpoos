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

	// Pricing validation constants (per 1K tokens).
	const MIN_PRICING_VALUE = 0.0;
	const MAX_PRICING_VALUE = 10.0;

	/**
	 * Bootstrap the pricing checker.
	 */
	public static function bootstrap() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'check_pricing' ) );
		// Register admin_notices on init to avoid early translation loading (WordPress 6.7.0+).
		add_action( 'init', array( __CLASS__, 'register_admin_notices' ) );
		add_action( 'wp_ajax_wp_mcp_ai_dismiss_price_notice', array( __CLASS__, 'dismiss_price_notice' ) );
		add_action( 'wp_ajax_wp_mcp_ai_update_model_costs', array( __CLASS__, 'update_model_costs' ) );

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
				if ( $prev_input !== $input_cost || $prev_output !== $output_cost ) {
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
				<strong><?php esc_html_e( 'NV oOS: AI Model Pricing Updates Detected', 'mcp-ai-wpoos' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: %d: number of pricing changes */
					esc_html__( '%d AI model pricing changes have been detected. Please review the Model Rate Limits CCT to update your cost estimates.', 'mcp-ai-wpoos' ),
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
				<p><em><?php esc_html_e( '(Showing last 5 changes)', 'mcp-ai-wpoos' ); ?></em></p>
			<?php endif; ?>
			<p>
				<button type="button" class="button button-primary wp-mcp-ai-update-costs-btn">
					<?php esc_html_e( 'Update Costs Automatically', 'mcp-ai-wpoos' ); ?>
				</button>
				<span class="wp-mcp-ai-update-status" style="margin-left: 10px;"></span>
			</p>
		</div>
		<script>
		jQuery(document).ready(function($) {
			$('.wp-mcp-ai-price-notice').on('click', '.notice-dismiss', function() {
				$.post(ajaxurl, {
					action: 'wp_mcp_ai_dismiss_price_notice',
					nonce: '<?php echo esc_js( wp_create_nonce( 'wp_mcp_ai_dismiss_price_notice' ) ); ?>',
					count: <?php echo esc_js( absint( count( $price_changes ) ) ); ?>
				});
			});

			$('.wp-mcp-ai-update-costs-btn').on('click', function() {
				var $btn = $(this);
				var $status = $('.wp-mcp-ai-update-status');
				
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Updating...', 'mcp-ai-wpoos' ) ); ?>');
				$status.text('');
				
				$.post(ajaxurl, {
					action: 'wp_mcp_ai_update_model_costs',
					nonce: '<?php echo esc_js( wp_create_nonce( 'wp_mcp_ai_update_model_costs' ) ); ?>'
				})
				.done(function(response) {
					if (response.success) {
						$status.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
						setTimeout(function() {
							$('.wp-mcp-ai-price-notice').fadeOut(function() {
								$(this).remove();
							});
						}, 2000);
					} else {
						$status.html('<span style="color: red;">✗ ' + (response.data.message || '<?php echo esc_js( __( 'Update failed', 'mcp-ai-wpoos' ) ); ?>') + '</span>');
						$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Update Costs Automatically', 'mcp-ai-wpoos' ) ); ?>');
					}
				})
				.fail(function() {
					$status.html('<span style="color: red;">✗ <?php echo esc_js( __( 'Network error occurred', 'mcp-ai-wpoos' ) ); ?></span>');
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Update Costs Automatically', 'mcp-ai-wpoos' ) ); ?>');
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
			wp_send_json_error( array( 'message' => __( 'You must be logged in to dismiss notices.', 'mcp-ai-wpoos' ) ) );
			return;
		}

		$count = isset( $_POST['count'] ) ? absint( $_POST['count'] ) : 0;
		update_user_meta( get_current_user_id(), 'wp_mcp_ai_dismissed_price_notice', $count );
		wp_send_json_success();
	}

	/**
	 * Handle AJAX request to update model costs in the CCT.
	 */
	public static function update_model_costs() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_update_model_costs', 'nonce' );

		// Check if user is logged in and has permissions.
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in to update costs.', 'mcp-ai-wpoos' ) ) );
			return;
		}

		// Check user capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to update costs.', 'mcp-ai-wpoos' ) ) );
			return;
		}

		// Check if CCT class is available.
		if ( ! class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
			wp_send_json_error( array( 'message' => __( 'Model Rate Limits CCT is not available. Please ensure JetEngine is active.', 'mcp-ai-wpoos' ) ) );
			return;
		}

		// Get price changes.
		$price_changes = get_option( self::OPTION_PRICE_CHANGES, array() );

		if ( empty( $price_changes ) ) {
			wp_send_json_error( array( 'message' => __( 'No pricing changes to apply.', 'mcp-ai-wpoos' ) ) );
			return;
		}

		// Get the item handler.
		$handler = WP_MCP_AI_Model_Rate_Limits_CCT::get_item_handler();

		if ( ! $handler ) {
			wp_send_json_error( array( 'message' => __( 'Unable to access Model Rate Limits CCT handler.', 'mcp-ai-wpoos' ) ) );
			return;
		}

		$factory = $handler->get_factory();

		if ( ! $factory || empty( $factory->db ) ) {
			wp_send_json_error( array( 'message' => __( 'Unable to access Model Rate Limits CCT database.', 'mcp-ai-wpoos' ) ) );
			return;
		}

		// Apply updates.
		$updated_count = 0;
		$errors        = array();

		foreach ( $price_changes as $change ) {
			if ( ! isset( $change['model'] ) || ! isset( $change['new_input'] ) || ! isset( $change['new_output'] ) ) {
				continue;
			}

			$model_name = sanitize_text_field( $change['model'] );

			// Validate model name is not empty.
			if ( empty( $model_name ) ) {
				continue;
			}

			// Validate and sanitize pricing values.
			$new_input_cost  = floatval( $change['new_input'] );
			$new_output_cost = floatval( $change['new_output'] );

			// Ensure pricing values are within reasonable ranges.
			if ( $new_input_cost < self::MIN_PRICING_VALUE || $new_input_cost > self::MAX_PRICING_VALUE ||
				$new_output_cost < self::MIN_PRICING_VALUE || $new_output_cost > self::MAX_PRICING_VALUE ) {
				$errors[] = sprintf(
					/* translators: 1: model name, 2: minimum price, 3: maximum price */
					__( 'Invalid pricing values for model: %1$s (must be between $%2$.2f and $%3$.2f per 1K)', 'mcp-ai-wpoos' ),
					$model_name,
					self::MIN_PRICING_VALUE,
					self::MAX_PRICING_VALUE
				);
				continue;
			}

			// Query for the model.
			$items = $factory->db->query(
				array(
					'model_name' => $model_name,
				)
			);

			if ( empty( $items ) || ! is_array( $items ) ) {
				$errors[] = sprintf(
					/* translators: %s: model name */
					__( 'Model not found: %s', 'mcp-ai-wpoos' ),
					$model_name
				);
				continue;
			}

			$model_data = reset( $items );

			// Update the costs with validated values.
			$model_data['cost_per_1k_input_tokens']  = $new_input_cost;
			$model_data['cost_per_1k_output_tokens'] = $new_output_cost;

			// Update in database.
			try {
				$handler->update_item( $model_data );
				++$updated_count;

				// Log the update.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'model_pricing_updated',
						sprintf( 'Updated pricing for model: %s', $model_name ),
						array(
							'model'       => $model_name,
							'old_input'   => $change['old_input'],
							'new_input'   => $change['new_input'],
							'old_output'  => $change['old_output'],
							'new_output'  => $change['new_output'],
							'updated_by'  => get_current_user_id(),
							'updated_via' => 'auto_update_button',
						)
					);
				}
			} catch ( Exception $e ) {
				$errors[] = sprintf(
					/* translators: 1: model name, 2: error message */
					__( 'Failed to update %1$s: %2$s', 'mcp-ai-wpoos' ),
					$model_name,
					$e->getMessage()
				);
			}
		}

		// Clear price changes after successful update.
		if ( $updated_count > 0 ) {
			delete_option( self::OPTION_PRICE_CHANGES );
			// Reset the dismissed notice counter for all users so they can see.
			// new pricing change notifications. This is intentional: when prices.
			// are updated via the button, all users should be re-notified if new.
			// changes are detected in the future.
			delete_metadata( 'user', 0, 'wp_mcp_ai_dismissed_price_notice', '', true );
		}

		// Send response.
		if ( $updated_count > 0 ) {
			$message = sprintf(
				/* translators: %d: number of models updated */
				_n( 'Successfully updated %d model cost.', 'Successfully updated %d model costs.', $updated_count, 'mcp-ai-wpoos' ),
				$updated_count
			);

			if ( ! empty( $errors ) ) {
				$message .= ' ' . __( 'Some updates failed:', 'mcp-ai-wpoos' ) . ' ' . implode( ', ', $errors );
			}

			wp_send_json_success( array( 'message' => $message ) );
		} else {
			wp_send_json_error(
				array(
					'message' => __( 'No models were updated.', 'mcp-ai-wpoos' ) . ' ' . implode( ', ', $errors ),
				)
			);
		}
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
