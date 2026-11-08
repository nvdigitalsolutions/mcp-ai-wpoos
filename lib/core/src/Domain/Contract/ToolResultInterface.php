<?php
/**
 * Tool result contract — the typed alternative to a `mixed` return.
 *
 * Tools that implement this interface return a `ToolResult` instead of an
 * untyped envelope-or-error value. Everything else keeps using
 * ToolInterface::execute(); the two paths are bridged by
 * {@see \Nvoos\Core\Tool\AbstractResultTool}, so the execution pipeline
 * (guards, waterfalls, observers) is identical for both.
 *
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

use Nvoos\Core\Domain\ValueObject\ToolResult;

interface ToolResultInterface {

	/**
	 * Execute the tool and return a typed result.
	 *
	 * Implementations MUST be total: expected failures (missing parameter,
	 * domain rule violation, upstream error) are returned as
	 * `ToolResult::failure()`, not thrown. Exceptions remain reserved for
	 * programmer error.
	 *
	 * @param ToolArgs    $arguments Parsed tool arguments.
	 * @param ToolContext $context   Execution context.
	 * @return ToolResult<mixed>
	 */
	public function executeResult( array $arguments = array(), array $context = array() ): ToolResult;
}
