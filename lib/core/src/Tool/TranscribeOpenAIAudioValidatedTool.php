<?php
/**
 * Transcribe OpenAI Audio (Validated) — validated transcription variant.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Transcribe_OpenAI_Audio_Validated.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

class TranscribeOpenAIAudioValidatedTool extends TranscribeOpenAIAudioTool {

	public function __construct(
		\Nvoos\Core\Domain\Contract\ErrorFactoryInterface $errors,
		\Nvoos\Core\Domain\Contract\SettingsStoreInterface $settings,
		\Nvoos\Core\Domain\Contract\HttpClientInterface $http,
	) {
		parent::__construct( $errors, $settings, $http );
	}

	public function getSlug(): string { return 'transcribe_openai_audio_validated'; }
	public function getName(): string { return 'Transcribe OpenAI Audio (Validated)'; }
	public function getDescription(): string { return 'Converts audio into text using OpenAI Whisper with validated arguments.'; }
}
