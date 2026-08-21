<?php
declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

use Nvoos\Core\Tool\AbstractTool;
use NvoosContentGraph\Contracts\Tool as ContentGraphTool;

/**
 * Base class for AI-powered tools in the Content Graph AI addon.
 *
 * Extends the framework-agnostic AbstractTool from nvoos/core and
 * simultaneously implements the parent plugin's Tool contract for
 * backward compatibility with nvoos-content-graph's ToolRegistry.
 *
 * @since 1.0.0
 */
abstract class AbstractAiTool extends AbstractTool implements ContentGraphTool {

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
