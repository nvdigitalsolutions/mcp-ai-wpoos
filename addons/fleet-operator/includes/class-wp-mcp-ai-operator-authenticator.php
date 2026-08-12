<?php
/**
 * Operator authentication integration for the Fleet Operator addon.
 *
 * Wires operator credentials into the base plugin's REST authentication
 * and tool-execution pipeline through public hooks:
 *
 * - wp_mcp_ai_pre_validate_bearer_token  Validate op_xxxx.SECRET tokens.
 * - wp_mcp_ai_map_bearer_to_user_id      Map operator to its authorizing user.
 * - wp_mcp_ai_mcp_tools_list             Scope tools/list to the allowlist.
 * - wp_mcp_ai_pre_execute_tool           Enforce the allowlist on tools/call
 *                                        (and deny write tools in read mode).
 * - wp_mcp_ai_after_tool_execution       Attribute audit entries to the operator.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrates operator credentials with REST auth and tool execution.
 */
class WP_MCP_AI_Operator_Authenticator {

	/**
	 * Operator record resolved for the current request, or null.
	 *
	 * @var array|null
	 */
	protected static $current_operator = null;

	/**
	 * Register all integration hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'wp_mcp_ai_pre_validate_bearer_token', array( $this, 'pre_validate_bearer' ), 10, 3 );
		add_filter( 'wp_mcp_ai_map_bearer_to_user_id', array( $this, 'map_bearer_user' ), 10, 3 );
		add_filter( 'wp_mcp_ai_mcp_tools_list', array( $this, 'scope_tools_list' ), 10, 2 );
		add_filter( 'wp_mcp_ai_pre_execute_tool', array( $this, 'enforce_tool_call' ), 10, 4 );
		add_action( 'wp_mcp_ai_after_tool_execution', array( $this, 'audit_tool_execution' ), 10, 4 );
	}

	/**
	 * Operator record for the current request, if any.
	 *
	 * @return array|null
	 */
	public static function current_operator() {
		return self::$current_operator;
	}

	/**
	 * Clear the per-request operator context.
	 *
	 * Used by tests and by pre_validate_bearer() before each attempt.
	 *
	 * @return void
	 */
	public static function reset_current_operator() {
		self::$current_operator = null;
	}

	/**
	 * Validate op_ tokens before the generic bearer path runs.
	 *
	 * @param null|bool|WP_Error $pre     Existing validation result.
	 * @param string             $token   Raw bearer token.
	 * @param WP_REST_Request    $request Current REST request.
	 * @return null|bool|WP_Error
	 */
	public function pre_validate_bearer( $pre, $token, WP_REST_Request $request ) {
		unset( $request ); // Context only.

		if ( null !== $pre ) {
			return $pre;
		}

		self::reset_current_operator();

		if ( ! is_string( $token ) || 0 !== strpos( $token, 'op_' ) ) {
			return $pre;
		}

		$record = WP_MCP_AI_Operator_Credential_Repository::verify( $token );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		self::$current_operator = $record;
		return true;
	}

	/**
	 * Map a validated operator token to its authorizing WordPress user.
	 *
	 * @param int|null        $user_id Existing mapped user.
	 * @param array|null      $payload  Decoded payload (null for op_ tokens).
	 * @param WP_REST_Request $request  Current REST request.
	 * @return int|null|WP_Error
	 */
	public function map_bearer_user( $user_id, $payload, WP_REST_Request $request ) {
		unset( $payload, $request ); // Context only.

		$operator = self::$current_operator;
		if ( null === $operator ) {
			return $user_id;
		}

		return absint( $operator['user_id'] );
	}

	/**
	 * Scope MCP tools/list to the current operator's allowlist.
	 *
	 * @param array           $mcp_tools MCP-format tool entries.
	 * @param WP_REST_Request $request   Current REST request.
	 * @return array Filtered tool entries.
	 */
	public function scope_tools_list( $mcp_tools, WP_REST_Request $request ) {
		unset( $request ); // Context only.

		$operator = self::$current_operator;
		if ( null === $operator ) {
			return $mcp_tools;
		}

		return WP_MCP_AI_Operator_Tool_Scope::filter_tools_list( $mcp_tools, $operator['allowed_tools'] );
	}

	/**
	 * Enforce the operator allowlist on every tool execution attempt.
	 *
	 * Defense in depth: even if a client guesses a tool name that was hidden
	 * from tools/list, the execution path re-checks the allowlist here.
	 *
	 * @param mixed                    $short_circuit Existing result (null = proceed).
	 * @param WP_MCP_AI_Tool_Interface $tool          Tool being executed.
	 * @param array                    $arguments     Prepared arguments.
	 * @param array                    $context       Execution context.
	 * @return mixed|WP_Error
	 */
	public function enforce_tool_call( $short_circuit, $tool, $arguments, $context ) {
		unset( $arguments, $context ); // Not used for the allowlist check.

		if ( null !== $short_circuit ) {
			return $short_circuit;
		}

		$operator = self::$current_operator;
		if ( null === $operator ) {
			return $short_circuit;
		}

		$slug = is_object( $tool ) && method_exists( $tool, 'get_slug' ) ? $tool->get_slug() : sanitize_key( (string) $tool );

		if ( ! WP_MCP_AI_Operator_Tool_Scope::is_tool_allowed( $slug, $operator['allowed_tools'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_operator_tool_forbidden',
				sprintf(
					/* translators: %s: tool slug */
					__( 'Tool "%s" is outside this operator credential\'s allowlist.', 'mcp-ai-wpoos' ),
					$slug
				),
				array( 'status' => 403 )
			);
		}

		if ( 'read' === $operator['mode'] && WP_MCP_AI_Operator_Tool_Scope::tool_is_write( $slug ) ) {
			return new WP_Error(
				'wp_mcp_ai_operator_read_only',
				sprintf(
					/* translators: %s: tool slug */
					__( 'Tool "%s" is write-capable and this operator credential is read-only.', 'mcp-ai-wpoos' ),
					$slug
				),
				array( 'status' => 403 )
			);
		}

		return $short_circuit;
	}

	/**
	 * Attribute tool-execution audit entries to the current operator.
	 *
	 * @param string $tool_slug Executed tool slug.
	 * @param array  $arguments Prepared arguments.
	 * @param array  $context   Execution context.
	 * @param mixed  $result    Tool result.
	 * @return void
	 */
	public function audit_tool_execution( $tool_slug, $arguments, $context, $result ) {
		unset( $arguments, $context ); // Never log tool arguments here; the base logger handles them.

		$operator = self::$current_operator;
		if ( null === $operator ) {
			return;
		}

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_info(
				sprintf(
					/* translators: 1: operator label, 2: tool slug */
					__( 'External operator "%1$s" executed tool "%2$s".', 'mcp-ai-wpoos' ),
					$operator['label'],
					sanitize_key( $tool_slug )
				),
				array(
					'operator_id'    => $operator['id'],
					'operator_label' => $operator['label'],
					'operator_user'  => $operator['user_id'],
					'tool'           => sanitize_key( $tool_slug ),
					'success'        => ! is_wp_error( $result ),
				)
			);
		}
	}
}
