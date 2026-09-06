<?php
/**
 * Tool: composio_manage_accounts — connected-account lifecycle and health.
 *
 * Validate, reconnect, disable, enable, delete and prune Composio connected
 * accounts. Splitting these off from the read-only listing keeps
 * `composio_list_connected_accounts` a safe, low-risk discovery tool while the
 * state-changing operations live behind one explicitly destructive surface.
 *
 * Pro tool (PHP 8.1+). Requires manage_options capability.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.4.1
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composio — Manage connected accounts.
 */
class WP_MCP_AI_Tool_Composio_Manage_Accounts implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Sensitive_Result_Interface {
	use WP_MCP_AI_Tool_Envelope;

	/**
	 * Allowed actions.
	 */
	const ACTIONS = array( 'validate', 'reconnect', 'disable', 'enable', 'delete', 'prune' );

	/**
	 * Actions that mutate remote state.
	 */
	const DESTRUCTIVE_ACTIONS = array( 'reconnect', 'disable', 'enable', 'delete', 'prune' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'composio_manage_accounts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'Composio — Manage Connected Accounts', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Manage the lifecycle of Composio connected accounts. "validate" probes an account against the live provider and returns a verified verdict with last_validated_at and last_error. "reconnect" re-authorises the SAME account in place (no orphaned duplicate) and returns a URL for the user to finish the flow. "delete" removes an account and revokes its upstream credentials. "prune" deletes every account for a toolkit that failed its last credential check. "disable"/"enable" toggle an account without deleting it.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'               => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: validate, reconnect, disable, enable, delete, prune.', 'mcp-ai-wpoos-pro' ),
					'enum'        => self::ACTIONS,
				),
				'connected_account_id' => array(
					'type'        => 'string',
					'description' => __( 'Composio connected-account nanoid ("ca_...") — the end user\'s authenticated app account, from composio_list_connected_accounts. NOT a "conn_..." connection ID. Required for every action except prune. For validate it may be omitted when toolkit is supplied, in which case every account for that toolkit is validated.', 'mcp-ai-wpoos-pro' ),
				),
				'toolkit'              => array(
					'type'        => 'string',
					'description' => __( 'Toolkit slug (e.g. "gmail"). Required for prune; optional elsewhere to validate a whole toolkit at once.', 'mcp-ai-wpoos-pro' ),
				),
				'revoke'               => array(
					'type'        => 'boolean',
					'description' => __( 'For delete/prune: also ask Composio to revoke the upstream provider credentials. Default true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'connection_id'        => array(
					'type'        => 'string',
					'description' => __( 'Optional NV oOS Composio connection ID ("conn_..."), identifying this site\'s Composio project integration. NOT a connected-account ID — do not pass a "ca_..." value here. Omit it to use the first enabled Composio connection.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability(): string {
		return 'manage_options';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1 — Sanitize at entry.
		$action     = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';
		$account_id = isset( $arguments['connected_account_id'] ) ? sanitize_text_field( $arguments['connected_account_id'] ) : '';
		$toolkit    = isset( $arguments['toolkit'] ) ? sanitize_key( $arguments['toolkit'] ) : '';
		$revoke     = ! isset( $arguments['revoke'] ) || ! empty( $arguments['revoke'] );

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new WP_Error( 'invalid_action', __( 'Invalid account action. Use one of: validate, reconnect, disable, enable, delete, prune.', 'mcp-ai-wpoos-pro' ) );
		}

		// Catch a connection ID passed where an account ID belongs.
		$account_check = WP_MCP_AI_Composio_Tools::validate_account_id( $account_id );

		if ( is_wp_error( $account_check ) ) {
			return $account_check;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Composio_Account_Health' ) ) {
			return new WP_Error( 'wp_mcp_ai_composio_health_unavailable', __( 'The Composio account-health engine is not loaded.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection = null;
		$resolved   = WP_MCP_AI_Composio_Tools::resolve_connection( $arguments, $connection );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		if ( '' !== $toolkit && ! WP_MCP_AI_Composio_Tools::is_toolkit_allowed( $connection, $toolkit ) ) {
			return new WP_Error( 'wp_mcp_ai_composio_toolkit_denied', __( 'This toolkit is not in the connection allowlist.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( 'prune' === $action && '' === $toolkit ) {
			return new WP_Error( 'missing_params', __( 'toolkit is required for prune so the blast radius is explicit.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( 'prune' !== $action && 'validate' !== $action && '' === $account_id ) {
			return new WP_Error( 'missing_params', __( 'connected_account_id is required for this action.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( 'validate' === $action && '' === $account_id && '' === $toolkit ) {
			return new WP_Error( 'missing_params', __( 'Supply connected_account_id or toolkit to validate.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection_id = isset( $connection['id'] ) ? (string) $connection['id'] : '';
		$client        = WP_MCP_AI_Composio_Tools::build_client( $connection );

		switch ( $action ) {
			case 'validate':
				$result = $this->do_validate( $client, $connection_id, $account_id, $toolkit );
				break;

			case 'reconnect':
				$result = $this->do_reconnect( $client, $connection, $connection_id, $account_id );
				break;

			case 'delete':
				$result = $this->do_delete( $client, $connection_id, $account_id, $revoke );
				break;

			case 'prune':
				$result = $this->do_prune( $client, $connection, $connection_id, $toolkit, $revoke );
				break;

			case 'disable':
			case 'enable':
				$result = $this->do_toggle( $client, $connection_id, $account_id, $action );
				break;

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid account action.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( in_array( $action, self::DESTRUCTIVE_ACTIONS, true ) ) {
			WP_MCP_AI_Composio_Client::clear_accounts_cache( $connection_id );

			do_action(
				'wp_mcp_ai_composio_account_managed',
				array(
					'connection_id' => $connection_id,
					'action'        => $action,
					'account_id'    => $account_id,
					'toolkit'       => $toolkit,
					'user_id'       => get_current_user_id(),
				)
			);
		}

		// Gate 2 — Escape at exit.
		return $this->format_success_response(
			$result['message'],
			array_merge(
				array(
					'action'      => esc_html( $action ),
					'destructive' => in_array( $action, self::DESTRUCTIVE_ACTIONS, true ),
				),
				$result['data']
			)
		);
	}

	/**
	 * Validate one account, or every account for a toolkit.
	 *
	 * @since 1.4.1
	 *
	 * @param WP_MCP_AI_Composio_Client $client        Client instance.
	 * @param string                    $connection_id Connection ID.
	 * @param string                    $account_id    Connected account nanoid, or ''.
	 * @param string                    $toolkit       Toolkit slug, or ''.
	 * @return array|WP_Error
	 */
	private function do_validate( $client, $connection_id, $account_id, $toolkit ) {
		$targets = array();

		if ( '' !== $account_id ) {
			$targets[] = array(
				'id'      => $account_id,
				'toolkit' => $toolkit,
			);
		} else {
			$accounts = $client->list_connected_accounts( array( 'toolkit' => $toolkit ) );

			if ( is_wp_error( $accounts ) ) {
				return $accounts;
			}

			foreach ( $accounts as $account ) {
				if ( is_array( $account ) && ! empty( $account['id'] ) ) {
					$targets[] = array(
						'id'      => (string) $account['id'],
						'toolkit' => isset( $account['toolkit'] ) ? (string) $account['toolkit'] : $toolkit,
					);
				}
			}

			if ( empty( $targets ) ) {
				return new WP_Error(
					'wp_mcp_ai_composio_no_accounts',
					sprintf(
						/* translators: %s: toolkit slug */
						__( 'No connected accounts exist for toolkit %s.', 'mcp-ai-wpoos-pro' ),
						$toolkit
					)
				);
			}
		}

		$results = array();
		$healthy = 0;
		$broken  = 0;

		foreach ( $targets as $target ) {
			// No `account` override and no cache reuse: an explicit validation
			// request must always perform the authoritative read plus a live
			// probe, otherwise it is the same false positive again.
			$record = WP_MCP_AI_Composio_Account_Health::probe(
				$client,
				$connection_id,
				$target['id'],
				array( 'toolkit' => $target['toolkit'] )
			);

			if ( is_wp_error( $record ) ) {
				return $record;
			}

			$health = WP_MCP_AI_Composio_Account_Health::present( $record );

			if ( ! empty( $health['verified'] ) ) {
				++$healthy;
			} else {
				++$broken;
			}

			$results[] = array(
				'id'               => esc_html( $target['id'] ),
				'toolkit'          => esc_html( isset( $record['toolkit'] ) ? (string) $record['toolkit'] : $target['toolkit'] ),
				'status'           => esc_html( isset( $record['status'] ) ? (string) $record['status'] : '' ),
				'token_expires_at' => esc_html( isset( $record['expires_at'] ) ? (string) $record['expires_at'] : '' ),
				'health'           => $health,
				'reconnect_url'    => ! empty( $health['needs_reconnect'] )
					? esc_url( WP_MCP_AI_Composio_Account_Health::build_reconnect_url( $connection_id, isset( $record['toolkit'] ) ? (string) $record['toolkit'] : $target['toolkit'] ) )
					: '',
			);
		}

		if ( 1 === count( $results ) ) {
			$only    = $results[0];
			$message = ! empty( $only['health']['verified'] )
				? sprintf(
					/* translators: 1: account ID, 2: probe tool slug */
					__( 'Account %1$s is verified working — a live %2$s call succeeded just now.', 'mcp-ai-wpoos-pro' ),
					$only['id'],
					'' !== $only['health']['probe_tool'] ? $only['health']['probe_tool'] : __( 'read-only', 'mcp-ai-wpoos-pro' )
				)
				: sprintf(
					/* translators: 1: account ID, 2: failure reason */
					__( 'Account %1$s did NOT verify: %2$s', 'mcp-ai-wpoos-pro' ),
					$only['id'],
					'' !== $only['health']['last_error'] ? $only['health']['last_error'] : __( 'unknown reason.', 'mcp-ai-wpoos-pro' )
				);
		} else {
			$message = sprintf(
				/* translators: 1: number verified, 2: number failing */
				__( 'Validated %1$d working account(s) and %2$d failing account(s).', 'mcp-ai-wpoos-pro' ),
				$healthy,
				$broken
			);
		}

		return array(
			'message' => $message,
			'data'    => array(
				'accounts' => $results,
				'verified' => $healthy,
				'failing'  => $broken,
			),
		);
	}

	/**
	 * Re-authorise an existing account in place, with a link fallback.
	 *
	 * @since 1.4.1
	 *
	 * @param WP_MCP_AI_Composio_Client $client        Client instance.
	 * @param array                     $connection    Connection record.
	 * @param string                    $connection_id Connection ID.
	 * @param string                    $account_id    Connected account nanoid.
	 * @return array|WP_Error
	 */
	private function do_reconnect( $client, array $connection, $connection_id, $account_id ) {
		$account = $client->get_connected_account( $account_id, 0 );

		if ( is_wp_error( $account ) ) {
			return $account;
		}

		$toolkit = isset( $account['toolkit'] ) ? (string) $account['toolkit'] : '';

		if ( '' !== $toolkit && ! WP_MCP_AI_Composio_Tools::is_toolkit_allowed( $connection, $toolkit ) ) {
			return new WP_Error( 'wp_mcp_ai_composio_toolkit_denied', __( 'This toolkit is not in the connection allowlist.', 'mcp-ai-wpoos-pro' ) );
		}

		$result       = $client->reconnect_connected_account( $account_id );
		$redirect_url = '';
		$in_place     = false;

		if ( ! is_wp_error( $result ) ) {
			$redirect_url = isset( $result['redirect_url'] ) ? esc_url_raw( (string) $result['redirect_url'] ) : '';
			$in_place     = '' !== $redirect_url;
		}

		// The in-place route is marked Legacy upstream. When it is gone (or the
		// auth scheme does not support it) fall back to a fresh Connect Link so
		// the operator is never left without a path forward — and say plainly
		// that the fallback creates a new account.
		if ( ! $in_place && '' !== $toolkit ) {
			$link = WP_MCP_AI_Composio_Auth_Handler::create_link( $connection_id, $toolkit, get_current_user_id() );

			if ( is_wp_error( $link ) ) {
				return $link;
			}

			// Invalidate the stale verdict: the operator is about to change the
			// credential, so the old one must not be trusted again.
			WP_MCP_AI_Composio_Account_Health::forget( $connection_id, $account_id );

			return array(
				'message' => sprintf(
					/* translators: 1: toolkit slug, 2: existing account ID */
					__( 'In-place re-authorisation is unavailable for %1$s, so a fresh Connect Link was created instead. Completing it will produce a NEW connected account — delete %2$s afterwards with action "delete" (or run "prune") to avoid an orphan.', 'mcp-ai-wpoos-pro' ),
					esc_html( $toolkit ),
					esc_html( $account_id )
				),
				'data'    => array(
					'account_id'  => esc_html( $account_id ),
					'toolkit'     => esc_html( $toolkit ),
					'url'         => esc_url( $link['url'] ),
					'in_place'    => false,
					'creates_new' => true,
					'orphan_risk' => true,
				),
			);
		}

		if ( ! $in_place ) {
			return new WP_Error(
				'wp_mcp_ai_composio_reconnect_failed',
				__( 'Composio did not return a re-authorisation URL and the toolkit for this account could not be determined, so no Connect Link could be created.', 'mcp-ai-wpoos-pro' )
			);
		}

		WP_MCP_AI_Composio_Account_Health::forget( $connection_id, $account_id );

		return array(
			'message' => sprintf(
				/* translators: %s: connected account ID */
				__( 'Re-authorisation started for the existing account %s — its ID, alias and any pinned triggers are preserved. Open the URL to finish, then run action "validate" to confirm.', 'mcp-ai-wpoos-pro' ),
				esc_html( $account_id )
			),
			'data'    => array(
				'account_id'  => esc_html( $account_id ),
				'toolkit'     => esc_html( $toolkit ),
				'url'         => esc_url( $redirect_url ),
				'in_place'    => true,
				'creates_new' => false,
			),
		);
	}

	/**
	 * Delete a single account and drop its health record.
	 *
	 * @since 1.4.1
	 *
	 * @param WP_MCP_AI_Composio_Client $client        Client instance.
	 * @param string                    $connection_id Connection ID.
	 * @param string                    $account_id    Connected account nanoid.
	 * @param bool                      $revoke        Whether to revoke upstream credentials.
	 * @return array|WP_Error
	 */
	private function do_delete( $client, $connection_id, $account_id, $revoke ) {
		$result = $client->delete_connected_account( $account_id, $revoke );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		WP_MCP_AI_Composio_Account_Health::forget( $connection_id, $account_id );

		return array(
			'message' => $revoke
				? sprintf(
					/* translators: %s: connected account ID */
					__( 'Connected account %s deleted and its upstream credentials revoked.', 'mcp-ai-wpoos-pro' ),
					esc_html( $account_id )
				)
				: sprintf(
					/* translators: %s: connected account ID */
					__( 'Connected account %s deleted. Upstream credentials were left in place.', 'mcp-ai-wpoos-pro' ),
					esc_html( $account_id )
				),
			'data'    => array(
				'account_id' => esc_html( $account_id ),
				'revoked'    => (bool) $revoke,
			),
		);
	}

	/**
	 * Delete every account for a toolkit that fails its credential check.
	 *
	 * Each candidate is probed immediately before deletion, so a working
	 * account can never be pruned on the strength of a stale verdict.
	 *
	 * @since 1.4.1
	 *
	 * @param WP_MCP_AI_Composio_Client $client        Client instance.
	 * @param array                     $connection    Connection record.
	 * @param string                    $connection_id Connection ID.
	 * @param string                    $toolkit       Toolkit slug.
	 * @param bool                      $revoke        Whether to revoke upstream credentials.
	 * @return array|WP_Error
	 */
	private function do_prune( $client, array $connection, $connection_id, $toolkit, $revoke ) {
		$accounts = $client->list_connected_accounts( array( 'toolkit' => $toolkit ) );

		if ( is_wp_error( $accounts ) ) {
			return $accounts;
		}

		$deleted = array();
		$kept    = array();
		$failed  = array();

		foreach ( $accounts as $account ) {
			if ( ! is_array( $account ) || empty( $account['id'] ) ) {
				continue;
			}

			$id     = (string) $account['id'];
			$record = WP_MCP_AI_Composio_Account_Health::probe(
				$client,
				$connection_id,
				$id,
				array(
					'account' => $account,
					'toolkit' => $toolkit,
				)
			);

			// Only a definitive "needs reconnect" verdict authorises deletion.
			// An inconclusive probe leaves the account alone.
			if ( is_wp_error( $record ) || empty( $record['needs_reconnect'] ) ) {
				$kept[] = esc_html( $id );
				continue;
			}

			$result = $client->delete_connected_account( $id, $revoke );

			if ( is_wp_error( $result ) ) {
				$failed[] = esc_html( $id );
				continue;
			}

			WP_MCP_AI_Composio_Account_Health::forget( $connection_id, $id );
			$deleted[] = esc_html( $id );
		}

		return array(
			'message' => sprintf(
				/* translators: 1: number deleted, 2: toolkit slug, 3: number kept */
				__( 'Pruned %1$d dead %2$s account(s); %3$d healthy or unconfirmed account(s) were left untouched.', 'mcp-ai-wpoos-pro' ),
				count( $deleted ),
				esc_html( $toolkit ),
				count( $kept )
			),
			'data'    => array(
				'toolkit' => esc_html( $toolkit ),
				'deleted' => $deleted,
				'kept'    => $kept,
				'failed'  => $failed,
				'revoked' => (bool) $revoke,
			),
		);
	}

	/**
	 * Enable or disable an account without deleting it.
	 *
	 * @since 1.4.1
	 *
	 * @param WP_MCP_AI_Composio_Client $client        Client instance.
	 * @param string                    $connection_id Connection ID.
	 * @param string                    $account_id    Connected account nanoid.
	 * @param string                    $action        "enable" or "disable".
	 * @return array|WP_Error
	 */
	private function do_toggle( $client, $connection_id, $account_id, $action ) {
		$status = 'disable' === $action ? 'inactive' : 'active';
		$result = $client->set_connected_account_status( $account_id, $status );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The stored verdict describes a credential in a different state now.
		WP_MCP_AI_Composio_Account_Health::forget( $connection_id, $account_id );

		return array(
			'message' => 'disable' === $action
				? sprintf(
					/* translators: %s: connected account ID */
					__( 'Connected account %s disabled. It will no longer be auto-selected for tool execution.', 'mcp-ai-wpoos-pro' ),
					esc_html( $account_id )
				)
				: sprintf(
					/* translators: %s: connected account ID */
					__( 'Connected account %s enabled. Run action "validate" to confirm the credential still works.', 'mcp-ai-wpoos-pro' ),
					esc_html( $account_id )
				),
			'data'    => array(
				'account_id' => esc_html( $account_id ),
				'status'     => esc_html( $status ),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'write', 'state-changing', 'destructive', 'pro', 'requires-capability', 'remote-api', 'sensitive-data' );
	}

	/**
	 * The reconnect action returns a hosted re-authorisation URL whose path can
	 * itself be the bearer capability (both the upstream `redirect_url` and the
	 * `lk_...` fallback Connect Link).
	 *
	 * {@inheritdoc}
	 */
	public function get_sensitive_result_fields(): array {
		return array( 'data.url' );
	}

	/**
	 * Get extended tool definition.
	 *
	 * @return array
	 */
	public function get_definition(): array {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'composio',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'risk_level'            => 'high',
		);
	}
}
