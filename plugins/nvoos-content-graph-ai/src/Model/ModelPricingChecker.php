<?php
/**
 * Model Pricing Checker for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/class-wp-mcp-ai-model-pricing-checker.php` (behaviour-
 * preserving; base copy retained permanently — ecosystem port plan
 * D-NOBASE). Cron hook, option keys, pricing-change detection, notice
 * markup, and AJAX endpoints are byte-identical.
 *
 * Decoupling (documented, additive):
 * - `get_cct_class()` resolves the rate-limits CCT class — the base
 *   `WP_MCP_AI_Model_Rate_Limits_CCT` in monolith installs, the ported
 *   `ModelRateLimitsCct` standalone. The base's reflection dance on a
 *   public static is replaced by a direct static call (identical result).
 * - `bootstrap()` (cron + admin hooks) is registered standalone-only by
 *   `Plugin.php` — the base owns the same hooks in monolith installs.
 *
 * @package NvoosContentGraphAi\Model
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages monthly pricing checks for AI models.
 *
 * @since 1.1.0
 */
class ModelPricingChecker {

	const CRON_HOOK            = 'wp_mcp_ai_check_model_pricing';
	const OPTION_LAST_CHECK    = 'wp_mcp_ai_last_pricing_check';
	const OPTION_PRICE_CHANGES = 'wp_mcp_ai_price_changes';

	// Pricing validation constants (per 1K tokens).
	const MIN_PRICING_VALUE = 0.0;
	const MAX_PRICING_VALUE = 10.0;

	/**
	 * Bootstrap the pricing checker.
	 *
	 * @return void
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
	 * @return void
	 */
	public static function register_admin_notices() {
		add_action( 'admin_notices', array( __CLASS__, 'show_price_change_notice' ) );
	}

	/**
	 * Resolve the rate-limits CCT class for the active install mode.
	 *
	 * @return string Class name or empty string when unavailable.
	 */
	protected static function get_cct_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
			return 'WP_MCP_AI_Model_Rate_Limits_CCT';
		}

		return ModelRateLimitsCct::class;
	}

	/**
	 * Check current pricing from the CCT and log any changes.
	 *
	 * @return void
	 */
	public static function check_pricing() {
		$cct_class = static::get_cct_class();

		if ( '' === $cct_class || ! method_exists( $cct_class, 'get_default_model_data' ) ) {
			return;
		}

		$current_models = $cct_class::get_default_model_data();

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
			if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
				\WP_MCP_AI_Logger::log_event(
					'model_pricing_changed',
					sprintf( 'Detected %d pricing changes for AI models.', count( $price_changes ) ),
					array( 'changes' => $price_changes )
				);
			}
		}
	}

	/**
	 * Show admin notice if there are price changes.
	 *
	 * @return void
	 */
	public static function show_price_change_notice() {
		// Only show on NV oOS admin pages to avoid noise on unrelated admin pages.
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'mcp-ai' ) ) {
			return;
		}

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
				<strong><?php esc_html_e( 'NV oOS: AI Model Pricing Updates Detected', 'nvoos-content-graph-ai' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: %d: number of pricing changes */
					esc_html__( '%d AI model pricing changes have been detected. Please review the Model Rate Limits CCT to update your cost estimates.', 'nvoos-content-graph-ai' ),
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
				<p><em><?php esc_html_e( '(Showing last 5 changes)', 'nvoos-content-graph-ai' ); ?></em></p>
			<?php endif; ?>
			<p>
				<button type="button" class="button button-primary wp-mcp-ai-update-costs-btn">
					<?php esc_html_e( 'Update Costs Automatically', 'nvoos-content-graph-ai' ); ?>
				</button>
				<span class="wp-mcp-ai-update-status" style="margin-left: 10px;"></span>
			</p>
		</div>
		<?php
		ob_start();
		?>
		jQuery(document).ready(function($) {
			$('.wp-mcp-ai-price-notice').on('click', '.notice-dismiss', function() {
				$.post(ajaxurl, {
					action: 'wp_mcp_ai_dismiss_price_notice',
					nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_dismiss_price_notice' ) ); ?>,
					count: <?php echo absint( count( $price_changes ) ); ?>
				});
			});

			$('.wp-mcp-ai-update-costs-btn').on('click', function() {
				var $btn = $(this);
				var $status = $('.wp-mcp-ai-update-status');

				$btn.prop('disabled', true).text(<?php echo wp_json_encode( __( 'Updating...', 'nvoos-content-graph-ai' ) ); ?>);
				$status.text('');

				$.post(ajaxurl, {
					action: 'wp_mcp_ai_update_model_costs',
					nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_update_model_costs' ) ); ?>
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
						$status.html('<span style="color: red;">✗ ' + (response.data.message || <?php echo wp_json_encode( __( 'Update failed', 'nvoos-content-graph-ai' ) ); ?>) + '</span>');
						$btn.prop('disabled', false).text(<?php echo wp_json_encode( __( 'Update Costs Automatically', 'nvoos-content-graph-ai' ) ); ?>);
					}
				})
				.fail(function() {
					$status.html('<span style="color: red;">✗ ' + <?php echo wp_json_encode( __( 'Network error occurred', 'nvoos-content-graph-ai' ) ); ?> + '</span>');
					$btn.prop('disabled', false).text(<?php echo wp_json_encode( __( 'Update Costs Automatically', 'nvoos-content-graph-ai' ) ); ?>);
				});
			});
		});
		<?php
		$js = ob_get_clean();
		wp_print_inline_script_tag( $js );
	}

	/**
	 * Handle AJAX request to dismiss price notice.
	 *
	 * @return void
	 */
	public static function dismiss_price_notice() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_dismiss_price_notice', 'nonce' );

		// Check capabilities — only administrators see pricing notices.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nvoos-content-graph-ai' ) ) );
			return;
		}

		$count = isset( $_POST['count'] ) ? absint( wp_unslash( $_POST['count'] ) ) : 0;
		update_user_meta( get_current_user_id(), 'wp_mcp_ai_dismissed_price_notice', $count );
		wp_send_json_success();
	}

	/**
	 * Handle AJAX request to update model costs in the CCT.
	 *
	 * @return void
	 */
	public static function update_model_costs() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_update_model_costs', 'nonce' );

		// Check if user is logged in and has permissions.
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in to update costs.', 'nvoos-content-graph-ai' ) ) );
			return;
		}

		// Check user capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to update costs.', 'nvoos-content-graph-ai' ) ) );
			return;
		}

		$cct_class = static::get_cct_class();

		// Check if CCT class is available.
		if ( '' === $cct_class ) {
			wp_send_json_error( array( 'message' => __( 'Model Rate Limits CCT is not available. Please ensure JetEngine is active.', 'nvoos-content-graph-ai' ) ) );
			return;
		}

		// Get price changes.
		$price_changes = get_option( self::OPTION_PRICE_CHANGES, array() );

		if ( empty( $price_changes ) ) {
			wp_send_json_error( array( 'message' => __( 'No pricing changes to apply.', 'nvoos-content-graph-ai' ) ) );
			return;
		}

		// Get the item handler.
		$handler = $cct_class::get_item_handler();

		if ( ! $handler ) {
			wp_send_json_error( array( 'message' => __( 'Unable to access Model Rate Limits CCT handler.', 'nvoos-content-graph-ai' ) ) );
			return;
		}

		$factory = $handler->get_factory();

		if ( ! $factory || empty( $factory->db ) ) {
			wp_send_json_error( array( 'message' => __( 'Unable to access Model Rate Limits CCT database.', 'nvoos-content-graph-ai' ) ) );
			return;
		}

		// Pre-load default model data for fallback lookups (keyed by model_name).
		$default_models_by_name = array();
		foreach ( $cct_class::get_default_model_data() as $default_model ) {
			if ( isset( $default_model['model_name'] ) ) {
				$default_models_by_name[ $default_model['model_name'] ] = $default_model;
			}
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
					__( 'Invalid pricing values for model: %1$s (must be between $%2$.2f and $%3$.2f per 1K)', 'nvoos-content-graph-ai' ),
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
				// Model not in CCT database yet — look it up in default data and insert it.
				if ( ! isset( $default_models_by_name[ $model_name ] ) ) {
					$errors[] = sprintf(
						/* translators: %s: model name */
						__( 'Model not found: %s', 'nvoos-content-graph-ai' ),
						$model_name
					);
					continue;
				}

				$default_match = $default_models_by_name[ $model_name ];

				// Apply the new pricing to the default data and insert into the CCT.
				$default_match['cost_per_1k_input_tokens']  = $new_input_cost;
				$default_match['cost_per_1k_output_tokens'] = $new_output_cost;

				// Remove database-specific fields that should be auto-generated.
				unset( $default_match['_ID'], $default_match['cct_created'], $default_match['cct_modified'], $default_match['cct_author_id'] );

				try {
					$new_id = $handler->update_item( $default_match );

					if ( $new_id ) {
						++$updated_count;

						// Log the insert + update.
						if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
							\WP_MCP_AI_Logger::log_event(
								'model_pricing_inserted_and_updated',
								sprintf( 'Inserted and updated pricing for model: %s', $model_name ),
								array(
									'model'       => $model_name,
									'new_input'   => $change['new_input'],
									'new_output'  => $change['new_output'],
									'updated_by'  => get_current_user_id(),
									'updated_via' => 'auto_update_button',
								)
							);
						}
					} else {
						$errors[] = sprintf(
							/* translators: %s: model name */
							__( 'Failed to insert model from defaults: %s', 'nvoos-content-graph-ai' ),
							$model_name
						);
					}
				} catch ( \Exception $e ) {
					$errors[] = sprintf(
						/* translators: 1: model name, 2: error message */
						__( 'Failed to insert %1$s: %2$s', 'nvoos-content-graph-ai' ),
						$model_name,
						$e->getMessage()
					);
				}

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
				if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
					\WP_MCP_AI_Logger::log_event(
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
			} catch ( \Exception $e ) {
				$errors[] = sprintf(
					/* translators: 1: model name, 2: error message */
					__( 'Failed to update %1$s: %2$s', 'nvoos-content-graph-ai' ),
					$model_name,
					$e->getMessage()
				);
			}
		}

		// Clear price changes after successful update.
		if ( $updated_count > 0 ) {
			delete_option( self::OPTION_PRICE_CHANGES );
			// Reset the dismissed notice counter for all users so they can see
			// new pricing change notifications in the future.
			delete_metadata( 'user', 0, 'wp_mcp_ai_dismissed_price_notice', '', true );
		}

		// Send response.
		if ( $updated_count > 0 ) {
			$message = sprintf(
				/* translators: %d: number of models updated */
				_n( 'Successfully updated %d model cost.', 'Successfully updated %d model costs.', $updated_count, 'nvoos-content-graph-ai' ),
				$updated_count
			);

			if ( ! empty( $errors ) ) {
				$message .= ' ' . __( 'Some updates failed:', 'nvoos-content-graph-ai' ) . ' ' . implode( ', ', $errors );
			}

			wp_send_json_success( array( 'message' => $message ) );
		} else {
			wp_send_json_error(
				array(
					'message' => __( 'No models were updated.', 'nvoos-content-graph-ai' ) . ' ' . implode( ', ', $errors ),
				)
			);
		}
	}

	/**
	 * Clear price change notices (admin utility).
	 *
	 * @return bool
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
	 *
	 * @return void
	 */
	public static function trigger_check() {
		self::check_pricing();
	}
}
