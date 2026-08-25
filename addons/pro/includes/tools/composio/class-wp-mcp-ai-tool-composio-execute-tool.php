<?php
/**
 * Tool: composio_execute_tool — execute a Composio tool on a connected account.
 *
 * Pro tool (PHP 8.1+). Requires manage_options capability.
 * Write-class tool slugs (DELETE_/UPDATE_/CREATE_/SEND_/POST_/...) are
 * classified as destructive and reported as such in the response metadata so
 * downstream guardrails (destructive-ops gate, audit log) can act on them.
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
 * Composio — Execute tool.
 */
class WP_MCP_AI_Tool_Composio_Execute_Tool implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Envelope;

	/**
	 * Verb segments considered destructive (write-class) actions.
	 *
	 * Composio slugs follow the {TOOLKIT}_{VERB}_{ACTION} pattern, so the
	 * verb is matched as a segment rather than as a string prefix.
	 */
	const DESTRUCTIVE_VERBS = array( 'DELETE', 'REMOVE', 'UPDATE', 'PATCH', 'CREATE', 'SEND', 'POST', 'UPLOAD', 'WRITE', 'INSERT', 'SET' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'composio_execute_tool';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'Composio — Execute Tool', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Execute a Composio tool on behalf of a connected account (e.g. send an email, create a GitHub issue). Requires an active connected account for the tool\'s toolkit. Write-class actions are flagged as destructive in the response.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tool_slug'            => array(
					'type'        => 'string',
					'description' => __( 'Composio tool slug, e.g. GMAIL_SEND_EMAIL.', 'mcp-ai-wpoos-pro' ),
				),
				'connected_account_id' => array(
					'type'        => 'string',
					'description' => __( 'Composio connected-account nanoid ("ca_...") — the end user\'s authenticated Gmail/Slack/GitHub account, from composio_list_connected_accounts. NOT a "conn_..." connection ID. Omit to auto-resolve the best account for the tool\'s toolkit: the most recently verified account owned by this connection\'s identity. Required explicitly when several equally-plausible accounts exist and the action is write-class.', 'mcp-ai-wpoos-pro' ),
				),
				'arguments'            => array(
					'type'        => 'object',
					'description' => __( 'Tool input arguments (see composio_get_tool_schema).', 'mcp-ai-wpoos-pro' ),
				),
				'wp_user_id'           => array(
					'type'        => 'integer',
					'description' => __( 'Optional WordPress user ID to act as when the connection uses per-user identity mode. Ignored in shared (site-wide) mode.', 'mcp-ai-wpoos-pro' ),
				),
				'connection_id'        => array(
					'type'        => 'string',
					'description' => __( 'Optional NV oOS Composio connection ID ("conn_..."), identifying this site\'s Composio project integration. NOT a connected-account ID — do not pass a "ca_..." value here. Omit it to use the first enabled Composio connection.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'tool_slug' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability(): string {
		return 'manage_options';
	}

	/**
	 * Classify a tool slug as destructive (write-class).
	 *
	 * @since 1.4.0
	 *
	 * @param string $tool_slug SCREAMING_SNAKE tool slug.
	 * @return bool
	 */
	private function is_destructive_slug( $tool_slug ) {
		$segments = explode( '_', $tool_slug );
		$verb     = isset( $segments[1] ) ? $segments[1] : '';

		return '' !== $verb && in_array( $verb, self::DESTRUCTIVE_VERBS, true );
	}

	/**
	 * Read the Composio user identity a connected account belongs to.
	 *
	 * @since 1.4.0
	 *
	 * @param array $account Connected-account payload.
	 * @return string Owner identity, or an empty string when absent.
	 */
	private function extract_account_owner( array $account ) {
		// `entity_id` is the deprecated v1/v2 name for the same field.
		foreach ( array( 'user_id', 'entity_id' ) as $key ) {
			if ( isset( $account[ $key ] ) && is_scalar( $account[ $key ] ) && '' !== (string) $account[ $key ] ) {
				return (string) $account[ $key ];
			}
		}

		if ( isset( $account['user']['id'] ) && is_scalar( $account['user']['id'] ) ) {
			return (string) $account['user']['id'];
		}

		return '';
	}

	/**
	 * Look up the identity that owns an explicitly supplied account.
	 *
	 * A definitive "no such account" is returned as a WP_Error, because
	 * continuing would send a dead ID to Composio and surface an opaque upstream
	 * failure — and the usual cause is a caller replaying a stale `ca_...` from
	 * earlier in the conversation. Any *other* lookup failure (rate limit,
	 * transport blip) is swallowed so execution still falls back to the
	 * connection's resolved identity.
	 *
	 * @since 1.4.0
	 * @since 1.4.2 An account Composio does not know fails fast with the live list.
	 *
	 * @param WP_MCP_AI_Composio_Client $client     Client instance.
	 * @param string                    $account_id Connected account nanoid.
	 * @param string                    $toolkit    Toolkit slug, for the listing hint.
	 * @return string|WP_Error Owner identity, or an empty string when unknown.
	 */
	private function resolve_account_owner( $client, $account_id, $toolkit = '' ) {
		$account = $client->get_connected_account( $account_id, WP_MCP_AI_Composio_Client::ACCOUNTS_CACHE_TTL );

		if ( is_wp_error( $account ) ) {
			if ( 'wp_mcp_ai_composio_http_404' !== $account->get_error_code() ) {
				return '';
			}

			return $this->unknown_account_error( $client, $account_id, $toolkit );
		}

		if ( ! is_array( $account ) ) {
			return '';
		}

		return $this->extract_account_owner( $account );
	}

	/**
	 * Build a self-correcting error for an account Composio does not know.
	 *
	 * Names the live accounts so the caller can retry without another
	 * round-trip, and says plainly that a remembered ID must not be reused.
	 *
	 * @since 1.4.2
	 *
	 * @param WP_MCP_AI_Composio_Client $client     Client instance.
	 * @param string                    $account_id Supplied account nanoid.
	 * @param string                    $toolkit    Toolkit slug, or an empty string.
	 * @return WP_Error
	 */
	private function unknown_account_error( $client, $account_id, $toolkit ) {
		$available = array();
		$accounts  = $client->list_connected_accounts( '' !== $toolkit ? array( 'toolkit' => $toolkit ) : array() );

		if ( ! is_wp_error( $accounts ) && is_array( $accounts ) ) {
			foreach ( $accounts as $account ) {
				if ( is_array( $account ) && ! empty( $account['id'] ) ) {
					$available[] = (string) $account['id'];
				}
			}
		}

		$available = array_slice( array_unique( $available ), 0, 10 );

		$message = sprintf(
			/* translators: %s: the connected-account ID that was supplied */
			__( 'Connected account %s does not exist on this Composio connection — it was deleted, or it belongs to a different Composio project. Do not reuse an account ID from earlier in the conversation: run composio_list_connected_accounts for the current list, or omit connected_account_id to auto-resolve the best account.', 'mcp-ai-wpoos-pro' ),
			$account_id
		);

		if ( ! empty( $available ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: comma-separated connected-account IDs */
				__( 'Accounts that do exist on this connection: %s.', 'mcp-ai-wpoos-pro' ),
				implode( ', ', $available )
			);
		}

		return new WP_Error(
			'wp_mcp_ai_composio_unknown_account',
			$message,
			array(
				'supplied'           => $account_id,
				'toolkit'            => $toolkit,
				'available_accounts' => $available,
			)
		);
	}

	/**
	 * Extract the toolkit slug from a SCREAMING_SNAKE tool slug.
	 *
	 * @since 1.4.0
	 *
	 * @param string $tool_slug Tool slug.
	 * @return string Lowercase toolkit guess.
	 */
	private function toolkit_from_slug( $tool_slug ) {
		return WP_MCP_AI_Composio_Tools::toolkit_from_tool_slug( $tool_slug );
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
		$tool_slug  = isset( $arguments['tool_slug'] ) ? sanitize_text_field( $arguments['tool_slug'] ) : '';
		$account_id = isset( $arguments['connected_account_id'] ) ? sanitize_text_field( $arguments['connected_account_id'] ) : '';
		$tool_args  = isset( $arguments['arguments'] ) && is_array( $arguments['arguments'] ) ? $arguments['arguments'] : array();
		$wp_user_id = isset( $arguments['wp_user_id'] ) ? absint( $arguments['wp_user_id'] ) : 0;

		if ( '' === $tool_slug ) {
			return new WP_Error( 'missing_params', __( 'tool_slug is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Catch a connection ID passed where an account ID belongs before it
		// reaches Composio as an opaque "not found".
		$account_check = WP_MCP_AI_Composio_Tools::validate_account_id( $account_id );

		if ( is_wp_error( $account_check ) ) {
			return $account_check;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection = null;
		$resolved   = WP_MCP_AI_Composio_Tools::resolve_connection( $arguments, $connection );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$toolkit = $this->toolkit_from_slug( $tool_slug );

		if ( '' !== $toolkit && ! WP_MCP_AI_Composio_Tools::is_toolkit_allowed( $connection, $toolkit ) ) {
			return new WP_Error( 'wp_mcp_ai_composio_toolkit_denied', __( 'This toolkit is not in the connection allowlist.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection_id = isset( $connection['id'] ) ? (string) $connection['id'] : '';
		$client        = WP_MCP_AI_Composio_Tools::build_client( $connection );
		$destructive   = $this->is_destructive_slug( $tool_slug );

		// Composio requires the user identity that owns the connected account on
		// every execution. Start from the connection's identity mode, then prefer
		// the account's own owner so accounts linked under a different identity
		// keep working.
		$user_id   = WP_MCP_AI_Composio_Tools::resolve_user_id( $connection, $wp_user_id );
		$ambiguous = array();

		if ( '' === $account_id ) {
			$account = WP_MCP_AI_Composio_Tools::resolve_account_for_toolkit( $client, $connection, $toolkit, $user_id );

			if ( is_wp_error( $account ) ) {
				return $account;
			}

			// Refuse to guess when a write-class action has several equally
			// plausible targets — silently emailing from the wrong mailbox is
			// worse than an extra round-trip.
			if ( ! empty( $account['ambiguous'] ) && $destructive ) {
				return new WP_Error(
					'wp_mcp_ai_composio_ambiguous_account',
					sprintf(
						/* translators: 1: number of accounts, 2: toolkit slug, 3: tool slug */
						__( '%1$d unverified %2$s accounts could run %3$s and none is a clearly better match. Pass connected_account_id explicitly, or run composio_manage_accounts with action "validate" and toolkit "%2$s" first so the healthy one can be identified.', 'mcp-ai-wpoos-pro' ),
						count( $account['candidates'] ),
						$toolkit,
						$tool_slug
					),
					array(
						'toolkit'    => $toolkit,
						'tool_slug'  => $tool_slug,
						'candidates' => WP_MCP_AI_Composio_Tools::present_candidates( $account['candidates'] ),
					)
				);
			}

			$account_id = $account['id'];

			if ( '' !== $account['user_id'] ) {
				$user_id = $account['user_id'];
			}

			if ( ! empty( $account['ambiguous'] ) ) {
				$ambiguous = WP_MCP_AI_Composio_Tools::present_candidates( $account['candidates'] );
			}
		} else {
			$owner = $this->resolve_account_owner( $client, $account_id, $toolkit );

			if ( is_wp_error( $owner ) ) {
				return $owner;
			}

			if ( '' !== $owner ) {
				$user_id = $owner;
			}
		}

		$result = $client->execute_tool( $tool_slug, $account_id, $tool_args, $user_id );

		if ( is_wp_error( $result ) ) {
			return $this->handle_execution_error( $result, $connection_id, $toolkit, $account_id, $tool_slug );
		}

		// A real, successful execution is the strongest possible credential
		// verification — record it so account health improves for free with
		// normal use instead of only when someone runs a probe.
		if ( class_exists( 'WP_MCP_AI_Composio_Account_Health' ) && '' !== $connection_id ) {
			WP_MCP_AI_Composio_Account_Health::record(
				$connection_id,
				$account_id,
				array(
					'account_id'          => $account_id,
					'toolkit'             => $toolkit,
					'status'              => 'ACTIVE',
					'owner'               => $user_id,
					'verified'            => true,
					'verification_method' => 'execution',
					'probe_tool'          => $tool_slug,
					'needs_reconnect'     => false,
					'last_error'          => '',
					'last_error_code'     => '',
					'validated_at'        => time(),
				)
			);
		}

		do_action(
			'wp_mcp_ai_composio_tool_executed',
			array(
				'connection_id'    => $connection_id,
				'tool_slug'        => $tool_slug,
				'account_id'       => $account_id,
				'composio_user_id' => $user_id,
				'destructive'      => $destructive,
				'user_id'          => get_current_user_id(),
			)
		);

		// Gate 2 — Escape at exit.
		$data = array(
			'tool_slug'        => esc_html( $tool_slug ),
			'account_id'       => esc_html( $account_id ),
			'composio_user_id' => esc_html( $user_id ),
			'destructive'      => $destructive,
			'result'           => $result,
		);

		if ( ! empty( $ambiguous ) ) {
			$data['ambiguous_accounts'] = $ambiguous;
		}

		return $this->format_success_response(
			/* translators: %s: tool slug */
			sprintf( __( 'Composio tool %s executed.', 'mcp-ai-wpoos-pro' ), esc_html( $tool_slug ) ),
			$data
		);
	}

	/**
	 * Turn an execution failure into an actionable error.
	 *
	 * An auth-class failure is the one error an operator can always fix, so it
	 * is rewritten into a message that names the app, explains that the token
	 * was revoked or expired, and carries a one-click reconnect URL. The health
	 * ledger is updated at the same time so auto-resolution stops picking this
	 * account on the next call.
	 *
	 * @since 1.4.1
	 *
	 * @param WP_Error $error         Failure from the client.
	 * @param string   $connection_id Connection ID.
	 * @param string   $toolkit       Toolkit slug.
	 * @param string   $account_id    Connected account nanoid.
	 * @param string   $tool_slug     Tool slug that failed.
	 * @return WP_Error
	 */
	private function handle_execution_error( WP_Error $error, $connection_id, $toolkit, $account_id, $tool_slug ) {
		if ( ! class_exists( 'WP_MCP_AI_Composio_Account_Health' ) ) {
			return $error;
		}

		$code    = $error->get_error_code();
		$message = $error->get_error_message();

		if ( ! WP_MCP_AI_Composio_Account_Health::is_auth_error( $code, $message ) ) {
			return $error;
		}

		if ( '' !== $connection_id && '' !== $account_id ) {
			WP_MCP_AI_Composio_Account_Health::record(
				$connection_id,
				$account_id,
				array(
					'account_id'          => $account_id,
					'toolkit'             => $toolkit,
					'status'              => 'ACTIVE',
					'verified'            => false,
					'verification_method' => 'execution',
					'probe_tool'          => $tool_slug,
					'needs_reconnect'     => true,
					'last_error'          => $message,
					'last_error_code'     => $code,
				)
			);
		}

		$hint = WP_MCP_AI_Composio_Account_Health::build_reconnect_hint( $connection_id, $toolkit, $account_id, $message );

		$data                    = (array) $error->get_error_data();
		$data['toolkit']         = $toolkit;
		$data['tool_slug']       = $tool_slug;
		$data['account_id']      = $account_id;
		$data['needs_reconnect'] = true;
		$data['reconnect_url']   = $hint['reconnect_url'];
		$data['upstream_error']  = $message;

		return new WP_Error( 'wp_mcp_ai_composio_account_auth_required', $hint['message'], $data );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'write', 'state-changing', 'pro', 'requires-capability', 'remote-api' );
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
