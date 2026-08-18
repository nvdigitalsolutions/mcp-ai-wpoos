<?php
/**
 * Transparent wrapper upgrading legacy-format tool classes to the
 * canonical WP_MCP_AI_Tool_Interface.
 *
 * Some tool classes predate the interface split and only expose
 * get_slug() / get_definition() / execute(). The registry requires the
 * interface, so registration of those classes silently failed. This wrapper
 * delegates the full interface surface to the inner legacy tool, deriving
 * name/description/schema from its get_definition() via
 * {@see WP_MCP_AI_Tool_Legacy_Definition}.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy tool wrapper.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Legacy_Tool_Wrapper implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Legacy_Definition;

	/**
	 * Wrapped legacy tool instance.
	 *
	 * @var object
	 */
	private $inner;

	/**
	 * Constructor.
	 *
	 * @param object $inner Legacy-format tool instance.
	 */
	public function __construct( $inner ) {
		$this->inner = $inner;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return method_exists( $this->inner, 'get_slug' ) ? (string) $this->inner->get_slug() : '';
	}

	/**
	 * Class name of the wrapped legacy tool.
	 *
	 * Slug-integrity consumers derive expected slugs from the *declaring*
	 * class; the wrapper class name is an implementation detail.
	 *
	 * @since 1.2.0
	 *
	 * @return string Inner tool class name.
	 */
	public function get_inner_class_name() {
		return get_class( $this->inner );
	}

	/**
	 * Passthrough for the legacy definition (used by the shared trait).
	 *
	 * @return array
	 */
	public function get_definition() {
		return method_exists( $this->inner, 'get_definition' ) ? (array) $this->inner->get_definition() : array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		if ( method_exists( $this->inner, 'get_required_capability' ) ) {
			return (string) $this->inner->get_required_capability();
		}

		$definition = $this->get_definition();

		return isset( $definition['required_capability'] ) ? (string) $definition['required_capability'] : 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return mixed|WP_Error Legacy tool execution result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! method_exists( $this->inner, 'execute' ) ) {
			return new WP_Error(
				'wp_mcp_ai_legacy_tool_no_execute',
				__( 'The wrapped legacy tool does not implement execute().', 'mcp-ai-wpoos' )
			);
		}

		return $this->inner->execute( $arguments, $context );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		if ( method_exists( $this->inner, 'get_capability_flags' ) ) {
			$flags = $this->inner->get_capability_flags();

			return is_array( $flags ) ? $flags : array();
		}

		// Legacy tools that declare a required capability perform capability
		// checks at dispatch time — advertise that so flag-driven consumers
		// (UI, orchestrator, tests) treat them correctly.
		if ( method_exists( $this->inner, 'get_required_capability' ) ) {
			$capability = $this->inner->get_required_capability();
			if ( ! empty( $capability ) ) {
				return array( 'requires-capability' );
			}
		}

		$definition = $this->get_definition();
		if ( ! empty( $definition['required_capability'] ) ) {
			return array( 'requires-capability' );
		}

		return array();
	}
}
