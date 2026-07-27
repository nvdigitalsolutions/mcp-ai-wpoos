<?php
/**
 * Generate OpenAI Speech — text-to-speech via OpenAI TTS API.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Generate_OpenAI_Speech.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class GenerateOpenAISpeechTool extends AbstractTool {

	private const VOICES  = array( 'alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer' );
	private const FORMATS = array( 'mp3', 'opus', 'aac', 'flac', 'wav', 'pcm' );
	private const DEFAULT_MODEL = 'tts-1';

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SettingsStoreInterface $settings,
		private readonly HttpClientInterface $http,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string { return 'generate_openai_speech'; }
	public function getName(): string { return 'Generate OpenAI Speech'; }
	public function getDescription(): string { return 'Converts text to speech using the OpenAI TTS API.'; }

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'text'    => array( 'type' => 'string', 'description' => 'The text to convert to speech (max 4096 characters).' ),
				'voice'   => array( 'type' => 'string', 'description' => 'Voice to use.', 'enum' => self::VOICES, 'default' => 'alloy' ),
				'format'  => array( 'type' => 'string', 'description' => 'Audio format.', 'enum' => self::FORMATS, 'default' => 'mp3' ),
				'model'   => array( 'type' => 'string', 'description' => 'TTS model.', 'default' => self::DEFAULT_MODEL ),
				'speed'   => array( 'type' => 'number', 'description' => 'Playback speed (0.25-4.0).', 'minimum' => 0.25, 'maximum' => 4.0, 'default' => 1.0 ),
			),
			'required'   => array( 'text' ),
		);
	}

	public function getRequiredCapability(): string { return 'read'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$text = $this->stringParam( $arguments, 'text' );
		if ( '' === $text ) {
			return $this->errors->validationFailed( 'The text parameter is required.', array( 'text' => array( 'Text is required.' ) ) );
		}

		$apiKey = $this->settings->getApiKey( 'openai' );
		if ( null === $apiKey || '' === $apiKey ) {
			return $this->errors->create( 'missing_key', 'No OpenAI API key configured.' );
		}

		$voice  = $this->stringParam( $arguments, 'voice', 'alloy' );
		$format = $this->stringParam( $arguments, 'format', 'mp3' );
		$model  = $this->stringParam( $arguments, 'model', self::DEFAULT_MODEL );
		$speed  = (float) ( $arguments['speed'] ?? 1.0 );

		$body = \json_encode( array(
			'model'           => $model,
			'input'           => $text,
			'voice'           => $voice,
			'response_format' => $format,
			'speed'           => $speed,
		) );

		try {
			$response = $this->http->send(
				'POST',
				'https://api.openai.com/v1/audio/speech',
				array(
					'Authorization' => "Bearer {$apiKey}",
					'Content-Type'  => 'application/json',
				),
				$body,
			);

			if ( $response->statusCode >= 400 ) {
				$data   = \json_decode( $response->body, true );
				$errMsg = $data['error']['message'] ?? 'OpenAI TTS API error.';
				return $this->errors->create( 'tts_error', $errMsg );
			}

			$audioBase64 = \base64_encode( $response->body );

			return $this->success(
				'Speech generated successfully.',
				array(
					'text'        => $text,
					'voice'       => $voice,
					'format'      => $format,
					'model'       => $model,
					'audio_base64' => $audioBase64,
					'audio_bytes'  => \strlen( $response->body ),
				),
			);

		} catch ( \Throwable $e ) {
			return $this->errors->create( 'request_failed', "TTS request failed: {$e->getMessage()}" );
		}
	}
}
