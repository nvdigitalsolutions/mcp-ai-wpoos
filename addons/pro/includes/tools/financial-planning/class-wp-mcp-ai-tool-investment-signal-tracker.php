<?php
/**
 * Investment Signal Tracker Tool
 *
 * Tracks and manages investment signals, evaluating how new market
 * information impacts existing signals with P&L tracking and
 * confidence scoring.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for tracking and managing investment signals.
 *
 * Supports:
 * - Creating new investment signals with thesis, direction, and targets
 * - Updating signal status and parameters
 * - Listing active signals for a user
 * - Evaluating signals against new market information and prices
 * - P&L calculation and confidence adjustment
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Investment_Signal_Tracker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if financial planner toolkit is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_financial_planner_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Investment signal tracker tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'investment_signal_tracker';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Investment Signal Tracker', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Track and manage investment signals with thesis, direction, confidence, and price targets. Evaluate how new market information impacts existing signals. Includes P&L tracking and confidence adjustment. EDUCATIONAL ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'          => array(
					'type'        => 'string',
					'description' => __( 'The action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'create', 'update', 'list', 'evaluate' ),
				),
				'signal'          => array(
					'type'        => 'object',
					'description' => __( 'Signal data for the "create" action.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'ticker'       => array(
							'type'        => 'string',
							'description' => __( 'Ticker symbol.', 'mcp-ai-wpoos-pro' ),
						),
						'thesis'       => array(
							'type'        => 'string',
							'description' => __( 'Investment thesis or rationale.', 'mcp-ai-wpoos-pro' ),
						),
						'direction'    => array(
							'type'        => 'string',
							'description' => __( 'Signal direction.', 'mcp-ai-wpoos-pro' ),
							'enum'        => array( 'bullish', 'bearish', 'neutral' ),
						),
						'confidence'   => array(
							'type'        => 'integer',
							'description' => __( 'Confidence level (0-100).', 'mcp-ai-wpoos-pro' ),
							'minimum'     => 0,
							'maximum'     => 100,
						),
						'entry_price'  => array(
							'type'        => 'number',
							'description' => __( 'Entry price point.', 'mcp-ai-wpoos-pro' ),
						),
						'target_price' => array(
							'type'        => 'number',
							'description' => __( 'Target price point.', 'mcp-ai-wpoos-pro' ),
						),
						'stop_loss'    => array(
							'type'        => 'number',
							'description' => __( 'Stop loss price point.', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
				'signal_id'       => array(
					'type'        => 'string',
					'description' => __( 'Signal ID for "update" or "evaluate" actions.', 'mcp-ai-wpoos-pro' ),
				),
				'new_information' => array(
					'type'        => 'string',
					'description' => __( 'New market information to evaluate against the signal.', 'mcp-ai-wpoos-pro' ),
				),
				'current_price'   => array(
					'type'        => 'number',
					'description' => __( 'Current market price for evaluation or update.', 'mcp-ai-wpoos-pro' ),
				),
				'user_id_filter'  => array(
					'type'        => 'integer',
					'description' => __( 'Filter signals by specific user ID.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'computation',
			'state-changing',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.1.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the signal tracker.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';

		$valid_actions = array( 'create', 'update', 'list', 'evaluate' );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			return new WP_Error(
				'invalid_action',
				__( 'Invalid action. Must be one of: create, update, list, evaluate.', 'mcp-ai-wpoos-pro' )
			);
		}

		$target_user_id = isset( $arguments['user_id_filter'] ) ? absint( $arguments['user_id_filter'] ) : $current_user_id;

		switch ( $action ) {
			case 'create':
				return $this->create_signal( $arguments, $current_user_id );

			case 'update':
				return $this->update_signal( $arguments, $current_user_id );

			case 'list':
				// Only admins may view other users' signals.
				if ( $target_user_id !== $current_user_id
					&& ! user_can( $current_user_id, 'manage_options' ) ) {
					return new WP_Error(
						'wp_mcp_ai_forbidden',
						__( 'You do not have permission to view another user\'s signals.', 'mcp-ai-wpoos-pro' )
					);
				}
				return $this->list_signals( $target_user_id );

			case 'evaluate':
				return $this->evaluate_signal( $arguments, $current_user_id );

			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * Create a new investment signal.
	 *
	 * @since 1.1.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   Current user ID.
	 * @return array|WP_Error
	 */
	private function create_signal( $arguments, $user_id ) {
		$signal_data = isset( $arguments['signal'] ) && is_array( $arguments['signal'] ) ? $arguments['signal'] : array();

		if ( empty( $signal_data ) ) {
			return new WP_Error(
				'missing_signal',
				__( 'Signal data is required for the "create" action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$ticker       = isset( $signal_data['ticker'] ) ? strtoupper( sanitize_text_field( $signal_data['ticker'] ) ) : '';
		$thesis       = isset( $signal_data['thesis'] ) ? sanitize_text_field( $signal_data['thesis'] ) : '';
		$direction    = isset( $signal_data['direction'] ) ? sanitize_text_field( $signal_data['direction'] ) : 'neutral';
		$confidence   = isset( $signal_data['confidence'] ) ? min( 100, max( 0, absint( $signal_data['confidence'] ) ) ) : 50;
		$entry_price  = isset( $signal_data['entry_price'] ) ? floatval( $signal_data['entry_price'] ) : 0;
		$target_price = isset( $signal_data['target_price'] ) ? floatval( $signal_data['target_price'] ) : 0;
		$stop_loss    = isset( $signal_data['stop_loss'] ) ? floatval( $signal_data['stop_loss'] ) : 0;

		if ( empty( $ticker ) ) {
			return new WP_Error( 'missing_ticker', __( 'Ticker symbol is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$valid_directions = array( 'bullish', 'bearish', 'neutral' );
		if ( ! in_array( $direction, $valid_directions, true ) ) {
			$direction = 'neutral';
		}

		$signal_id = 'sig_' . wp_generate_uuid4();

		$signal = array(
			'signal_id'          => $signal_id,
			'ticker'             => $ticker,
			'thesis'             => $thesis,
			'direction'          => $direction,
			'confidence'         => $confidence,
			'initial_confidence' => $confidence,
			'entry_price'        => $entry_price,
			'target_price'       => $target_price,
			'stop_loss'          => $stop_loss,
			'status'             => 'active',
			'created_at'         => current_time( 'mysql' ),
			'updated_at'         => current_time( 'mysql' ),
			'evaluations'        => array(),
		);

		$signals               = $this->get_user_signals( $user_id );
		$signals[ $signal_id ] = $signal;
		$this->save_user_signals( $user_id, $signals );

		return array(
			'success'    => true,
			'action'     => 'create',
			'signal'     => $signal,
			'message'    => sprintf(
				/* translators: 1: signal ID, 2: ticker, 3: direction */
				__( 'Signal %1$s created for %2$s (%3$s).', 'mcp-ai-wpoos-pro' ),
				$signal_id,
				$ticker,
				$direction
			),
			'disclaimer' => __( 'EDUCATIONAL ONLY. Signal tracking is for informational and educational purposes only. This does not constitute investment advice or a recommendation to buy or sell securities. Consult a licensed financial advisor.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Update an existing signal.
	 *
	 * @since 1.1.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   Current user ID.
	 * @return array|WP_Error
	 */
	private function update_signal( $arguments, $user_id ) {
		$signal_id     = isset( $arguments['signal_id'] ) ? sanitize_text_field( $arguments['signal_id'] ) : '';
		$current_price = isset( $arguments['current_price'] ) ? floatval( $arguments['current_price'] ) : null;

		if ( empty( $signal_id ) ) {
			return new WP_Error( 'missing_signal_id', __( 'Signal ID is required for the "update" action.', 'mcp-ai-wpoos-pro' ) );
		}

		$signals = $this->get_user_signals( $user_id );

		if ( ! isset( $signals[ $signal_id ] ) ) {
			return new WP_Error(
				'signal_not_found',
				/* translators: %s: signal ID */
				sprintf( __( 'Signal %s not found.', 'mcp-ai-wpoos-pro' ), $signal_id )
			);
		}

		$signal = $signals[ $signal_id ];

		// Update fields from signal data if provided.
		if ( isset( $arguments['signal'] ) && is_array( $arguments['signal'] ) ) {
			$updates = $arguments['signal'];
			if ( isset( $updates['status'] ) ) {
				$valid_statuses = array( 'active', 'closed', 'expired' );
				$new_status     = sanitize_text_field( $updates['status'] );
				if ( in_array( $new_status, $valid_statuses, true ) ) {
					$signal['status'] = $new_status;
				}
			}
			if ( isset( $updates['confidence'] ) ) {
				$signal['confidence'] = min( 100, max( 0, absint( $updates['confidence'] ) ) );
			}
			if ( isset( $updates['target_price'] ) ) {
				$signal['target_price'] = floatval( $updates['target_price'] );
			}
			if ( isset( $updates['stop_loss'] ) ) {
				$signal['stop_loss'] = floatval( $updates['stop_loss'] );
			}
			if ( isset( $updates['thesis'] ) ) {
				$signal['thesis'] = sanitize_text_field( $updates['thesis'] );
			}
		}

		if ( null !== $current_price && $current_price > 0 ) {
			$signal['last_price'] = $current_price;
		}

		$signal['updated_at'] = current_time( 'mysql' );

		$signals[ $signal_id ] = $signal;
		$this->save_user_signals( $user_id, $signals );

		return array(
			'success'    => true,
			'action'     => 'update',
			'signal'     => $signal,
			'message'    => sprintf(
				/* translators: %s: signal ID */
				__( 'Signal %s updated successfully.', 'mcp-ai-wpoos-pro' ),
				$signal_id
			),
			'disclaimer' => __( 'EDUCATIONAL ONLY. Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * List all signals for a user.
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	private function list_signals( $user_id ) {
		$signals        = $this->get_user_signals( $user_id );
		$active_signals = array_filter(
			$signals,
			function ( $s ) {
				return 'active' === $s['status'];
			}
		);

		return array(
			'success'      => true,
			'action'       => 'list',
			'signals'      => array_values( $signals ),
			'total_count'  => count( $signals ),
			'active_count' => count( $active_signals ),
			'closed_count' => count( $signals ) - count( $active_signals ),
			'disclaimer'   => __( 'EDUCATIONAL ONLY. Signal tracking is for informational purposes only. Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Evaluate a signal against new information and/or current price.
	 *
	 * @since 1.1.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   Current user ID.
	 * @return array|WP_Error
	 */
	private function evaluate_signal( $arguments, $user_id ) {
		$signal_id       = isset( $arguments['signal_id'] ) ? sanitize_text_field( $arguments['signal_id'] ) : '';
		$new_information = isset( $arguments['new_information'] ) ? sanitize_text_field( $arguments['new_information'] ) : '';
		$current_price   = isset( $arguments['current_price'] ) ? floatval( $arguments['current_price'] ) : 0;

		if ( empty( $signal_id ) ) {
			return new WP_Error( 'missing_signal_id', __( 'Signal ID is required for the "evaluate" action.', 'mcp-ai-wpoos-pro' ) );
		}

		$signals = $this->get_user_signals( $user_id );

		if ( ! isset( $signals[ $signal_id ] ) ) {
			return new WP_Error(
				'signal_not_found',
				/* translators: %s: signal ID */
				sprintf( __( 'Signal %s not found.', 'mcp-ai-wpoos-pro' ), $signal_id )
			);
		}

		$signal = $signals[ $signal_id ];

		// Price-based evaluation.
		$price_analysis = array();
		if ( $current_price > 0 && $signal['entry_price'] > 0 ) {
			$pnl     = $current_price - $signal['entry_price'];
			$pnl_pct = ( $pnl / $signal['entry_price'] ) * 100;

			if ( 'bearish' === $signal['direction'] ) {
				$pnl     = -$pnl;
				$pnl_pct = -$pnl_pct;
			}

			$target_hit = false;
			$stop_hit   = false;

			if ( $signal['target_price'] > 0 ) {
				if ( 'bullish' === $signal['direction'] ) {
					$target_hit = $current_price >= $signal['target_price'];
				} elseif ( 'bearish' === $signal['direction'] ) {
					$target_hit = $current_price <= $signal['target_price'];
				}
			}

			if ( $signal['stop_loss'] > 0 ) {
				if ( 'bullish' === $signal['direction'] ) {
					$stop_hit = $current_price <= $signal['stop_loss'];
				} elseif ( 'bearish' === $signal['direction'] ) {
					$stop_hit = $current_price >= $signal['stop_loss'];
				}
			}

			$price_analysis = array(
				'current_price' => $current_price,
				'entry_price'   => $signal['entry_price'],
				'pnl'           => round( $pnl, 4 ),
				'pnl_pct'       => round( $pnl_pct, 2 ),
				'target_hit'    => $target_hit,
				'stop_hit'      => $stop_hit,
			);
		}

		// Information-based evaluation.
		$info_analysis = array();
		$evolution     = 'unchanged';
		$conf_change   = 0;

		if ( ! empty( $new_information ) ) {
			$info_analysis = $this->analyze_information_impact( $new_information, $signal );
			$evolution     = $info_analysis['evolution'];
			$conf_change   = $info_analysis['confidence_change'];
		}

		// Adjust confidence from price.
		if ( ! empty( $price_analysis ) ) {
			if ( $price_analysis['target_hit'] ) {
				$conf_change += 10;
				$evolution    = 'strengthened';
			} elseif ( $price_analysis['stop_hit'] ) {
				$conf_change -= 15;
				$evolution    = 'falsified';
			} elseif ( $price_analysis['pnl_pct'] > 5 ) {
				$conf_change += 5;
			} elseif ( $price_analysis['pnl_pct'] < -5 ) {
				$conf_change -= 5;
			}
		}

		// Apply confidence change.
		$new_confidence       = min( 100, max( 0, $signal['confidence'] + $conf_change ) );
		$signal['confidence'] = $new_confidence;
		$signal['updated_at'] = current_time( 'mysql' );

		if ( $current_price > 0 ) {
			$signal['last_price'] = $current_price;
		}

		// Auto-close if falsified or target hit.
		if ( 'falsified' === $evolution || ( ! empty( $price_analysis['target_hit'] ) && $price_analysis['target_hit'] ) ) {
			$signal['status'] = 'closed';
		}

		// Record evaluation.
		$evaluation_record = array(
			'timestamp'         => current_time( 'mysql' ),
			'evolution'         => $evolution,
			'confidence_change' => $conf_change,
			'new_confidence'    => $new_confidence,
			'current_price'     => $current_price > 0 ? $current_price : null,
			'new_information'   => ! empty( $new_information ) ? wp_trim_words( $new_information, 30, '...' ) : '',
		);

		if ( ! isset( $signal['evaluations'] ) ) {
			$signal['evaluations'] = array();
		}
		$signal['evaluations'][] = $evaluation_record;

		$signals[ $signal_id ] = $signal;
		$this->save_user_signals( $user_id, $signals );

		return array(
			'success'           => true,
			'action'            => 'evaluate',
			'signal_id'         => $signal_id,
			'evolution'         => $evolution,
			'confidence_change' => $conf_change,
			'new_confidence'    => $new_confidence,
			'price_analysis'    => $price_analysis,
			'info_analysis'     => $info_analysis,
			'signal'            => $signal,
			'disclaimer'        => __( 'EDUCATIONAL ONLY. Signal evaluation is based on simplified rule-based analysis and should not be used as the sole basis for investment decisions. Markets are complex and unpredictable. Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Analyze the impact of new information on a signal.
	 *
	 * @since 1.1.0
	 *
	 * @param string $information New market information.
	 * @param array  $signal      Signal data.
	 * @return array Analysis result with evolution and confidence_change.
	 */
	private function analyze_information_impact( $information, $signal ) {
		$info_lower = strtolower( $information );
		$direction  = $signal['direction'];

		$bullish_keywords = array( 'upgrade', 'beat', 'growth', 'profit', 'surge', 'rally', 'strong', 'outperform', 'buy', 'bullish', 'positive' );
		$bearish_keywords = array( 'downgrade', 'miss', 'decline', 'loss', 'crash', 'weak', 'selloff', 'underperform', 'sell', 'bearish', 'negative' );

		$bullish_count    = 0;
		$bearish_count    = 0;
		$matched_keywords = array();

		foreach ( $bullish_keywords as $keyword ) {
			if ( false !== strpos( $info_lower, $keyword ) ) {
				++$bullish_count;
				$matched_keywords[] = '+' . $keyword;
			}
		}

		foreach ( $bearish_keywords as $keyword ) {
			if ( false !== strpos( $info_lower, $keyword ) ) {
				++$bearish_count;
				$matched_keywords[] = '-' . $keyword;
			}
		}

		$net_sentiment = $bullish_count - $bearish_count;
		$evolution     = 'unchanged';
		$conf_change   = 0;

		if ( 'bullish' === $direction ) {
			if ( $net_sentiment > 0 ) {
				$evolution   = 'strengthened';
				$conf_change = min( 15, $net_sentiment * 5 );
			} elseif ( $net_sentiment < 0 ) {
				$evolution   = 'weakened';
				$conf_change = max( -15, $net_sentiment * 5 );
				if ( $net_sentiment <= -3 ) {
					$evolution = 'falsified';
				}
			}
		} elseif ( 'bearish' === $direction ) {
			if ( $net_sentiment < 0 ) {
				$evolution   = 'strengthened';
				$conf_change = min( 15, abs( $net_sentiment ) * 5 );
			} elseif ( $net_sentiment > 0 ) {
				$evolution   = 'weakened';
				$conf_change = max( -15, -$net_sentiment * 5 );
				if ( $net_sentiment >= 3 ) {
					$evolution = 'falsified';
				}
			}
		}

		return array(
			'evolution'         => $evolution,
			'confidence_change' => $conf_change,
			'bullish_signals'   => $bullish_count,
			'bearish_signals'   => $bearish_count,
			'net_sentiment'     => $net_sentiment,
			'matched_keywords'  => $matched_keywords,
		);
	}

	/**
	 * Get stored signals for a user.
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id User ID.
	 * @return array Signals array.
	 */
	private function get_user_signals( $user_id ) {
		$option_key = 'wp_mcp_ai_investment_signals_' . absint( $user_id );
		$signals    = get_option( $option_key, array() );
		return is_array( $signals ) ? $signals : array();
	}

	/**
	 * Save signals for a user.
	 *
	 * @since 1.1.0
	 *
	 * @param int   $user_id User ID.
	 * @param array $signals Signals array to save.
	 * @return bool Whether the option was successfully updated.
	 */
	private function save_user_signals( $user_id, $signals ) {
		$option_key = 'wp_mcp_ai_investment_signals_' . absint( $user_id );
		return update_option( $option_key, $signals, false );
	}
}
