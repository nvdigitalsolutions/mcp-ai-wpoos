<?php
/** Create Chart (Validated). @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
class CreateChartValidatedTool extends CreateChartTool {
	public function getSlug(): string { return 'create_chart_validated'; }
	public function getName(): string { return 'Create Chart (Validated)'; }
}
