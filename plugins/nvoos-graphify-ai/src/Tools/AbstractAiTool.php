<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Tools;

use Nvoos\Core\Tool\AbstractTool;
use NvoosGraphify\Contracts\Tool as GraphifyTool;

/**
 * Base class for AI-powered tools in the Graphify AI addon.
 *
 * Extends the framework-agnostic AbstractTool from nvoos/core and
 * simultaneously implements the parent plugin's Tool contract for
 * backward compatibility with nvoos-graphify's ToolRegistry.
 *
 * @since 1.0.0
 */
abstract class AbstractAiTool extends AbstractTool implements GraphifyTool {

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	/**
	 * Capability flags for the parent plugin's tool system.
	 *
	 * @return string[]
	 */
	public function getCapabilityFlags(): array {
		return array( 'read-only', 'external-api' );
	}
}
