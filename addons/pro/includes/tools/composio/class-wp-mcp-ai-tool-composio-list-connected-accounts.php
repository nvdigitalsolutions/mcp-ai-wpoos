<?php
/**
 * Tool: composio_list_connected_accounts — list and verify app connections.
 *
 * Pro tool (PHP 8.1+). Requires manage_options capability.
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
 * Composio — List connected accounts.
 */
class WP_MCP_AI_Tool_Composio_List_Connected_Accounts implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Envelope;

	/**
	 * Maximum accounts probed in a single listing call.
	 *
	 * A probe costs one tool execution per account, so a project with dozens of
	 * connections would otherwise turn a listing into an expensive fan-out.
	 * Accounts beyond the cap are reported with their last stored verdict.
	 */
	const MAX_VERIFY = 10;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'composio_list_connected_accounts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'Composio — List Connected Accounts', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'List the Composio connected accounts (Gmail, Slack, GitHub, ...) with *verified* health, not just Composio\'s stored status. By default each account is probed with a harmless read-only call so a revoked token is reported as broken instead of "active"; every entry carries last_validated_at, last_error, credential expiry and a needs_reconnect flag. Set verify to false for a cheap stored-status-only listing.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'toolkit'       => array(
					'type'        => 'string',
					'description' => __( 'Optional toolkit filter (e.g. "gmail").', 'mcp-ai-wpoos-pro' ),
				),
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'Optional Composio status filter.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'ACTIVE', 'INACTIVE', 'INITIALIZING', 'INITIATED', 'FAILED', 'EXPIRED', 'REVOKED' ),
				),
				'verify'        => array(
					'type'        => 'boolean',
					'description' => __( 'Probe each account against the live provider to confirm the credential still works. Default true. Verdicts newer than 15 minutes are reused instead of re-probed.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'force'         => array(
					'type'        => 'boolean',
					'description' => __( 'Re-probe even when a recent verdict exists. Default false.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional NV oOS Composio connection ID ("conn_..."), identifying this site\'s Composio project integration. NOT a connected-account ID — do not pass a "ca_..." value here. Omit it to use the first enabled Composio connection.', 'mcp-ai-wpoos-pro' ),
				),
			),
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
		$toolkit = isset( $arguments['toolkit'] ) ? sanitize_key( $arguments['toolkit'] ) : '';
		$status  = isset( $arguments['status'] ) ? strtoupper( sanitize_key( $arguments['status'] ) ) : '';
		$verify  = ! isset( $arguments['verify'] ) || ! empty( $arguments['verify'] );
		$force   = ! empty( $arguments['force'] );

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection = null;
		$resolved   = WP_MCP_AI_Composio_Tools::resolve_connection( $arguments, $connection );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$filters = array();
		if ( '' !== $toolkit ) {
			$filters['toolkit'] = $toolkit;
		}
		if ( '' !== $status ) {
			$filters['status'] = $status;
		}

		$client   = WP_MCP_AI_Composio_Tools::build_client( $connection );
		$accounts = $client->list_connected_accounts( $filters );

		if ( is_wp_error( $accounts ) ) {
			return $accounts;
		}

		$connection_id    = isset( $connection['id'] ) ? (string) $connection['id'] : '';
		$health_available = class_exists( 'WP_MCP_AI_Composio_Account_Health' );

		$items   = array();
		$probed  = 0;
		$skipped = 0;
		$summary = array(
			'total'           => 0,
			'verified'        => 0,
			'needs_reconnect' => 0,
			'unverified'      => 0,
		);

		foreach ( $accounts as $account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}

			$account_id      = isset( $account['id'] ) ? (string) $account['id'] : '';
			$account_toolkit = isset( $account['toolkit'] ) ? (string) $account['toolkit'] : '';
			$record          = array();

			if ( $health_available && '' !== $account_id ) {
				$record = WP_MCP_AI_Composio_Account_Health::get( $connection_id, $account_id );

				$needs_probe = $verify
					&& ( $force || empty( $record ) || WP_MCP_AI_Composio_Account_Health::is_stale( $record ) );

				if ( $needs_probe && $probed >= self::MAX_VERIFY ) {
					$needs_probe = false;
					++$skipped;
				}

				if ( $needs_probe ) {
					$fresh = WP_MCP_AI_Composio_Account_Health::probe(
						$client,
						$connection_id,
						$account_id,
						array(
							// The listing already carries the authoritative
							// fields for a filtered read, but an unfiltered
							// listing is cached for 5 minutes — force a fresh
							// authoritative read when the caller asked for it.
							'account' => $force ? array() : $account,
							'toolkit' => $account_toolkit,
						)
					);

					if ( ! is_wp_error( $fresh ) ) {
						$record = $fresh;
						++$probed;
					}
				}
			}

			$health = $health_available
				? WP_MCP_AI_Composio_Account_Health::present( $record )
				: array(
					'verified'            => false,
					'verification_method' => 'unavailable',
				);

			++$summary['total'];
			if ( ! empty( $health['verified'] ) ) {
				++$summary['verified'];
			} elseif ( ! empty( $health['needs_reconnect'] ) ) {
				++$summary['needs_reconnect'];
			} else {
				++$summary['unverified'];
			}

			// Gate 2 — Escape at exit.
			$items[] = array(
				'id'               => esc_html( $account_id ),
				'alias'            => esc_html( isset( $account['alias'] ) ? (string) $account['alias'] : '' ),
				'toolkit'          => esc_html( $account_toolkit ),
				'status'           => esc_html( isset( $account['status'] ) ? (string) $account['status'] : '' ),
				'status_reason'    => esc_html( isset( $account['status_reason'] ) ? (string) $account['status_reason'] : '' ),
				'auth_scheme'      => esc_html( isset( $account['auth_scheme'] ) ? (string) $account['auth_scheme'] : '' ),
				'disabled'         => ! empty( $account['disabled'] ),
				// The Composio identity the account belongs to. Tool execution
				// must send this value alongside the account ID.
				'user_id'          => esc_html( isset( $account['user_id'] ) ? (string) $account['user_id'] : '' ),
				'token_expires_at' => esc_html( isset( $account['expires_at'] ) ? (string) $account['expires_at'] : '' ),
				'created_at'       => esc_html( isset( $account['created_at'] ) ? (string) $account['created_at'] : '' ),
				'updated_at'       => esc_html( isset( $account['updated_at'] ) ? (string) $account['updated_at'] : '' ),
				'health'           => $health,
				'reconnect_url'    => ! empty( $health['needs_reconnect'] ) && $health_available
					? esc_url( WP_MCP_AI_Composio_Account_Health::build_reconnect_url( $connection_id, $account_toolkit ) )
					: '',
				// Retained for backwards compatibility with callers that read
				// the old boolean; health.needs_reconnect is authoritative.
				'expired'          => ! empty( $health['needs_reconnect'] ),
			);
		}

		return $this->format_success_response(
			$this->build_message( $summary, $verify, $skipped ),
			array(
				'accounts'             => $items,
				'count'                => count( $items ),
				'summary'              => $summary,
				'verification_enabled' => $verify,
				'verifications_run'    => $probed,
				'verifications_capped' => $skipped,
			)
		);
	}

	/**
	 * Compose the human-readable summary line.
	 *
	 * @since 1.4.1
	 *
	 * @param array $summary Counters.
	 * @param bool  $verify  Whether verification was requested.
	 * @param int   $skipped Accounts left unprobed by the fan-out cap.
	 * @return string
	 */
	private function build_message( array $summary, $verify, $skipped ) {
		if ( 0 === $summary['total'] ) {
			return __( 'No Composio connected accounts found. Use composio_create_connect_link to connect an app.', 'mcp-ai-wpoos-pro' );
		}

		if ( ! $verify ) {
			return sprintf(
				/* translators: %d: number of accounts */
				_n( 'Found %d connected account (stored status only — verification was skipped, so an "ACTIVE" status may not reflect a revoked token).', 'Found %d connected accounts (stored status only — verification was skipped, so an "ACTIVE" status may not reflect a revoked token).', $summary['total'], 'mcp-ai-wpoos-pro' ),
				$summary['total']
			);
		}

		$message = sprintf(
			/* translators: 1: total accounts, 2: verified count, 3: broken count */
			__( 'Found %1$d connected account(s): %2$d verified working, %3$d need reconnecting.', 'mcp-ai-wpoos-pro' ),
			$summary['total'],
			$summary['verified'],
			$summary['needs_reconnect']
		);

		if ( $summary['unverified'] > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of accounts that could not be probed */
				_n( '%d could not be probed (no safe read-only tool for its toolkit) — treat its status as unconfirmed.', '%d could not be probed (no safe read-only tool for their toolkits) — treat their status as unconfirmed.', $summary['unverified'], 'mcp-ai-wpoos-pro' ),
				$summary['unverified']
			);
		}

		if ( $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of accounts skipped by the verification cap */
				__( '%d account(s) exceeded the per-call verification cap and show their previous verdict.', 'mcp-ai-wpoos-pro' ),
				$skipped
			);
		}

		return $message;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'read', 'pro', 'requires-capability', 'remote-api', 'sensitive-data' );
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
			'risk_level'            => 'medium',
		);
	}
}
