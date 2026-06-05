<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Tools;

use NvoosGraphify\Contracts\Tool;

/**
 * Base class for AI-powered tools in the Graphify AI addon.
 *
 * Provides access to the provider registry and common helpers.
 *
 * @since 1.0.0
 */
abstract class AbstractAiTool implements Tool {

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array( 'read-only', 'external-api' );
	}
}
