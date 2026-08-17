<?php
/**
 * ToolWriteClassInterface — optional safety classification for tools.
 *
 * Declares whether a tool mutates state (write-class) or only reads.
 * Shadow-mode rollouts use this to suppress write-class execution so a
 * parallel (shadow) engine run can never double-execute destructive or
 * state-changing tools. Tools that do not implement it are classified
 * by their required capability: empty/read/public → read; anything else
 * → write (fail safe for shadow suppression).
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ToolWriteClassInterface {

	/**
	 * Whether this tool mutates state.
	 */
	public function isWriteClass(): bool;
}
