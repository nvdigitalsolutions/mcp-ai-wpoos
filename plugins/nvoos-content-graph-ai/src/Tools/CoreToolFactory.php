<?php
/**
 * D8 Cluster 1 — core tool registration factory.
 *
 * Instantiates the pre-ported nvoos/core (and wordpress-adapter) tool
 * classes from {@see CoreToolManifest} with their WordPress adapter
 * dependencies and registers them into the nvoos-core registry owned by
 * {@see CoreBridge}. Standalone-only: in monolith installs the base
 * plugin's own registry serves the same slugs, so registering here would
 * double-surface the toolset.
 *
 * Registration is additive and defensive: slugs already present in the
 * registry (AI tools, graph tools) are never overridden, and classes
 * that cannot be resolved are skipped rather than fatalling the boot.
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

use NvoosContentGraphAi\CoreBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the portable core tool inventory.
 *
 * @since 1.0.4
 */
final class CoreToolFactory {

	/**
	 * Register every manifest entry into the bridge's tool registry.
	 *
	 * @param CoreBridge $bridge Bridge owning the nvoos-core registry.
	 * @return int Number of tools newly registered (0 in monolith mode).
	 */
	public static function register( CoreBridge $bridge ): int {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base registry owns the same surface.
			return 0;
		}

		$adapters   = self::build_adapters( $bridge );
		$registered = 0;

		foreach ( CoreToolManifest::manifest() as $slug => $spec ) {
			$class = $spec[0];

			if ( ! class_exists( $class ) ) {
				continue;
			}
			if ( $bridge->tools->has( $slug ) ) {
				// Never override AI tools or bridged graph tools.
				continue;
			}

			$args = array();
			foreach ( $spec[1] as $dependency ) {
				$args[] = $adapters[ $dependency ];
			}

			$bridge->tools->register( new $class( ...$args ) );
			++$registered;
		}

		if ( $registered > 0 ) {
			$bridge->tools->notifyRegistered();
		}

		return $registered;
	}

	/**
	 * Build the shared adapter instances keyed by manifest dependency key.
	 *
	 * Adapters are cheap, constructor-parameterless WordPress bridges;
	 * they degrade gracefully (documented per-adapter) when the feature
	 * they wrap is absent from the runtime.
	 *
	 * @param CoreBridge $bridge Bridge holding the pre-built shared services.
	 * @return array<string,object>
	 */
	private static function build_adapters( CoreBridge $bridge ): array {
		return array(
			'errors'     => $bridge->errors,
			'settings'   => $bridge->settings,
			'http'       => $bridge->http,
			'events'     => $bridge->events,
			'cache'      => new \Nvoos\WordPress\Adapter\CacheStore(),
			'content'    => new \Nvoos\WordPress\Adapter\ContentStore(),
			'memory'     => new \Nvoos\WordPress\Adapter\MemoryStore(),
			'files'      => new \Nvoos\WordPress\Adapter\FileStore(),
			'queue'      => new \Nvoos\WordPress\Adapter\QueueClient(),
			'auth'       => new \Nvoos\WordPress\Adapter\AuthProvider(),
			'agent'      => new \Nvoos\WordPress\Adapter\AgentOrchestration(),
			'img'        => new \Nvoos\WordPress\Adapter\ImageProcessing(),
			'erlang'     => new \Nvoos\Core\Domain\Service\Optimization\ErlangC(),
			'profession' => new \Nvoos\WordPress\Adapter\ProfessionRepository(),
			'skills'     => new \Nvoos\Core\Application\Skill\SkillRegistry(),
			'email'      => new \Nvoos\WordPress\Adapter\EmailService(),
			'schema'     => new \Nvoos\WordPress\Adapter\SchemaStore(),
		);
	}
}
