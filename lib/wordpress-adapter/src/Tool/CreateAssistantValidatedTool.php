<?php
/**
 * WordPress-specific tool: Create AI Assistant (Validated).
 *
 * Thin validated wrapper around CreateAssistantTool — same pattern
 * as other validated variants (extends base, overrides slug/name).
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Tool;

class CreateAssistantValidatedTool extends CreateAssistantTool {

	public function getSlug(): string {
		return 'create_assistant_validated';
	}

	public function getName(): string {
		return 'Create AI Assistant (Validated)';
	}
}
