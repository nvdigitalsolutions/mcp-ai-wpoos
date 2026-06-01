<?php
/**
 * Fired when a tool is registered with the registry.
 *
 * Replaces: do_action('wp_mcp_ai_register_tools', ...)
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Event;

final class ToolsRegistered {

	/**
	 * @param string[] $toolSlugs
	 */
	public function __construct(
		public readonly array $toolSlugs,
	) {}
}
