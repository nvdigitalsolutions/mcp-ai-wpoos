<?php
declare(strict_types=1);

namespace NvoosGraphify\Tools;

use NvoosGraphify\Contracts\Tool;

/**
 * Abstract base class for graphify tools.
 *
 * Provides a default `getRequiredCapability()` implementation
 * that returns 'edit_posts'. Individual tools can override this
 * for elevated requirements (e.g., 'manage_options').
 *
 * @since 1.0.0
 */
abstract class AbstractTool implements Tool {

	/**
	 * Return the WordPress capability required to execute this tool.
	 *
	 * Defaults to 'edit_posts'. Override for tools that need
	 * 'manage_options' or other capabilities.
	 *
	 * @return string
	 */
	public function getRequiredCapability(): string {
		return 'edit_posts';
	}
}
