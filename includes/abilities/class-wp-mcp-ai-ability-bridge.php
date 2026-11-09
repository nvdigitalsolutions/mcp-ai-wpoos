<?php
/**
 * Ability Bridge — wraps one NV oOS tool as a WordPress Ability.
 *
 * Handles the full mapping from the NV oOS tool interface to the WordPress
 * Abilities API contract: identifier, schemas, annotations, context
 * population, WP_Error conversion, and permission callbacks.
 *
 * All registrations are guarded by function_exists('wp_register_ability')
 * for backward compatibility with WordPress < 6.9.
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 *
 * @license GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges a single tool instance to a wp_register_ability() call.
 *
 * @since 2.0.0
 */
class WP_MCP_AI_Ability_Bridge {

	/**
	 * Register a single tool as a WordPress Ability.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_MCP_AI_Tool_Interface $tool     The tool instance.
	 * @param string                   $category Ability category slug.
	 * @return WP_Ability|false Registration result or false on failure.
	 */
	public static function register( WP_MCP_AI_Tool_Interface $tool, $category ) {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return false;
		}

		$slug        = $tool->get_slug();
		$hyphen_slug = str_replace( '_', '-', $slug );
		$id          = 'nvoos/' . $hyphen_slug;
		$flags       = self::get_flags( $tool );
		$annotations = self::map_annotations( $flags );

		return wp_register_ability(
			$id,
			array(
				'label'              => $tool->get_name(),
				'description'        => $tool->get_description(),
				'category'           => $category,
				'input_schema'       => $tool->get_parameters_schema(),
				'output_schema'      => self::get_output_schema( $tool ),
				'execute_callback'   => self::build_execute_callback( $slug ),
				'permission_callback' => self::build_permission_callback( $tool ),
				'meta'               => array(
					'show_in_rest' => true,
					'annotations'  => $annotations,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * Build the execute callback closure with lazy tool instantiation.
	 *
	 * Looks up the tool in the registry on first call rather than
	 * capturing the instance at registration time, avoiding loading
	 * tool classes during wp_abilities_api_init.
	 *
	 * @since 2.0.0
	 *
	 * @param string $slug Tool slug.
	 * @return callable Execute callback suitable for wp_register_ability().
	 */
	private static function build_execute_callback( $slug ) {
		return static function ( $input = array() ) use ( $slug ) {
			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			$tool     = $registry->get_tool( $slug );

			if ( ! $tool ) {
				return array(
					'success' => false,
					'message' => 'Tool not available.',
					'code'    => 'tool_not_found',
				);
			}

			$context = array(
				'user_id'         => get_current_user_id(),
				'ability_context' => true,
				'is_ability_call' => true,
			);

			$input_array = is_array( $input ) ? $input : array();

			/**
			 * Fires before an NV oOS tool is executed via the Ability bridge.
			 *
			 * @since 2.0.0
			 *
			 * @param string $ability_id Full ability identifier (e.g. 'nvoos/get-post').
			 * @param string $tool_slug  The NV oOS tool slug.
			 * @param array  $input      The validated input arguments.
			 */
			do_action( 'wp_mcp_ai_before_ability_execute', 'nvoos/' . str_replace( '_', '-', $slug ), $slug, $input_array );

			$start_time = microtime( true );
			$result     = $tool->execute( $input_array, $context );
			$duration   = microtime( true ) - $start_time;

			/**
			 * Fires after an NV oOS tool is executed via the Ability bridge.
			 *
			 * @since 2.0.0
			 *
			 * @param string          $ability_id Full ability identifier.
			 * @param string          $tool_slug  The NV oOS tool slug.
			 * @param array           $input      The validated input arguments.
			 * @param array|WP_Error  $result     The execution result.
			 * @param float           $duration   Execution time in seconds.
			 */
			do_action( 'wp_mcp_ai_after_ability_execute', 'nvoos/' . str_replace( '_', '-', $slug ), $slug, $input_array, $result, $duration );

			if ( is_wp_error( $result ) ) {
				/**
				 * Return the raw WP_Error so downstream consumers apply native
				 * error semantics. Verified live (2026-11-02, WP 7.1 +
				 * mcp-adapter 0.7.1): the core Abilities REST run controller
				 * converts it to a proper REST error response, and the official
				 * MCP Adapter marks the tool result `isError: true`. The legacy
				 * structured-array envelope made MCP clients see failures as
				 * successful tool results.
				 *
				 * Disable this filter to restore the legacy
				 * `{success, message, code}` envelope for consumers that
				 * expect it.
				 *
				 * @since 2.0.0
				 *
				 * @param bool     $passthrough Whether to return the raw WP_Error.
				 * @param WP_Error $result      The execution error.
				 * @param string   $slug        The tool slug being executed.
				 */
				if ( apply_filters( 'wp_mcp_ai_ability_wp_error_passthrough', true, $result, $slug ) ) {
					return $result;
				}

				return array(
					// phpcs:ignore WPMCPAI.Tools.CanonicalReturnEnvelope -- Deliberate BC fallback: this envelope only returns when the wp_mcp_ai_ability_wp_error_passthrough filter is disabled; the default path returns the raw WP_Error above.
					'success' => false,
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
					'data'    => $result->get_error_data(),
				);
			}

			return $result;
		};
	}

	/**
	 * Build the permission callback closure.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_MCP_AI_Tool_Interface $tool The tool instance.
	 * @return callable Permission callback suitable for wp_register_ability().
	 */
	private static function build_permission_callback( WP_MCP_AI_Tool_Interface $tool ) {
		$capability = $tool->get_required_capability();

		return static function () use ( $capability ) {
			return current_user_can( $capability );
		};
	}

	/**
	 * Extract capability flags from a tool.
	 *
	 * Falls back to an empty array if the tool does not implement the
	 * optional Capability_Flags_Interface.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_MCP_AI_Tool_Interface $tool The tool instance.
	 * @return array<string> Array of capability flag strings.
	 */
	private static function get_flags( WP_MCP_AI_Tool_Interface $tool ) {
		if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			return $tool->get_capability_flags();
		}
		return array();
	}

	/**
	 * Map NV oOS capability flags to MCP annotations.
	 *
	 * Industry best practice: set all four hints explicitly (sunpeak.ai),
	 * and default pessimistically for anything unproven — the MCP spec
	 * instructs clients to assume a tool may be destructive when the hint
	 * is absent, so claiming `destructiveHint: false` for a tool without
	 * flags would be an unverified safety claim. Only read-only and
	 * explicitly reversible tools are advertised as non-destructive.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string> $flags Capability flag strings from the tool.
	 * @return array<string, bool> MCP annotation array.
	 */
	private static function map_annotations( array $flags ) {
		$read_only  = in_array( 'read-only', $flags, true );
		$reversible = in_array( 'reversible', $flags, true );

		$explicit_destructive = in_array( 'irreversible', $flags, true )
			|| in_array( 'data-destruction', $flags, true )
			|| in_array( 'destructive', $flags, true );

		$mutating = in_array( 'write', $flags, true )
			|| in_array( 'state-changing', $flags, true )
			|| in_array( 'external-communication', $flags, true );

		$destructive = ! $read_only
			&& ( $explicit_destructive || ( $mutating && ! $reversible ) || empty( $flags ) );

		return array(
			'readOnlyHint'    => $read_only,
			'destructiveHint' => $destructive,
			'idempotentHint'  => $read_only || in_array( 'idempotent', $flags, true ),
			'openWorldHint'   => in_array( 'external-api', $flags, true )
			|| in_array( 'network-dependent', $flags, true )
			|| in_array( 'long-running', $flags, true ),
		);
	}

	/**
	 * Get the output schema for an ability.
	 *
	 * Prefers the tool's declared output_schema via the optional
	 * WP_MCP_AI_Tool_Ability_Interface. Falls back to the generic
	 * canonical envelope schema.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_MCP_AI_Tool_Interface $tool The tool instance.
	 * @return array JSON Schema array.
	 */
	private static function get_output_schema( WP_MCP_AI_Tool_Interface $tool ) {
		if ( $tool instanceof WP_MCP_AI_Tool_Ability_Interface ) {
			$schema = $tool->get_output_schema();
			if ( ! empty( $schema ) ) {
				return $schema;
			}
		}

		return array(
			'type'       => 'object',
			'properties' => array(
				'success' => array(
					'type'        => 'boolean',
					'description' => 'Whether the operation succeeded.',
				),
				'message' => array(
					'type'        => 'string',
					'description' => 'Human-readable summary.',
				),
				// Operation-specific payloads vary by tool, so accept any JSON
				// value type rather than forcing one (REST schema validation
				// requires a declared type on every property).
				'data'    => array(
					'type'        => array( 'boolean', 'integer', 'number', 'string', 'array', 'object', 'null' ),
					'description' => 'Operation-specific result payload.',
				),
			),
		);
	}
}
