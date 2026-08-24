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
					'description' => __( 'Connected account nanoid. Omit to auto-resolve the first active account for the tool\'s toolkit.', 'mcp-ai-wpoos-pro' ),
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
					'description' => __( 'Optional Composio connection ID.', 'mcp-ai-wpoos-pro' ),
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
	 * Resolve a connected account for a tool when none is supplied.
	 *
	 * Prefers the first active account that belongs to the connection's
	 * resolved identity, and falls back to any active account for the toolkit
	 * so a connection whose identity mode changed after the app was linked
	 * still executes (the account's own owner is then used for the call).
	 *
	 * @since 1.4.0
	 *
	 * @param WP_MCP_AI_Composio_Client $client  Client instance.
	 * @param string                    $toolkit Toolkit slug of the tool.
	 * @param string                    $user_id Resolved Composio identity.
	 * @return array|WP_Error Array with id + user_id keys, or WP_Error.
	 */
	private function resolve_account( $client, $toolkit, $user_id ) {
		$accounts = $client->list_connected_accounts( array( 'toolkit' => $toolkit ) );

		if ( is_wp_error( $accounts ) ) {
			return $accounts;
		}

		$fallback = null;

		foreach ( $accounts as $account ) {
			if ( ! is_array( $account ) || empty( $account['id'] ) ) {
				continue;
			}

			$status = isset( $account['status'] ) ? (string) $account['status'] : '';
			if ( 'active' !== strtolower( $status ) ) {
				continue;
			}

			$owner = $this->extract_account_owner( $account );
			$match = array(
				'id'      => (string) $account['id'],
				'user_id' => $owner,
			);

			if ( '' !== $user_id && $owner === $user_id ) {
				return $match;
			}

			if ( null === $fallback ) {
				$fallback = $match;
			}
		}

		if ( null !== $fallback ) {
			return $fallback;
		}

		return new WP_Error(
			'wp_mcp_ai_composio_no_active_account',
			sprintf(
				/* translators: 1: toolkit slug, 2: Composio user identity */
				__( 'No active connected account found for toolkit %1$s (identity %2$s). Connect the app from Remote Sites → your Composio connection, then try again.', 'mcp-ai-wpoos-pro' ),
				$toolkit,
				'' !== $user_id ? $user_id : __( 'unset', 'mcp-ai-wpoos-pro' )
			)
		);
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
	 * Returns an empty string when the lookup fails so execution falls back to
	 * the connection's resolved identity instead of erroring out.
	 *
	 * @since 1.4.0
	 *
	 * @param WP_MCP_AI_Composio_Client $client     Client instance.
	 * @param string                    $account_id Connected account nanoid.
	 * @return string
	 */
	private function resolve_account_owner( $client, $account_id ) {
		$account = $client->get_connected_account( $account_id, WP_MCP_AI_Composio_Client::ACCOUNTS_CACHE_TTL );

		if ( is_wp_error( $account ) || ! is_array( $account ) ) {
			return '';
		}

		return $this->extract_account_owner( $account );
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
		$parts   = explode( '_', $tool_slug );
		$toolkit = isset( $parts[0] ) ? strtolower( $parts[0] ) : '';
		return $toolkit;
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

		$client = WP_MCP_AI_Composio_Tools::build_client( $connection );

		// Composio requires the user identity that owns the connected account on
		// every execution. Start from the connection's identity mode, then prefer
		// the account's own owner so accounts linked under a different identity
		// keep working.
		$user_id = WP_MCP_AI_Composio_Tools::resolve_user_id( $connection, $wp_user_id );

		if ( '' === $account_id ) {
			$account = $this->resolve_account( $client, $toolkit, $user_id );

			if ( is_wp_error( $account ) ) {
				return $account;
			}

			$account_id = $account['id'];

			if ( '' !== $account['user_id'] ) {
				$user_id = $account['user_id'];
			}
		} else {
			$owner = $this->resolve_account_owner( $client, $account_id );

			if ( '' !== $owner ) {
				$user_id = $owner;
			}
		}

		$result = $client->execute_tool( $tool_slug, $account_id, $tool_args, $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$destructive = $this->is_destructive_slug( $tool_slug );

		if ( function_exists( 'do_action' ) ) {
			do_action(
				'wp_mcp_ai_composio_tool_executed',
				array(
					'connection_id'    => isset( $connection['id'] ) ? $connection['id'] : '',
					'tool_slug'        => $tool_slug,
					'account_id'       => $account_id,
					'composio_user_id' => $user_id,
					'destructive'      => $destructive,
					'user_id'          => get_current_user_id(),
				)
			);
		}

		// Gate 2 — Escape at exit.
		return $this->format_success_response(
			/* translators: %s: tool slug */
			sprintf( __( 'Composio tool %s executed.', 'mcp-ai-wpoos-pro' ), esc_html( $tool_slug ) ),
			array(
				'tool_slug'        => esc_html( $tool_slug ),
				'account_id'       => esc_html( $account_id ),
				'composio_user_id' => esc_html( $user_id ),
				'destructive'      => $destructive,
				'result'           => $result,
			)
		);
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
