<?php
/**
 * Slash Command Tool Adapter
 *
 * Declarative bridge that lets a slash command delegate execution to an
 * existing MCP tool. The tool registry remains the single source of truth
 * for business logic; the adapter only maps slash-command input onto tool
 * arguments and normalises the tool's canonical envelope.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 2.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Slash_Command_Tool_Adapter
 *
 * Invocable handler registered by WP_MCP_AI_Slash_Command_Handler when a
 * command declares a 'tool' key. Execution flow:
 *
 *   1. Map slash args/flags onto tool arguments (arg_map, positional_map).
 *   2. Merge declared defaults for tool arguments the user did not supply.
 *   3. Execute the tool through WP_MCP_AI_Tool_Registry::execute_tool() —
 *      capability checks, parameter validation, sanitisation and rate
 *      limiting all live in the tool layer, not here.
 *   4. Return the tool's canonical envelope (success array or WP_Error)
 *      unchanged, optionally transformed by a render callable.
 *
 * @since 2.2.0
 */
class WP_MCP_AI_Slash_Command_Tool_Adapter {

	/**
	 * Tool slug to delegate to.
	 *
	 * @var string
	 */
	private $tool_slug;

	/**
	 * Mapping of slash-command argument names to tool argument names.
	 *
	 * Example: array( 'post-id' => 'post_id', 'lang' => 'target_language' ).
	 *
	 * @var array
	 */
	private $arg_map;

	/**
	 * Mapping of positional arguments to tool argument names.
	 *
	 * Example: array( 0 => 'post_id', 1 => 'target_language' ).
	 *
	 * @var array
	 */
	private $positional_map;

	/**
	 * Default values merged into every tool call.
	 *
	 * @var array
	 */
	private $defaults;

	/**
	 * Optional render callable receiving ( $tool_result, $tool_args, $context )
	 * and returning the slash-command payload. Applied only on success.
	 *
	 * @var callable|null
	 */
	private $render;

	/**
	 * Constructor.
	 *
	 * @param string $tool_slug      Tool slug to delegate to.
	 * @param array  $config         {
	 *     Optional adapter configuration.
	 *
	 *     @type array         $arg_map       Named-argument mapping: slash name => tool name.
	 *     @type array         $positional    Positional-argument mapping: index => tool name.
	 *     @type array         $defaults      Default tool arguments.
	 *     @type callable|null $render        Success render callable.
	 * }
	 */
	public function __construct( $tool_slug, $config = array() ) {
		$this->tool_slug      = sanitize_key( (string) $tool_slug );
		$this->arg_map        = isset( $config['arg_map'] ) && is_array( $config['arg_map'] ) ? $config['arg_map'] : array();
		$this->positional_map = isset( $config['positional'] ) && is_array( $config['positional'] ) ? $config['positional'] : array();
		$this->defaults       = isset( $config['defaults'] ) && is_array( $config['defaults'] ) ? $config['defaults'] : array();
		$this->render         = isset( $config['render'] ) && is_callable( $config['render'] ) ? $config['render'] : null;
	}

	/**
	 * Execute the adapted tool.
	 *
	 * @param array $args    Merged slash arguments (flags + positional).
	 * @param array $flags   Raw parsed flags (unused; kept for handler signature parity).
	 * @param array $context Execution context.
	 * @return array|WP_Error Canonical tool envelope or error.
	 */
	public function __invoke( $args, $flags, $context ) {
		unset( $flags ); // Handler signature parity; $args already contains flags.

		$tool_args = $this->defaults;

		foreach ( (array) $args as $key => $value ) {
			if ( is_int( $key ) ) {
				if ( isset( $this->positional_map[ $key ] ) ) {
					$tool_args[ sanitize_key( (string) $this->positional_map[ $key ] ) ] = $value;
				}
				continue;
			}

			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			$target                                        = isset( $this->arg_map[ $key ] ) ? $this->arg_map[ $key ] : $key;
			$tool_args[ sanitize_key( (string) $target ) ] = $value;
		}

		// Strip common UI flags that are never tool arguments.
		unset( $tool_args['json'], $tool_args['format'] );

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return new WP_Error(
				'tool_registry_unavailable',
				__( 'The tool registry is not available.', 'mcp-ai-wpoos' )
			);
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		if ( ! $registry->is_tool_registered( $this->tool_slug ) ) {
			return new WP_Error(
				'tool_unavailable',
				sprintf(
					/* translators: 1: slash command tool slug, 2: tool slug */
					__( 'The tool backing this command (%1$s) is not registered on this site.', 'mcp-ai-wpoos' ),
					esc_html( $this->tool_slug )
				)
			);
		}

		$result = $registry->execute_tool( $this->tool_slug, $tool_args, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Defensive normalisation: tools must return the canonical envelope,
		// but tolerate raw payloads from third-party tools.
		if ( is_array( $result ) && ! isset( $result['success'] ) ) {
			$result = array(
				'success' => true,
				'message' => __( 'Command executed.', 'mcp-ai-wpoos' ),
				'data'    => $result,
			);
		}

		if ( $this->render && is_array( $result ) ) {
			return call_user_func( $this->render, $result, $tool_args, $context );
		}

		return $result;
	}

	/**
	 * Get the delegated tool slug.
	 *
	 * @return string
	 */
	public function get_tool_slug() {
		return $this->tool_slug;
	}
}
