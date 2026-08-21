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
	 * Resolve a connected account ID for a tool when none is supplied.
	 *
	 * Picks the first active account belonging to the tool's toolkit.
	 *
	 * @since 1.4.0
	 *
	 * @param WP_MCP_AI_Composio_Client $client  Client instance.
	 * @param string                    $toolkit Toolkit slug of the tool.
	 * @return string|WP_Error
	 */
	private function resolve_account_id( $client, $toolkit ) {
		$accounts = $client->list_connected_accounts( array( 'toolkit' => $toolkit ) );

		if ( is_wp_error( $accounts ) ) {
			return $accounts;
		}

		foreach ( $accounts as $account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}

			$status = isset( $account['status'] ) ? (string) $account['status'] : '';
			if ( 'active' === strtolower( $status ) && ! empty( $account['id'] ) ) {
				return (string) $account['id'];
			}
		}

		return new WP_Error(
			'wp_mcp_ai_composio_no_active_account',
			/* translators: %s: toolkit slug */
			sprintf( __( 'No active connected account found for toolkit %s. Create a Connect Link first.', 'mcp-ai-wpoos-pro' ), $toolkit )
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

		if ( '' === $account_id ) {
			$account_id = $this->resolve_account_id( $client, $toolkit );

			if ( is_wp_error( $account_id ) ) {
				return $account_id;
			}
		}

		$result = $client->execute_tool( $tool_slug, $account_id, $tool_args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$destructive = $this->is_destructive_slug( $tool_slug );

		if ( function_exists( 'do_action' ) ) {
			do_action(
				'wp_mcp_ai_composio_tool_executed',
				array(
					'connection_id' => isset( $connection['id'] ) ? $connection['id'] : '',
					'tool_slug'     => $tool_slug,
					'account_id'    => $account_id,
					'destructive'   => $destructive,
					'user_id'       => get_current_user_id(),
				)
			);
		}

		// Gate 2 — Escape at exit.
		return $this->format_success_response(
			/* translators: %s: tool slug */
			sprintf( __( 'Composio tool %s executed.', 'mcp-ai-wpoos-pro' ), esc_html( $tool_slug ) ),
			array(
				'tool_slug'   => esc_html( $tool_slug ),
				'account_id'  => esc_html( $account_id ),
				'destructive' => $destructive,
				'result'      => $result,
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
