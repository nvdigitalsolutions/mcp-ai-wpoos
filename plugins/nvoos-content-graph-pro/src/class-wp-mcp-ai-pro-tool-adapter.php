<?php
/**
 * Adapter exposing a WP-style Pro tool through the ecosystem graph Tool
 * contract (standalone-only — no monolith counterpart; documented new class).
 *
 * The ported Pro tools keep the base addon's `get_slug()` /
 * `get_definition()` / `execute()` shape (byte-identical classes). The
 * standalone ecosystem registers tools through
 * `NvoosContentGraph\Contracts\Tool`, so this adapter bridges the two
 * contracts the same way `NvoosContentGraphAi\Adapter\GraphToolAdapter`
 * bridges the graph registry into the nvoos/core registry.
 *
 * WP_Error results pass through untouched; the canonical envelope rule is
 * the inner tool's own responsibility (byte-identical with the monolith).
 *
 * @package NvoosContentGraphPro
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter from a WP_MCP_AI-style tool to the graph Tool contract.
 */
class WP_MCP_AI_Pro_Tool_Adapter implements \NvoosContentGraph\Contracts\Tool {

	/**
	 * Wrapped WP-style tool.
	 *
	 * @var object
	 */
	private $inner;

	/**
	 * Constructor.
	 *
	 * @param object $inner WP-style tool with get_slug()/get_definition()/execute().
	 */
	public function __construct( $inner ) {
		$this->inner = $inner;
	}

	/**
	 * Unique tool slug (byte-identical with the inner tool).
	 *
	 * @return string
	 */
	public function getSlug(): string {
		return (string) $this->inner->get_slug();
	}

	/**
	 * Human-readable tool name.
	 *
	 * @return string
	 */
	public function getName(): string {
		$definition = $this->inner->get_definition();
		return isset( $definition['name'] ) ? (string) $definition['name'] : $this->getSlug();
	}

	/**
	 * Tool description.
	 *
	 * @return string
	 */
	public function getDescription(): string {
		$definition = $this->inner->get_definition();
		return isset( $definition['description'] ) ? (string) $definition['description'] : '';
	}

	/**
	 * JSON Schema of the tool's input parameters.
	 *
	 * @return array<string,mixed>
	 */
	public function getParametersSchema(): array {
		$definition = $this->inner->get_definition();
		return isset( $definition['input_schema'] ) && is_array( $definition['input_schema'] ) ? $definition['input_schema'] : array();
	}

	/**
	 * Required WordPress capability (from the definition, manage_options for
	 * the ported Pro tools).
	 *
	 * @return string
	 */
	public function getRequiredCapability(): string {
		$definition = $this->inner->get_definition();
		return isset( $definition['required_capability'] ) ? (string) $definition['required_capability'] : 'manage_options';
	}

	/**
	 * Capability flags (read-only unless the definition marks destructive).
	 *
	 * @return string[]
	 */
	public function getCapabilityFlags(): array {
		$flags = array( 'read-only' );
		if ( method_exists( $this->inner, 'is_destructive' ) && $this->inner->is_destructive() ) {
			$flags[] = 'destructive';
		}
		return $flags;
	}

	/**
	 * Execute the inner tool (canonical envelope or WP_Error passthrough).
	 *
	 * @param array<string,mixed> $arguments Validated tool arguments.
	 * @param array<string,mixed> $context   Execution context.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		return $this->inner->execute( $arguments, $context );
	}
}
