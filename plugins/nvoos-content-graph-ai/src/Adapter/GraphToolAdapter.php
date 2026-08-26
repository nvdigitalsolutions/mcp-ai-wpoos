<?php
declare(strict_types=1);

namespace NvoosContentGraphAi\Adapter;

use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use NvoosContentGraph\Contracts\Tool as ContentGraphTool;

/**
 * Adapter that exposes a parent-plugin graph tool
 * ({@see \NvoosContentGraph\Contracts\Tool}) through the nvoos/core
 * {@see ToolInterface} contract.
 *
 * The parent plugin registers its 14 graph tools in its own
 * `NvoosContentGraph\ToolRegistry` during the
 * `nvoos_content_graph/register_tools` action. The agentic chat loop
 * (ChatOrchestrator) resolves and executes tools exclusively through
 * the core `ToolRegistry`, so this adapter bridges the two contracts
 * and makes graph tools callable by the LLM.
 *
 * WP_Error results from parent tools are passed through untouched —
 * the WordPress ErrorFactory already recognises WP_Error instances.
 *
 * @package NvoosContentGraphAi
 * @since   1.1.0
 */
class GraphToolAdapter implements ToolInterface, ToolCapabilityFlagsInterface {

	/**
	 * @param ContentGraphTool $inner The wrapped parent-plugin tool.
	 */
	public function __construct(
		private readonly ContentGraphTool $inner,
	) {}

	public function getSlug(): string {
		return $this->inner->getSlug();
	}

	public function getName(): string {
		return $this->inner->getName();
	}

	public function getDescription(): string {
		return $this->inner->getDescription();
	}

	public function getParametersSchema(): array {
		return $this->inner->getParametersSchema();
	}

	public function getRequiredCapability(): string {
		return $this->inner->getRequiredCapability();
	}

	public function getCapabilityFlags(): array {
		return $this->inner->getCapabilityFlags();
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		return $this->inner->execute( $arguments, $context );
	}
}
