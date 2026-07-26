<?php
/** Generate OpenAI Image (Validated). @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1);
namespace Nvoos\Core\Tool;
class GenerateOpenAIImageValidatedTool extends GenerateOpenAIImageTool {
	public function getSlug(): string { return 'generate_openai_image_validated'; }
	public function getName(): string { return 'Generate OpenAI Image (Validated)'; }
}
