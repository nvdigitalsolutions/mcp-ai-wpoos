<?php
/**
 * Composio tools — shared static helpers.
 *
 * Connection resolution, client construction, toolkit-allowlist checks and
 * health-aware connected-account selection, shared by the seven composio_* MCP
 * tools.
 *
 * PHP 8.1+ only (Pro addon).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composio tools helper.
 */
class WP_MCP_AI_Composio_Tools {

	/**
	 * Prefix of a Composio connected-account nanoid (an end user's authenticated
	 * Gmail/Slack/GitHub account), e.g. `ca_F0HEJBssnCXL`.
	 */
	const ACCOUNT_ID_PREFIX = 'ca_';

	/**
	 * Prefix of an NV oOS remote-site connection ID (this site's Composio
	 * project integration), e.g. `conn_2ezeknzxsuzq`.
	 */
	const CONNECTION_ID_PREFIX = 'conn_';

	/**
	 * Whether a value looks like a Composio connected-account nanoid.
	 *
	 * @since 1.4.1
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public static function looks_like_account_id( $value ) {
		return is_scalar( $value ) && 0 === stripos( (string) $value, self::ACCOUNT_ID_PREFIX );
	}

	/**
	 * Whether a value looks like an NV oOS remote-site connection ID.
	 *
	 * @since 1.4.1
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public static function looks_like_connection_id( $value ) {
		return is_scalar( $value ) && 0 === stripos( (string) $value, self::CONNECTION_ID_PREFIX );
	}

	/**
	 * Reject a connected-account ID that is actually a connection ID.
	 *
	 * `connection_id` and `connected_account_id` are both opaque strings, so a
	 * model can silently swap them and get a misleading "not found". Detecting
	 * the swap by prefix turns a dead end into a self-correcting error.
	 *
	 * @since 1.4.1
	 *
	 * @param string $account_id Supplied connected-account ID.
	 * @return true|WP_Error True when the value is usable.
	 */
	public static function validate_account_id( $account_id ) {
		if ( '' === (string) $account_id || ! self::looks_like_connection_id( $account_id ) ) {
			return true;
		}

		return new WP_Error(
			'wp_mcp_ai_composio_id_swapped',
			sprintf(
				/* translators: %s: the ID that was supplied */
				__( '"%s" is an NV oOS connection ID, not a Composio connected-account ID. Pass it as connection_id instead. connected_account_id must be a Composio account nanoid that starts with "ca_" — run composio_list_connected_accounts to get one, or omit connected_account_id to auto-resolve the best account for the toolkit.', 'mcp-ai-wpoos-pro' ),
				$account_id
			),
			array(
				'supplied'      => $account_id,
				'supplied_kind' => 'connection_id',
				'expected_kind' => 'connected_account_id',
				'expected_form' => self::ACCOUNT_ID_PREFIX . '...',
			)
		);
	}

	/**
	 * Ensure the Remote Site Manager class is loaded.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public static function maybe_load_manager() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) && defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$manager_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
			if ( file_exists( $manager_file ) ) {
				require_once $manager_file;
			}
		}
	}

	/**
	 * Resolve the target Composio connection from tool arguments.
	 *
	 * Falls back to the first enabled composio connection when no explicit
	 * connection_id argument is supplied.
	 *
	 * `connection_id` identifies *this site's* Composio project integration
	 * (`conn_...`); it is not a Composio connected-account nanoid (`ca_...`).
	 * Because both are opaque strings the two are easy to swap, so a swapped
	 * value is detected by prefix and reported as such instead of surfacing as
	 * "connection not found".
	 *
	 * @since 1.4.0
	 *
	 * @param array      $arguments  Tool arguments.
	 * @param array|null $connection Output. Resolved connection record.
	 * @return WP_Error|array Connection record or error.
	 */
	public static function resolve_connection( array $arguments, &$connection = null ) {
		self::maybe_load_manager();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_manager', __( 'The Remote Site Manager is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection = null;

		if ( ! empty( $arguments['connection_id'] ) ) {
			$supplied = $arguments['connection_id'];

			// The most common mistake: a `ca_...` connected-account nanoid passed
			// where the project connection was expected. Say so plainly.
			if ( self::looks_like_account_id( $supplied ) ) {
				return new WP_Error(
					'wp_mcp_ai_composio_id_swapped',
					sprintf(
						/* translators: %s: the ID that was supplied */
						__( '"%s" is a Composio connected-account ID, not an NV oOS connection ID. Either omit connection_id entirely (the enabled Composio connection is resolved automatically), or pass this value as connected_account_id on a tool that accepts one. NV oOS connection IDs start with "conn_".', 'mcp-ai-wpoos-pro' ),
						is_scalar( $supplied ) ? (string) $supplied : ''
					),
					array(
						'supplied'      => is_scalar( $supplied ) ? (string) $supplied : '',
						'supplied_kind' => 'connected_account_id',
						'expected_kind' => 'connection_id',
						'expected_form' => self::CONNECTION_ID_PREFIX . '...',
						'available'     => self::get_available_connection_ids(),
						'suggested_fix' => __( 'Retry without connection_id.', 'mcp-ai-wpoos-pro' ),
					)
				);
			}

			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( sanitize_key( $supplied ) );
			if ( null === $connection || 'composio' !== ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) {
				$available = self::get_available_connection_ids();

				return new WP_Error(
					'wp_mcp_ai_composio_invalid_connection',
					empty( $available )
						? __( 'Composio connection not found, and no Composio connection exists on this site. Create one in Remote Sites first.', 'mcp-ai-wpoos-pro' )
						: sprintf(
							/* translators: 1: supplied ID, 2: comma-separated list of valid connection IDs */
							__( 'No Composio connection matches "%1$s". Available Composio connection IDs: %2$s. You can also omit connection_id to use the first enabled one.', 'mcp-ai-wpoos-pro' ),
							is_scalar( $supplied ) ? (string) $supplied : '',
							implode( ', ', $available )
						),
					array(
						'supplied'  => is_scalar( $supplied ) ? (string) $supplied : '',
						'available' => $available,
					)
				);
			}
		} else {
			$all = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
			foreach ( $all as $candidate ) {
				if ( 'composio' === ( isset( $candidate['connection_type'] ) ? $candidate['connection_type'] : '' ) && ! empty( $candidate['enabled'] ) ) {
					$connection = $candidate;
					break;
				}
			}

			if ( null === $connection ) {
				return new WP_Error( 'wp_mcp_ai_composio_no_connection', __( 'No enabled Composio connection found. Create one in the Remote Site Manager.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		return $connection;
	}

	/**
	 * List the Composio connection IDs configured on this site.
	 *
	 * Included in resolution errors so a caller that guessed wrong can retry
	 * with a real value instead of guessing again.
	 *
	 * @since 1.4.1
	 *
	 * @return array List of connection IDs.
	 */
	public static function get_available_connection_ids() {
		self::maybe_load_manager();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return array();
		}

		$ids = array();

		foreach ( WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections() as $candidate ) {
			if ( ! is_array( $candidate ) || 'composio' !== ( isset( $candidate['connection_type'] ) ? $candidate['connection_type'] : '' ) ) {
				continue;
			}

			if ( ! empty( $candidate['id'] ) ) {
				$ids[] = (string) $candidate['id'];
			}
		}

		return $ids;
	}

	/**
	 * Build a client instance for a resolved connection.
	 *
	 * @since 1.4.0
	 *
	 * @param array $connection Composio connection record.
	 * @return WP_MCP_AI_Composio_Client
	 */
	public static function build_client( array $connection ) {
		return WP_MCP_AI_Composio_Client::from_connection( $connection );
	}

	/**
	 * Resolve the Composio user identity for a connection.
	 *
	 * Shared (site-wide) connections resolve to one identity for the whole
	 * site; per-user connections resolve to the WordPress user's own identity.
	 * Every tool call that targets a connected account must carry this value.
	 *
	 * @since 1.4.0
	 *
	 * @param array $connection Composio connection record.
	 * @param int   $wp_user_id Optional. WordPress user ID (0 = current user).
	 * @return string
	 */
	public static function resolve_user_id( array $connection, $wp_user_id = 0 ) {
		if ( ! class_exists( 'WP_MCP_AI_Composio_Auth_Handler' ) ) {
			return isset( $connection['default_user_id'] ) ? sanitize_text_field( (string) $connection['default_user_id'] ) : '';
		}

		return WP_MCP_AI_Composio_Auth_Handler::resolve_user_id( $connection, $wp_user_id );
	}

	/**
	 * Check whether a toolkit is allowed for a connection.
	 *
	 * An empty allowlist permits every toolkit.
	 *
	 * @since 1.4.0
	 *
	 * @param array  $connection Composio connection record.
	 * @param string $toolkit    Toolkit slug.
	 * @return bool
	 */
	public static function is_toolkit_allowed( array $connection, $toolkit ) {
		$allowlist = isset( $connection['toolkit_allowlist'] ) && is_array( $connection['toolkit_allowlist'] ) ? $connection['toolkit_allowlist'] : array();

		if ( empty( $allowlist ) ) {
			return true;
		}

		return in_array( sanitize_key( (string) $toolkit ), array_map( 'sanitize_key', $allowlist ), true );
	}

	/**
	 * Extract the toolkit slug from a SCREAMING_SNAKE tool slug.
	 *
	 * Composio slugs are `{TOOLKIT}_{VERB}_{ACTION}` by construction, so the
	 * first segment is the toolkit.
	 *
	 * @since 1.4.1
	 *
	 * @param string $tool_slug Tool slug.
	 * @return string Lowercase toolkit slug.
	 */
	public static function toolkit_from_tool_slug( $tool_slug ) {
		$parts = explode( '_', (string) $tool_slug );

		return isset( $parts[0] ) ? strtolower( $parts[0] ) : '';
	}

	/**
	 * Pick the connected account most likely to actually work for a toolkit.
	 *
	 * "First account whose stored status is ACTIVE" is not safe: Composio's
	 * stored status lags a revoked credential, so the first ACTIVE account can
	 * be dead. This resolver ranks candidates by evidence instead:
	 *
	 *  1. accounts known-dead by a recent probe are excluded outright;
	 *  2. an account owned by the connection's resolved identity is preferred;
	 *  3. a probe-verified account beats an unverified one, and a freshly
	 *     verified account beats a stale one;
	 *  4. ties break on most-recently validated, then most-recently updated.
	 *
	 * When two or more candidates remain indistinguishable the result is flagged
	 * `ambiguous` with the full candidate list, so callers can refuse to guess on
	 * a destructive action.
	 *
	 * @since 1.4.1
	 *
	 * @param WP_MCP_AI_Composio_Client $client     Client bound to the connection.
	 * @param array                     $connection Connection record.
	 * @param string                    $toolkit    Toolkit slug.
	 * @param string                    $user_id    Resolved Composio identity.
	 * @return array|WP_Error {
	 *     @type string $id         Chosen connected account nanoid.
	 *     @type string $user_id    Identity that owns the chosen account.
	 *     @type string $status     Stored Composio status.
	 *     @type array  $health     Stored health record for the chosen account.
	 *     @type bool   $ambiguous  Whether the choice was a coin-flip.
	 *     @type array  $candidates Every viable candidate, best first.
	 * }
	 */
	public static function resolve_account_for_toolkit( $client, array $connection, $toolkit, $user_id ) {
		$connection_id = isset( $connection['id'] ) ? (string) $connection['id'] : '';
		$toolkit       = sanitize_key( (string) $toolkit );

		$accounts = $client->list_connected_accounts( array( 'toolkit' => $toolkit ) );

		if ( is_wp_error( $accounts ) ) {
			return $accounts;
		}

		$health_available = class_exists( 'WP_MCP_AI_Composio_Account_Health' );
		$viable           = array();
		$known_dead       = array();

		foreach ( $accounts as $account ) {
			if ( ! is_array( $account ) || empty( $account['id'] ) ) {
				continue;
			}

			$account_id = (string) $account['id'];
			$status     = isset( $account['status'] ) ? strtoupper( (string) $account['status'] ) : '';

			if ( 'ACTIVE' !== $status || ! empty( $account['disabled'] ) ) {
				continue;
			}

			$owner  = isset( $account['user_id'] ) ? (string) $account['user_id'] : '';
			$health = $health_available ? WP_MCP_AI_Composio_Account_Health::get( $connection_id, $account_id ) : array();

			$entry = array(
				'id'      => $account_id,
				'user_id' => $owner,
				'status'  => $status,
				'alias'   => isset( $account['alias'] ) ? (string) $account['alias'] : '',
				'toolkit' => isset( $account['toolkit'] ) ? (string) $account['toolkit'] : $toolkit,
				'health'  => $health,
			);

			// A recent probe that came back "reconnect required" is hard evidence
			// that this credential is dead, regardless of the stored status.
			if ( ! empty( $health['needs_reconnect'] ) && $health_available && ! WP_MCP_AI_Composio_Account_Health::is_stale( $health ) ) {
				$known_dead[] = $entry;
				continue;
			}

			$score = 0;

			if ( '' !== $user_id && $owner === $user_id ) {
				$score += 8;
			}

			if ( ! empty( $health['verified'] ) ) {
				$score += $health_available && WP_MCP_AI_Composio_Account_Health::is_stale( $health ) ? 2 : 4;
			}

			$entry['score']        = $score;
			$entry['validated_at'] = isset( $health['validated_at'] ) ? absint( $health['validated_at'] ) : 0;
			$entry['updated_at']   = isset( $account['updated_at'] ) ? (int) strtotime( (string) $account['updated_at'] ) : 0;

			$viable[] = $entry;
		}

		if ( empty( $viable ) ) {
			return self::no_account_error( $connection_id, $toolkit, $user_id, $known_dead );
		}

		usort(
			$viable,
			static function ( $a, $b ) {
				if ( $a['score'] !== $b['score'] ) {
					return $b['score'] <=> $a['score'];
				}
				if ( $a['validated_at'] !== $b['validated_at'] ) {
					return $b['validated_at'] <=> $a['validated_at'];
				}

				return $b['updated_at'] <=> $a['updated_at'];
			}
		);

		$best = $viable[0];
		$tied = 0;
		foreach ( $viable as $candidate ) {
			if ( $candidate['score'] === $best['score'] && 0 === $candidate['validated_at'] && 0 === $best['validated_at'] ) {
				++$tied;
			}
		}

		$best['ambiguous']  = $tied > 1;
		$best['candidates'] = $viable;
		$best['known_dead'] = $known_dead;

		return $best;
	}

	/**
	 * Build the "no usable account" error, naming dead accounts when known.
	 *
	 * @since 1.4.1
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $toolkit       Toolkit slug.
	 * @param string $user_id       Resolved Composio identity.
	 * @param array  $known_dead    Accounts excluded because a probe failed.
	 * @return WP_Error
	 */
	private static function no_account_error( $connection_id, $toolkit, $user_id, array $known_dead ) {
		if ( ! empty( $known_dead ) ) {
			$ids = array();
			foreach ( $known_dead as $dead ) {
				$ids[] = $dead['id'];
			}

			$hint = class_exists( 'WP_MCP_AI_Composio_Account_Health' )
				? WP_MCP_AI_Composio_Account_Health::build_reconnect_hint( $connection_id, $toolkit, implode( ', ', $ids ) )
				: array(
					'message'       => '',
					'reconnect_url' => '',
				);

			return new WP_Error(
				'wp_mcp_ai_composio_account_auth_required',
				sprintf(
					/* translators: 1: toolkit slug, 2: comma-separated account IDs, 3: remediation sentence */
					__( 'Every %1$s account on this connection (%2$s) failed its last credential check. %3$s', 'mcp-ai-wpoos-pro' ),
					$toolkit,
					implode( ', ', $ids ),
					isset( $hint['message'] ) ? $hint['message'] : ''
				),
				array(
					'toolkit'         => $toolkit,
					'dead_accounts'   => $ids,
					'reconnect_url'   => isset( $hint['reconnect_url'] ) ? $hint['reconnect_url'] : '',
					'needs_reconnect' => true,
				)
			);
		}

		$reconnect_url = class_exists( 'WP_MCP_AI_Composio_Account_Health' )
			? WP_MCP_AI_Composio_Account_Health::build_reconnect_url( $connection_id, $toolkit )
			: '';

		return new WP_Error(
			'wp_mcp_ai_composio_no_active_account',
			sprintf(
				/* translators: 1: toolkit slug, 2: Composio user identity */
				__( 'No active connected account found for toolkit %1$s (identity %2$s). Connect the app from Remote Sites → your Composio connection, then try again.', 'mcp-ai-wpoos-pro' ),
				$toolkit,
				'' !== $user_id ? $user_id : __( 'unset', 'mcp-ai-wpoos-pro' )
			),
			array(
				'toolkit'       => $toolkit,
				'reconnect_url' => $reconnect_url,
			)
		);
	}

	/**
	 * Present a candidate list for tool output.
	 *
	 * @since 1.4.1
	 *
	 * @param array $candidates Candidate entries from resolve_account_for_toolkit().
	 * @return array Escaped candidate summaries.
	 */
	public static function present_candidates( array $candidates ) {
		$out = array();

		foreach ( $candidates as $candidate ) {
			$out[] = array(
				'id'       => esc_html( isset( $candidate['id'] ) ? (string) $candidate['id'] : '' ),
				'alias'    => esc_html( isset( $candidate['alias'] ) ? (string) $candidate['alias'] : '' ),
				'user_id'  => esc_html( isset( $candidate['user_id'] ) ? (string) $candidate['user_id'] : '' ),
				'verified' => ! empty( $candidate['health']['verified'] ),
			);
		}

		return $out;
	}
}
