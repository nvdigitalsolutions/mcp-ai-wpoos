<?php
/**
 * Legacy tool resolver — adapts the WP-side tool registry to the OOS
 * ToolResolverInterface so compositions can scope the full ~1,500-tool
 * legacy surface (Proposal 029, Phase 5.2).
 *
 * This resolver is deliberately NOT enumerable (the legacy registry
 * exposes thousands of tool objects with no shared Nvoos\Core contract).
 * Consumers that need schema projection pass a seed slug universe into
 * ToolScope (see ToolScope's $seed_slugs), which resolves each seeded
 * slug through this resolver.
 *
 * Each legacy tool is wrapped once per request in a LegacyToolAdapter
 * (duck-typed anti-corruption layer from Phase 1) and cached by slug.
 *
 * When the OOS engine owns the tool registry outright (Phase 6), this
 * resolver swaps for the registry itself — no consumer changes.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Composition
 * @since   1.1.57
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ToolResolverInterface over the legacy WP_MCP_AI tool registry.
 *
 * @since 1.1.57
 */
class WP_MCP_AI_Pro_Legacy_Tool_Resolver implements \Nvoos\Core\Domain\Contract\ToolResolverInterface {

	/**
	 * Adapted-tool cache keyed by slug.
	 *
	 * @var array<string, \Nvoos\Core\Domain\Contract\ToolInterface>
	 */
	private $adapter_cache = array();

	/**
	 * Resolve a tool by slug as an adapted Nvoos\Core tool.
	 *
	 * @param string $slug Tool slug.
	 * @return \Nvoos\Core\Domain\Contract\ToolInterface|null
	 */
	public function get( string $slug ): ?\Nvoos\Core\Domain\Contract\ToolInterface {
		if ( isset( $this->adapter_cache[ $slug ] ) ) {
			return $this->adapter_cache[ $slug ];
		}

		$legacy = $this->legacy_tool( $slug );

		if ( null === $legacy ) {
			return null;
		}

		if ( ! class_exists( 'Nvoos\WordPress\Tool\LegacyToolAdapter' ) ) {
			return null;
		}

		$adapter = new \Nvoos\WordPress\Tool\LegacyToolAdapter(
			$legacy,
			new \Nvoos\WordPress\Adapter\ErrorFactory()
		);

		$this->adapter_cache[ $slug ] = $adapter;

		return $adapter;
	}

	/**
	 * Whether a tool with the given slug exists in the legacy registry.
	 *
	 * @param string $slug Tool slug.
	 * @return bool
	 */
	public function has( string $slug ): bool {
		return null !== $this->legacy_tool( $slug );
	}

	/**
	 * Every slug registered in the legacy registry.
	 *
	 * @return string[]
	 */
	public function all_slugs(): array {
		$registry = $this->legacy_registry();

		if ( null === $registry ) {
			return array();
		}

		$slugs = array();

		foreach ( $registry->get_all_tools() as $tool ) {
			if ( is_object( $tool ) && method_exists( $tool, 'get_slug' ) ) {
				$slugs[] = (string) $tool->get_slug();
			}
		}

		sort( $slugs );

		return $slugs;
	}

	/**
	 * Fetch the raw legacy tool object by slug.
	 *
	 * @param string $slug Tool slug.
	 * @return object|null
	 */
	private function legacy_tool( string $slug ) {
		$registry = $this->legacy_registry();

		if ( null === $registry || ! method_exists( $registry, 'get_tool' ) ) {
			return null;
		}

		$tool = $registry->get_tool( $slug );

		return is_object( $tool ) ? $tool : null;
	}

	/**
	 * The legacy tool registry singleton, when present.
	 *
	 * @return WP_MCP_AI_Tool_Registry|null
	 */
	private function legacy_registry() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return null;
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		return $registry instanceof WP_MCP_AI_Tool_Registry ? $registry : null;
	}
}
