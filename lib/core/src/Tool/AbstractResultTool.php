<?php
/**
 * Abstract result tool — base class for tools that return a typed result.
 *
 * Bridges the typed `ToolResultInterface` surface onto the untyped
 * `ToolInterface` contract so a tool can be written purely, while the
 * registry, guards, waterfalls, and observers keep working unchanged.
 *
 * A subclass implements exactly one method:
 *
 *     public function executeResult( array $arguments = array(), array $context = array() ): ToolResult
 *
 * and inherits a final `execute()` that converts the result to the legacy
 * surface (envelope array on success, platform error object on failure).
 *
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ToolResultInterface;
use Nvoos\Core\Domain\ValueObject\ToolResult;

abstract class AbstractResultTool extends AbstractTool implements ToolResultInterface {

	/**
	 * Execute the tool and return the legacy surface.
	 *
	 * Final on purpose: the conversion from `ToolResult` to the untyped
	 * envelope/error shape must be identical for every typed tool, or the
	 * canonical-envelope contract stops being checkable.
	 *
	 * @param ToolArgs    $arguments Parsed tool arguments.
	 * @param ToolContext $context   Execution context.
	 * @return mixed Envelope array on success, platform error on failure.
	 */
	final public function execute( array $arguments = array(), array $context = array() ): mixed {
		return $this->unwrapResult( $this->executeResult( $arguments, $context ) );
	}

	/**
	 * Execute the tool and return a typed result.
	 *
	 * @param ToolArgs    $arguments Parsed tool arguments.
	 * @param ToolContext $context   Execution context.
	 * @return ToolResult<mixed>
	 */
	abstract public function executeResult( array $arguments = array(), array $context = array() ): ToolResult;
}
