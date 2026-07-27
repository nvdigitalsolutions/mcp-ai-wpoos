<?php
/** Web Search (Validated). @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
class WebSearchValidatedTool extends WebSearchTool {
	public function getSlug(): string { return 'web_search_validated'; }
	public function getName(): string { return 'Web Search (Validated)'; }
}
