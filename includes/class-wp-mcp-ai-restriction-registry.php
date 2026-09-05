<?php
/**
 * Restriction Registry — unified tracking of user access restrictions.
 *
 * Converts ephemeral enforcement events (rate limits, token overages,
 * per-session budgets) into persistent, enumerable restriction records so
 * admins can see *who* is blocked and lift the restriction from the
 * Command Center or Token Manager.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Restriction_Registry' ) ) {
	/**
	 * Persists, indexes, and lifts per-user access restrictions.
	 *
	 * Storage model:
	 * - Full records: user meta `_wp_mcp_ai_restrictions` (type => record).
	 * - Fast index:   option `wp_mcp_ai_active_restrictions` (autoload off)
	 *   keyed by "user_id:type" so listing and badge counts are O(1)-ish.
	 *
	 * Events are kept forever-ish (pruned with the user), while the active
	 * index only contains non-expired, non-cleared restrictions.
	 *
	 * @since 1.2.0
	 */
	class WP_MCP_AI_Restriction_Registry {

		/**
		 * User meta key holding the full restriction records.
		 */
		const USER_META_KEY = '_wp_mcp_ai_restrictions';

		/**
		 * Option key holding the fast-lookup index of active restrictions.
		 */
		const INDEX_OPTION = 'wp_mcp_ai_active_restrictions';

		/**
		 * Option key holding unseen restriction notices for admins.
		 */
		const NOTICE_OPTION = 'wp_mcp_ai_restriction_notices';

		/**
		 * Restriction type: chat/SSE rate limit.
		 */
		const TYPE_RATE_LIMIT = 'rate_limit';

		/**
		 * Restriction type: daily per-tool token overage.
		 */
		const TYPE_TOKEN_OVERAGE = 'token_overage';

		/**
		 * Restriction type: per-session token budget.
		 */
		const TYPE_SESSION_LIMIT = 'session_limit';

		/**
		 * Restriction type: admin-applied manual block.
		 */
		const TYPE_MANUAL = 'manual';

		/**
		 * Record status: currently enforced.
		 */
		const STATUS_ACTIVE = 'active';

		/**
		 * Record status: released automatically because the window elapsed.
		 */
		const STATUS_EXPIRED = 'expired';

		/**
		 * Record status: released by an administrator.
		 */
		const STATUS_CLEARED = 'cleared';

		/**
		 * How long session-limit records stay active when no release time
		 * is known (matches the session transient lifetime).
		 */
		const SESSION_LIMIT_GRACE_SECONDS = 3600;

		/**
		 * Register hooks.
		 *
		 * Subscribes to the existing enforcement hooks so the enforcement
		 * classes themselves stay untouched:
		 * - `wp_mcp_ai_tool_token_limit_exceeded` (daily tier overage).
		 * - `wp_mcp_ai_per_session_limit_exceeded` (per-session budget).
		 * - `wp_mcp_ai_rate_limit_exceeded` (fired by the Nvoos rate limiter
		 *   WordPress adapter when a chat/SSE window is exhausted).
		 * - `wp_mcp_ai_rest_request_rate_limit_exceeded` (fired by the general
		 *   REST request limiter when a user exhausts their request budget).
		 *
		 * @since 1.2.0
		 * @return void
		 */
		public static function register() {
			add_action( 'wp_mcp_ai_tool_token_limit_exceeded', array( __CLASS__, 'on_tool_token_limit_exceeded' ), 10, 6 );
			add_action( 'wp_mcp_ai_per_session_limit_exceeded', array( __CLASS__, 'on_per_session_limit_exceeded' ), 10, 4 );
			add_action( 'wp_mcp_ai_rate_limit_exceeded', array( __CLASS__, 'on_rate_limit_exceeded' ), 10, 3 );
			add_action( 'wp_mcp_ai_rest_request_rate_limit_exceeded', array( __CLASS__, 'on_rest_request_rate_limit_exceeded' ), 10, 5 );

			// Lazy expiry sweep on the existing daily cleanup cron.
			add_action( 'wp_mcp_ai_daily_cleanup', array( __CLASS__, 'maybe_expire' ) );

			// Admin discoverability.
			add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );

			// REST surface (delegated to the dedicated controller).
			add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		}

		/**
		 * Register REST routes through the restrictions controller when present.
		 *
		 * @since 1.2.0
		 * @return void
		 */
		public static function register_rest_routes() {
			if ( class_exists( 'WP_MCP_AI_REST_Restrictions_Controller' ) ) {
				WP_MCP_AI_REST_Restrictions_Controller::register_routes();
			}
		}

		/**
		 * Get the allowlisted restriction types.
		 *
		 * @since 1.2.0
		 * @return array<string, string> Type slug => translated label.
		 */
		public static function get_types() {
			$types = array(
				self::TYPE_RATE_LIMIT    => __( 'Rate limit', 'mcp-ai-wpoos' ),
				self::TYPE_TOKEN_OVERAGE => __( 'Token overage', 'mcp-ai-wpoos' ),
				self::TYPE_SESSION_LIMIT => __( 'Session limit', 'mcp-ai-wpoos' ),
				self::TYPE_MANUAL        => __( 'Manual block', 'mcp-ai-wpoos' ),
			);

			/**
			 * Filter the restriction types the registry understands.
			 *
			 * @since 1.2.0
			 *
			 * @param array $types Type slug => label pairs.
			 */
			return apply_filters( 'wp_mcp_ai_restriction_types', $types );
		}

		/**
		 * Flag (or re-flag) a user restriction.
		 *
		 * Idempotent: re-flagging the same type upserts the record and
		 * increments the trigger counter instead of duplicating it.
		 *
		 * @since 1.2.0
		 *
		 * @param int    $user_id Affected user ID.
		 * @param string $type   Restriction type (see TYPE_* constants).
		 * @param array  $details Contextual details (scope, tool, limits, etc.).
		 * @return array|WP_Error The stored record, or WP_Error on invalid input.
		 */
		public static function flag( $user_id, $type, $details = array() ) {
			$user_id = absint( $user_id );
			$type    = sanitize_key( $type );

			if ( $user_id <= 0 ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_user',
					__( 'A valid user ID is required to flag a restriction.', 'mcp-ai-wpoos' )
				);
			}

			if ( ! array_key_exists( $type, self::get_types() ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_restriction_type',
					sprintf(
						/* translators: %s: restriction type */
						__( 'Unknown restriction type: %s.', 'mcp-ai-wpoos' ),
						$type
					)
				);
			}

			$records = self::get_for_user( $user_id );
			$record  = isset( $records[ $type ] ) && is_array( $records[ $type ] ) ? $records[ $type ] : array();

			// Keep cleared/expired history but reset it to active on re-flag.
			$record['type']          = $type;
			$record['status']        = self::STATUS_ACTIVE;
			$record['scope']         = isset( $details['scope'] ) ? sanitize_key( $details['scope'] ) : ( isset( $record['scope'] ) ? $record['scope'] : 'global' );
			$record['assistant_id']  = isset( $details['assistant_id'] ) ? absint( $details['assistant_id'] ) : ( isset( $record['assistant_id'] ) ? $record['assistant_id'] : 0 );
			$record['tool_slug']     = isset( $details['tool_slug'] ) ? sanitize_key( $details['tool_slug'] ) : ( isset( $record['tool_slug'] ) ? $record['tool_slug'] : '' );
			$record['session_id']    = isset( $details['session_id'] ) ? sanitize_text_field( $details['session_id'] ) : ( isset( $record['session_id'] ) ? $record['session_id'] : '' );
			$record['limit']         = isset( $details['limit'] ) ? absint( $details['limit'] ) : ( isset( $record['limit'] ) ? $record['limit'] : 0 );
			$record['window']        = isset( $details['window'] ) ? absint( $details['window'] ) : ( isset( $record['window'] ) ? $record['window'] : 0 );
			$record['usage']         = isset( $details['usage'] ) ? absint( $details['usage'] ) : ( isset( $record['usage'] ) ? $record['usage'] : 0 );
			$record['tier']          = isset( $details['tier'] ) ? sanitize_key( $details['tier'] ) : ( isset( $record['tier'] ) ? $record['tier'] : '' );
			$record['reason']        = isset( $details['reason'] ) ? sanitize_text_field( $details['reason'] ) : ( isset( $record['reason'] ) ? $record['reason'] : '' );
			$record['triggered_at']  = time();
			$record['released_at']   = isset( $details['released_at'] ) ? absint( $details['released_at'] ) : ( isset( $record['released_at'] ) ? absint( $record['released_at'] ) : 0 );
			$record['trigger_count'] = isset( $record['trigger_count'] ) ? absint( $record['trigger_count'] ) + 1 : 1;
			$record['cleared_at']    = 0;
			$record['cleared_by']    = 0;

			$records[ $type ] = $record;
			update_user_meta( $user_id, self::USER_META_KEY, $records );

			self::rebuild_index_for_user( $user_id, $records );
			self::queue_admin_notice( $user_id, $type );

			if ( class_exists( 'WP_MCP_AI_Security_Audit_Logger' ) ) {
				WP_MCP_AI_Security_Audit_Logger::log_event(
					'wp_mcp_ai_restriction_flagged',
					$user_id,
					array(
						'type'         => $type,
						'scope'        => $record['scope'],
						'tool_slug'    => $record['tool_slug'],
						'triggered_at' => $record['triggered_at'],
						'released_at'  => $record['released_at'],
					)
				);
			}

			/**
			 * Fires after a user restriction has been flagged or re-flagged.
			 *
			 * @since 1.2.0
			 *
			 * @param int    $user_id Affected user ID.
			 * @param string $type    Restriction type.
			 * @param array  $record  Full restriction record.
			 */
			do_action( 'wp_mcp_ai_restriction_flagged', $user_id, $type, $record );

			return $record;
		}

		/**
		 * Lift one or all restrictions for a user and reset the underlying
		 * counters (token usage, session budgets, rate-limit windows).
		 *
		 * @since 1.2.0
		 *
		 * @param int    $user_id    Affected user ID.
		 * @param string $type       Restriction type or 'all'.
		 * @param int    $cleared_by Admin user ID performing the lift.
		 * @return true|WP_Error True on success, WP_Error on failure.
		 */
		public static function lift( $user_id, $type = 'all', $cleared_by = 0 ) {
			$user_id = absint( $user_id );
			$type    = sanitize_key( $type );

			if ( $user_id <= 0 ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_user',
					__( 'A valid user ID is required to lift a restriction.', 'mcp-ai-wpoos' )
				);
			}

			if ( 'all' !== $type && ! array_key_exists( $type, self::get_types() ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_restriction_type',
					sprintf(
						/* translators: %s: restriction type */
						__( 'Unknown restriction type: %s.', 'mcp-ai-wpoos' ),
						$type
					)
				);
			}

			$records = self::get_for_user( $user_id );
			$types   = ( 'all' === $type ) ? array_keys( self::get_types() ) : array( $type );
			$changed = false;

			foreach ( $types as $current_type ) {
				if ( empty( $records[ $current_type ] ) || ! is_array( $records[ $current_type ] ) ) {
					continue;
				}

				$records[ $current_type ]['status']     = self::STATUS_CLEARED;
				$records[ $current_type ]['cleared_at'] = time();
				$records[ $current_type ]['cleared_by'] = absint( $cleared_by );
				$changed                                = true;

				self::reset_underlying_counters( $user_id, $records[ $current_type ] );
			}

			if ( ! $changed ) {
				return new WP_Error(
					'wp_mcp_ai_no_active_restriction',
					__( 'The user has no active restriction of that type.', 'mcp-ai-wpoos' )
				);
			}

			update_user_meta( $user_id, self::USER_META_KEY, $records );
			self::rebuild_index_for_user( $user_id, $records );

			if ( class_exists( 'WP_MCP_AI_Security_Audit_Logger' ) ) {
				WP_MCP_AI_Security_Audit_Logger::log_event(
					'wp_mcp_ai_restriction_lifted',
					$user_id,
					array(
						'types'      => $types,
						'cleared_by' => absint( $cleared_by ),
						'cleared_at' => time(),
					)
				);
			}

			/**
			 * Fires after a user restriction has been lifted.
			 *
			 * @since 1.2.0
			 *
			 * @param int    $user_id    Affected user ID.
			 * @param array  $types      Restriction types that were lifted.
			 * @param int    $cleared_by Admin user ID that performed the lift.
			 */
			do_action( 'wp_mcp_ai_restriction_lifted', $user_id, $types, absint( $cleared_by ) );

			return true;
		}

		/**
		 * Apply an admin-initiated manual block.
		 *
		 * @since 1.2.0
		 *
		 * @param int   $user_id    Affected user ID.
		 * @param array $details    Reason and optional released_at timestamp.
		 * @param int   $created_by Admin user ID.
		 * @return array|WP_Error Stored record, or WP_Error on invalid input.
		 */
		public static function add_manual( $user_id, $details = array(), $created_by = 0 ) {
			$user_id = absint( $user_id );

			if ( $user_id <= 0 ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_user',
					__( 'A valid user ID is required to add a manual block.', 'mcp-ai-wpoos' )
				);
			}

			$details['type'] = self::TYPE_MANUAL;
			if ( empty( $details['reason'] ) ) {
				$details['reason'] = __( 'Manually restricted by an administrator.', 'mcp-ai-wpoos' );
			}

			$record = self::flag( $user_id, self::TYPE_MANUAL, $details );

			if ( ! is_wp_error( $record ) && class_exists( 'WP_MCP_AI_Security_Audit_Logger' ) ) {
				WP_MCP_AI_Security_Audit_Logger::log_event(
					'wp_mcp_ai_restriction_manual_added',
					$user_id,
					array(
						'reason'     => $record['reason'],
						'created_by' => absint( $created_by ),
					)
				);
			}

			return $record;
		}

		/**
		 * Reset the storage behind a restriction so enforcement passes again.
		 *
		 * @since 1.2.0
		 *
		 * @param int   $user_id Affected user ID.
		 * @param array $record  Restriction record.
		 * @return void
		 */
		private static function reset_underlying_counters( $user_id, $record ) {
			$type = isset( $record['type'] ) ? sanitize_key( $record['type'] ) : '';

			switch ( $type ) {
				case self::TYPE_TOKEN_OVERAGE:
					$tool_slug = isset( $record['tool_slug'] ) ? sanitize_key( $record['tool_slug'] ) : '';
					if ( class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
						WP_MCP_AI_Tool_Token_Limits::reset_user_tool_usage( $user_id, $tool_slug );
					}
					if ( class_exists( 'WP_MCP_AI_Usage_Tracker' ) && defined( 'WP_MCP_AI_Usage_Tracker::USER_META_KEY' ) ) {
						delete_user_meta( $user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY );
					}
					break;

				case self::TYPE_SESSION_LIMIT:
					$session_id = isset( $record['session_id'] ) ? sanitize_text_field( $record['session_id'] ) : '';
					if ( '' !== $session_id && class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
						WP_MCP_AI_Tool_Token_Limits::reset_session_usage( $user_id, $session_id );
					}
					break;

				case self::TYPE_RATE_LIMIT:
					self::reset_rate_limit_counters( $user_id );
					break;
			}
		}

		/**
		 * Reset every rate-limit window tracked for a user.
		 *
		 * Clears the general REST request limiter's per-user window
		 * (`wp_mcp_ai_rate_limit_user_{id}`) and, when the OOS engine is
		 * active, every chat window in the adapter's enumerable key index.
		 * Guest (IP-keyed) windows cannot be mapped back to a user and are
		 * left to expire on their own.
		 *
		 * @since 1.2.0
		 * @since 1.1.71 Also clears the general REST request-limit window.
		 *
		 * @param int $user_id Affected user ID.
		 * @return void
		 */
		private static function reset_rate_limit_counters( $user_id ) {
			// General REST request limiter: delete the fixed-window transient
			// so the user's next request starts a fresh window.
			delete_transient( 'wp_mcp_ai_rate_limit_user_' . absint( $user_id ) );

			if ( function_exists( 'wp_mcp_ai_oos_rate_limiter' ) ) {
				$limiter = wp_mcp_ai_oos_rate_limiter();

				// Prefer the enumerable index introduced for restriction lifts.
				if ( is_object( $limiter ) && method_exists( $limiter, 'resetForPrefix' ) ) {
					$limiter->resetForPrefix( 'chat:' . absint( $user_id ) . ':' );
				} elseif ( is_object( $limiter ) && method_exists( $limiter, 'enumerateKeys' ) ) {
					$prefix = 'chat:' . absint( $user_id ) . ':';
					foreach ( $limiter->enumerateKeys() as $key ) {
						if ( 0 === strpos( $key, $prefix ) ) {
							$limiter->reset( $key );
						}
					}
				}
			}
		}

		/**
		 * Get all restriction records for a user.
		 *
		 * @since 1.2.0
		 *
		 * @param int $user_id User ID.
		 * @return array Type => record pairs (empty when none).
		 */
		public static function get_for_user( $user_id ) {
			$user_id = absint( $user_id );
			if ( $user_id <= 0 ) {
				return array();
			}

			$records = get_user_meta( $user_id, self::USER_META_KEY, true );
			return is_array( $records ) ? $records : array();
		}

		/**
		 * Check whether a user currently has an active restriction.
		 *
		 * @since 1.2.0
		 *
		 * @param int    $user_id User ID.
		 * @param string $type    Optional type to scope the check.
		 * @return bool True when an active restriction exists.
		 */
		public static function is_restricted( $user_id, $type = '' ) {
			$index = self::get_index();

			if ( '' !== $type ) {
				$type = sanitize_key( $type );
				return isset( $index[ absint( $user_id ) . ':' . $type ] );
			}

			foreach ( array_keys( $index ) as $key ) {
				if ( 0 === strpos( $key, absint( $user_id ) . ':' ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Get active restrictions, optionally filtered and hydrated with
		 * user display data.
		 *
		 * @since 1.2.0
		 *
		 * @param array $args {
		 *     Optional query arguments.
		 *
		 *     @type string $type       Restriction type filter.
		 *     @type int    $user_id    User ID filter.
		 *     @type int    $per_page   Rows per page (0 = all). Default 20.
		 *     @type int    $page       Page number (1-based).
		 *     @type bool   $hydrate    Include user display fields. Default true.
		 * }
		 * @return array {
		 *     @type array  $rows   Active restriction rows (most recent first).
		 *     @type int    $total  Total matching rows.
		 * }
		 */
		public static function get_active( $args = array() ) {
			$type     = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : '';
			$user_id  = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : 0;
			$per_page = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 20;
			$page     = isset( $args['page'] ) ? max( 1, absint( $args['page'] ) ) : 1;
			$hydrate  = isset( $args['hydrate'] ) ? (bool) $args['hydrate'] : true;

			self::maybe_expire();

			$type_labels = self::get_types();
			$rows        = array();
			foreach ( self::get_index() as $row ) {
				if ( '' !== $type && ( ! isset( $row['type'] ) || $type !== $row['type'] ) ) {
					continue;
				}
				if ( $user_id > 0 && ( ! isset( $row['user_id'] ) || $user_id !== (int) $row['user_id'] ) ) {
					continue;
				}

				if ( $hydrate ) {
					$user = get_userdata( (int) $row['user_id'] );
					if ( $user ) {
						$row['display_name'] = $user->display_name;
						$row['user_login']   = $user->user_login;
						$row['user_email']   = $user->user_email;
						$row['roles']        = array_values( $user->roles );
					} else {
						$row['display_name'] = '';
						$row['user_login']   = '';
						$row['user_email']   = '';
						$row['roles']        = array();
					}
					$row['type_label'] = isset( $type_labels[ $row['type'] ] ) ? $type_labels[ $row['type'] ] : $row['type'];
				}

				$rows[] = $row;
			}

			usort(
				$rows,
				function ( $a, $b ) {
					$at = isset( $a['triggered_at'] ) ? (int) $a['triggered_at'] : 0;
					$bt = isset( $b['triggered_at'] ) ? (int) $b['triggered_at'] : 0;
					return $bt <=> $at;
				}
			);

			$total = count( $rows );

			if ( $per_page > 0 ) {
				$offset = ( $page - 1 ) * $per_page;
				$rows   = array_slice( $rows, $offset, $per_page );
			}

			return array(
				'rows'  => $rows,
				'total' => $total,
			);
		}

		/**
		 * Count active restrictions (optionally by type).
		 *
		 * @since 1.2.0
		 *
		 * @param string $type Optional type filter.
		 * @return int
		 */
		public static function count_active( $type = '' ) {
			self::maybe_expire();

			$index = self::get_index();
			if ( '' === $type ) {
				return count( $index );
			}

			$type  = sanitize_key( $type );
			$count = 0;
			foreach ( $index as $row ) {
				if ( isset( $row['type'] ) && $type === $row['type'] ) {
					++$count;
				}
			}
			return $count;
		}

		/**
		 * Read the active-restriction index.
		 *
		 * @since 1.2.0
		 * @return array Index rows keyed by "user_id:type".
		 */
		public static function get_index() {
			$index = get_option( self::INDEX_OPTION, array() );
			return is_array( $index ) ? $index : array();
		}

		/**
		 * Rebuild the index slice for a single user from their records.
		 *
		 * @since 1.2.0
		 *
		 * @param int   $user_id User ID.
		 * @param array $records Restriction records for that user.
		 * @return void
		 */
		private static function rebuild_index_for_user( $user_id, $records ) {
			$index = self::get_index();
			$keys  = array();

			// Remove stale slices for this user.
			foreach ( array_keys( $index ) as $key ) {
				if ( 0 === strpos( $key, $user_id . ':' ) ) {
					unset( $index[ $key ] );
				}
			}

			foreach ( $records as $type => $record ) {
				if ( ! is_array( $record ) || ! array_key_exists( $type, self::get_types() ) ) {
					continue;
				}

				$status = isset( $record['status'] ) ? $record['status'] : self::STATUS_EXPIRED;
				if ( self::STATUS_ACTIVE !== $status ) {
					continue;
				}

				$released_at = isset( $record['released_at'] ) ? absint( $record['released_at'] ) : 0;
				if ( $released_at > 0 && $released_at < time() ) {
					$record['status'] = self::STATUS_EXPIRED;
					$records[ $type ] = $record;
					update_user_meta( $user_id, self::USER_META_KEY, $records );
					continue;
				}

				$index_key           = $user_id . ':' . $type;
				$index[ $index_key ] = array(
					'user_id'      => $user_id,
					'type'         => $type,
					'scope'        => isset( $record['scope'] ) ? $record['scope'] : 'global',
					'tool_slug'    => isset( $record['tool_slug'] ) ? $record['tool_slug'] : '',
					'reason'       => isset( $record['reason'] ) ? $record['reason'] : '',
					'triggered_at' => isset( $record['triggered_at'] ) ? absint( $record['triggered_at'] ) : time(),
					'released_at'  => $released_at,
				);
			}

			update_option( self::INDEX_OPTION, $index, false );
		}

		/**
		 * Sweep the index for restrictions whose release time has passed.
		 *
		 * @since 1.2.0
		 * @return int Number of restrictions expired in this sweep.
		 */
		public static function maybe_expire() {
			$index   = self::get_index();
			$now     = time();
			$expired = 0;

			foreach ( $index as $key => $row ) {
				$released_at = isset( $row['released_at'] ) ? absint( $row['released_at'] ) : 0;
				if ( $released_at <= 0 || $released_at >= $now ) {
					continue;
				}

				unset( $index[ $key ] );
				++$expired;

				// Reflect the expiry in the full record too.
				$parts   = explode( ':', (string) $key );
				$user_id = isset( $parts[0] ) ? absint( $parts[0] ) : 0;
				$type    = isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '';
				if ( $user_id > 0 && '' !== $type ) {
					$records = self::get_for_user( $user_id );
					if ( isset( $records[ $type ] ) && self::STATUS_ACTIVE === ( isset( $records[ $type ]['status'] ) ? $records[ $type ]['status'] : '' ) ) {
						$records[ $type ]['status'] = self::STATUS_EXPIRED;
						update_user_meta( $user_id, self::USER_META_KEY, $records );
					}
				}
			}

			if ( $expired > 0 ) {
				update_option( self::INDEX_OPTION, $index, false );
			}

			return $expired;
		}

		/**
		 * Queue an unseen-restriction notice for administrators.
		 *
		 * Notices can be disabled from the Orchestration settings
		 * (enable_restriction_admin_notices) or at runtime via the
		 * wp_mcp_ai_restriction_admin_notices filter.
		 *
		 * @since 1.2.0
		 *
		 * @param int    $user_id Affected user ID.
		 * @param string $type    Restriction type.
		 * @return void
		 */
		private static function queue_admin_notice( $user_id, $type ) {
			if ( class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
				$enabled = WP_MCP_AI_Settings_Registry::get_setting( 'enable_restriction_admin_notices', true );
				if ( ! $enabled ) {
					return;
				}
			}

			/**
			 * Filter whether admin notices for new restrictions are queued.
			 *
			 * @since 1.2.0
			 *
			 * @param bool $enabled Default true.
			 */
			if ( ! apply_filters( 'wp_mcp_ai_restriction_admin_notices', true ) ) {
				return;
			}

			$notices = get_option( self::NOTICE_OPTION, array() );
			if ( ! is_array( $notices ) ) {
				$notices = array();
			}

			$user      = get_userdata( $user_id );
			$notices[] = array(
				'user_id'      => $user_id,
				'display_name' => $user ? $user->display_name : (string) $user_id,
				'type'         => $type,
				'triggered_at' => time(),
			);

			// Keep only the most recent 10 unseen flags.
			$notices = array_slice( $notices, -10 );
			update_option( self::NOTICE_OPTION, $notices, false );
		}

		/**
		 * Render the dismissible admin notice listing newly restricted users.
		 *
		 * @since 1.2.0
		 * @return void
		 */
		public static function render_admin_notice() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$notices = get_option( self::NOTICE_OPTION, array() );
			if ( ! is_array( $notices ) || empty( $notices ) ) {
				return;
			}

			$url = admin_url( 'admin.php?page=wp-mcp-ai-token-manager' );
			?>
			<div class="notice notice-warning is-dismissible wp-mcp-ai-restriction-notice">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of newly restricted users */
							_n(
								'%d user has been restricted by rate limits or token budgets.',
								'%d users have been restricted by rate limits or token budgets.',
								count( $notices ),
								'mcp-ai-wpoos'
							),
							count( $notices )
						)
					);
					?>
					<a href="<?php echo esc_url( $url ); ?>">
						<?php esc_html_e( 'Review restrictions', 'mcp-ai-wpoos' ); ?>
					</a>
				</p>
			</div>
			<?php
		}

		/**
		 * Clear the unseen-notice queue (invoked by the dismiss AJAX handler).
		 *
		 * @since 1.2.0
		 * @return void
		 */
		public static function clear_notices() {
			delete_option( self::NOTICE_OPTION );
		}

		/**
		 * Flag handler: daily per-tool token overage.
		 *
		 * @since 1.2.0
		 *
		 * @param int    $user_id   User ID.
		 * @param string $tool_slug Tool slug.
		 * @param int    $usage     Tokens used.
		 * @param int    $limit     Daily limit.
		 * @param string $reset_time Reset time in "Y-m-d H:i:s" format.
		 * @param string $tier      User tier.
		 * @return void
		 */
		public static function on_tool_token_limit_exceeded( $user_id, $tool_slug, $usage, $limit, $reset_time, $tier ) {
			$released_at = strtotime( (string) $reset_time );
			if ( false === $released_at || $released_at <= time() ) {
				// Fall back to midnight tomorrow when the reset time is unparseable.
				$released_at = strtotime( 'tomorrow midnight', time() );
			}

			self::flag(
				absint( $user_id ),
				self::TYPE_TOKEN_OVERAGE,
				array(
					'scope'       => 'tool',
					'tool_slug'   => sanitize_key( $tool_slug ),
					'usage'       => absint( $usage ),
					'limit'       => absint( $limit ),
					'tier'        => sanitize_key( $tier ),
					'released_at' => absint( $released_at ),
					'reason'      => sprintf(
						/* translators: 1: tool slug, 2: tokens used, 3: daily limit */
						__( 'Daily token limit reached for %1$s (%2$d of %3$d tokens).', 'mcp-ai-wpoos' ),
						sanitize_key( $tool_slug ),
						absint( $usage ),
						absint( $limit )
					),
				)
			);
		}

		/**
		 * Flag handler: per-session token budget.
		 *
		 * @since 1.2.0
		 *
		 * @param int    $user_id    User ID.
		 * @param string $session_id Session identifier.
		 * @param int    $usage      Tokens used.
		 * @param int    $limit      Session limit.
		 * @return void
		 */
		public static function on_per_session_limit_exceeded( $user_id, $session_id, $usage, $limit ) {
			self::flag(
				absint( $user_id ),
				self::TYPE_SESSION_LIMIT,
				array(
					'scope'       => 'session',
					'session_id'  => sanitize_text_field( $session_id ),
					'usage'       => absint( $usage ),
					'limit'       => absint( $limit ),
					'released_at' => time() + self::SESSION_LIMIT_GRACE_SECONDS,
					'reason'      => __( 'Per-session token budget exhausted.', 'mcp-ai-wpoos' ),
				)
			);
		}

		/**
		 * Flag handler: chat rate-limit window exhausted.
		 *
		 * Keys follow the "chat:{userId}:{assistantId}" shape emitted by the
		 * ChatOrchestrator through the WordPress rate-limiter adapter.
		 *
		 * @since 1.2.0
		 *
		 * @param string $key           Raw rate-limit key.
		 * @param int    $max_requests  Requests allowed per window.
		 * @param int    $window_seconds Window length in seconds.
		 * @return void
		 */
		public static function on_rate_limit_exceeded( $key, $max_requests, $window_seconds ) {
			$key = sanitize_text_field( (string) $key );

			if ( ! preg_match( '/^chat:(\d+):(\d*)$/', $key, $matches ) ) {
				return;
			}

			$user_id = absint( $matches[1] );
			if ( $user_id <= 0 ) {
				return;
			}

			$window_seconds = max( 60, absint( $window_seconds ) );

			self::flag(
				$user_id,
				self::TYPE_RATE_LIMIT,
				array(
					'scope'        => 'chat',
					'assistant_id' => isset( $matches[2] ) ? absint( $matches[2] ) : 0,
					'limit'        => absint( $max_requests ),
					'window'       => $window_seconds,
					'released_at'  => time() + $window_seconds,
					'reason'       => sprintf(
						/* translators: 1: request limit, 2: window in seconds */
						__( 'Chat rate limit reached (%1$d requests per %2$d seconds).', 'mcp-ai-wpoos' ),
						absint( $max_requests ),
						$window_seconds
					),
				)
			);
		}

		/**
		 * Flag handler: general REST request-limit window exhausted.
		 *
		 * Fired by WP_MCP_AI_REST::check_rate_limit() when a user exhausts
		 * their configured per-window request budget. Guest (user_id=0)
		 * blocks are IP-keyed and cannot be attached to a user record, so
		 * they are ignored here and left to expire on their own.
		 *
		 * @since 1.1.71
		 *
		 * @param int $user_id       Affected user ID (0 for guests — ignored).
		 * @param int $max_requests  Requests allowed per window.
		 * @param int $window_seconds Window length in seconds.
		 * @param int $current_count Requests consumed in the current window.
		 * @param int $window_end    Unix timestamp at which the fixed window ends.
		 * @return void
		 */
		public static function on_rest_request_rate_limit_exceeded( $user_id, $max_requests, $window_seconds, $current_count, $window_end ) {
			$user_id = (int) $user_id;
			if ( $user_id <= 0 ) {
				return;
			}

			$window_seconds = max( 1, absint( $window_seconds ) );
			$window_end     = absint( $window_end );
			if ( $window_end <= time() ) {
				$window_end = time() + $window_seconds;
			}

			self::flag(
				$user_id,
				self::TYPE_RATE_LIMIT,
				array(
					'scope'       => 'rest',
					'limit'       => absint( $max_requests ),
					'window'      => $window_seconds,
					'usage'       => absint( $current_count ),
					'released_at' => $window_end,
					'reason'      => sprintf(
						/* translators: 1: request limit, 2: window in seconds */
						__( 'REST request rate limit reached (%1$d requests per %2$d seconds).', 'mcp-ai-wpoos' ),
						absint( $max_requests ),
						$window_seconds
					),
				)
			);
		}
	}
}
