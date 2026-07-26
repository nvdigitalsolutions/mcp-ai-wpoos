<?php
/**
 * Generate Music (Validated) — validated music generation variant.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Generate_Music_Validated.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

class GenerateMusicValidatedTool extends GenerateMusicTool {

	public function __construct(
		\Nvoos\Core\Domain\Contract\ErrorFactoryInterface $errors,
		\Nvoos\Core\Domain\Contract\SettingsStoreInterface $settings,
		\Nvoos\Core\Domain\Contract\HttpClientInterface $http,
	) {
		parent::__construct( $errors, $settings, $http );
	}

	public function getSlug(): string { return 'generate_music_validated'; }
	public function getName(): string { return 'Generate Music (Validated)'; }
	public function getDescription(): string { return 'Generates instrumental music from a text description with validated arguments.'; }
}
