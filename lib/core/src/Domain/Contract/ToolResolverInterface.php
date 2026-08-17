<?php
/**
 * ToolResolverInterface — read surface shared by the tool registry and
 * its scoped views.
 *
 * The orchestrator and presenters resolve tools through this contract so
 * a scoped view can substitute for the global registry without changing
 * any call site. Execution always happens on the owning registry; scopes
 * only shape visibility.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ToolResolverInterface {

	/**
	 * Resolve a tool by slug as this scope sees it.
	 */
	public function get( string $slug ): ?ToolInterface;

	/**
	 * Whether a tool with the given slug is visible.
	 */
	public function has( string $slug ): bool;
}
