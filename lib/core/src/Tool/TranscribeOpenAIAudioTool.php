<?php
/**
 * Transcribe OpenAI Audio — speech-to-text via OpenAI Whisper API.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Transcribe_OpenAI_Audio.
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

class TranscribeOpenAIAudioTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SettingsStoreInterface $settings,
		private readonly HttpClientInterface $http,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string { return 'transcribe_openai_audio'; }
	public function getName(): string { return 'Transcribe OpenAI Audio'; }
	public function getDescription(): string { return 'Converts an audio file into text using OpenAI Whisper transcription.'; }

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'audio_url'       => array( 'type' => 'string', 'description' => 'URL of the audio file to transcribe.' ),
				'attachment_id'   => array( 'type' => 'integer', 'description' => 'WordPress Media Library attachment ID (platform adapter resolves to URL).', 'minimum' => 1 ),
				'translate'       => array( 'type' => 'boolean', 'description' => 'Translate to English instead of transcribing.', 'default' => false ),
				'model'           => array( 'type' => 'string', 'description' => 'Whisper model. Default: whisper-1.', 'default' => 'whisper-1' ),
				'response_format' => array( 'type' => 'string', 'description' => 'Response format.', 'enum' => array( 'json', 'text', 'srt', 'verbose_json', 'vtt' ), 'default' => 'json' ),
				'language'        => array( 'type' => 'string', 'description' => 'Optional: ISO 639-1 language code.' ),
				'prompt'          => array( 'type' => 'string', 'description' => 'Optional context prompt to guide transcription.' ),
			),
		);
	}

	public function getRequiredCapability(): string { return 'read'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$apiKey = $this->settings->getApiKey( 'openai' );
		if ( null === $apiKey || '' === $apiKey ) {
			return $this->errors->create( 'missing_key', 'No OpenAI API key configured.' );
		}

		$audioUrl     = $this->stringParam( $arguments, 'audio_url' );
		$attachmentId = $this->intParam( $arguments, 'attachment_id' );
		$translate    = ! empty( $arguments['translate'] );
		$model        = $this->stringParam( $arguments, 'model', 'whisper-1' );
		$format       = $this->stringParam( $arguments, 'response_format', 'json' );
		$language     = $this->stringParam( $arguments, 'language' );
		$prompt       = $this->stringParam( $arguments, 'prompt' );

		if ( '' === $audioUrl && $attachmentId <= 0 ) {
			return $this->errors->validationFailed(
				'You must supply an audio URL or attachment ID.',
				array( 'audio_url' => array( 'Audio source is required.' ) ),
			);
		}

		$endpoint = $translate
			? 'https://api.openai.com/v1/audio/translations'
			: 'https://api.openai.com/v1/audio/transcriptions';

		$formFields = array(
			array( 'name' => 'model', 'contents' => $model ),
			array( 'name' => 'response_format', 'contents' => $format ),
		);

		if ( '' !== $language ) {
			$formFields[] = array( 'name' => 'language', 'contents' => $language );
		}
		if ( '' !== $prompt ) {
			$formFields[] = array( 'name' => 'prompt', 'contents' => $prompt );
		}

		// Build multipart form for file upload.
		$boundary = '----WhisperBoundary' . \bin2hex( \random_bytes( 8 ) );
		$body     = '';

		foreach ( $formFields as $field ) {
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"{$field['name']}\"\r\n\r\n";
			$body .= "{$field['contents']}\r\n";
		}

		// If audio_url provided, fetch the audio first then attach.
		if ( '' !== $audioUrl ) {
			try {
				$audioResp = $this->http->send( 'GET', $audioUrl );
				if ( $audioResp->statusCode >= 400 ) {
					return $this->errors->create( 'fetch_failed', "Failed to fetch audio from URL (HTTP {$audioResp->statusCode})." );
				}

				$body .= "--{$boundary}\r\n";
				$body .= "Content-Disposition: form-data; name=\"file\"; filename=\"audio.mp3\"\r\n";
				$body .= "Content-Type: audio/mpeg\r\n\r\n";
				$body .= $audioResp->body . "\r\n";

			} catch ( \Throwable $e ) {
				return $this->errors->create( 'fetch_failed', "Failed to fetch audio: {$e->getMessage()}" );
			}
		}

		$body .= "--{$boundary}--\r\n";

		try {
			$response = $this->http->send(
				'POST',
				$endpoint,
				array(
					'Authorization' => "Bearer {$apiKey}",
					'Content-Type'  => "multipart/form-data; boundary={$boundary}",
				),
				$body,
			);

			if ( $response->statusCode >= 400 ) {
				$data   = \json_decode( $response->body, true );
				$errMsg = $data['error']['message'] ?? "Whisper API error (HTTP {$response->statusCode}).";
				return $this->errors->create( 'transcription_error', $errMsg );
			}

			$text = 'json' === $format ? ( \json_decode( $response->body, true )['text'] ?? $response->body ) : $response->body;

			return $this->success(
				'Audio transcribed successfully.',
				array(
					'text'   => \is_string( $text ) ? $text : \json_encode( $text ),
					'model'  => $model,
					'format' => $format,
				),
			);

		} catch ( \Throwable $e ) {
			return $this->errors->create( 'request_failed', "Transcription request failed: {$e->getMessage()}" );
		}
	}
}
