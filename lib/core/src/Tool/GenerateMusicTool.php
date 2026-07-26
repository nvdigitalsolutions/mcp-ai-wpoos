<?php
/**
 * Generate Music — music generation via Mubert API.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Generate_Music.
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

class GenerateMusicTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SettingsStoreInterface $settings,
		private readonly HttpClientInterface $http,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string { return 'generate_music'; }
	public function getName(): string { return 'Generate Music'; }
	public function getDescription(): string { return 'Generates royalty-free background music from a text description via Mubert API.'; }

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'prompt'   => array( 'type' => 'string', 'description' => 'Description of the desired music.' ),
				'duration' => array( 'type' => 'integer', 'description' => 'Duration in seconds (15-1500).', 'minimum' => 15, 'maximum' => 1500, 'default' => 30 ),
				'genre'    => array( 'type' => 'string', 'description' => 'Optional music genre.' ),
				'mood'     => array( 'type' => 'string', 'description' => 'Optional mood.' ),
			),
			'required'   => array( 'prompt' ),
		);
	}

	public function getRequiredCapability(): string { return 'edit_posts'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$prompt = $this->stringParam( $arguments, 'prompt' );
		if ( '' === $prompt ) {
			return $this->errors->validationFailed( 'No prompt was supplied.', array( 'prompt' => array( 'Prompt is required.' ) ) );
		}

		$apiKey = $this->settings->getApiKey( 'mubert' );
		if ( null === $apiKey || '' === $apiKey ) {
			return $this->errors->create( 'missing_key', 'No Mubert API key configured.' );
		}

		$duration = $this->intParam( $arguments, 'duration', 30 );
		$genre    = $this->stringParam( $arguments, 'genre' );
		$mood     = $this->stringParam( $arguments, 'mood' );

		$body = array(
			'method'   => '4k',
			'params'   => array( 'prompt' => $prompt, 'duration' => $duration ),
		);

		if ( '' !== $genre ) { $body['params']['genre'] = $genre; }
		if ( '' !== $mood )  { $body['params']['mood']  = $mood; }

		try {
			$response = $this->http->send(
				'POST',
				'https://api-b2b.mubert.com/v2/TTMRecordTrack',
				array(
					'Authorization' => "Bearer {$apiKey}",
					'Content-Type'  => 'application/json',
				),
				\json_encode( $body ),
			);

			$data = \json_decode( $response->body, true );

			if ( $response->statusCode >= 400 || ( \is_array( $data ) && ! empty( $data['error'] ) ) ) {
				$errMsg = $data['error']['message'] ?? $data['error'] ?? 'Mubert API error.';
				return $this->errors->create( 'music_error', \is_string( $errMsg ) ? $errMsg : 'Unknown error.' );
			}

			return $this->success(
				'Music generated successfully.',
				array(
					'prompt'      => $prompt,
					'duration'    => $data['duration'] ?? $duration,
					'format'      => $data['format'] ?? 'wav',
					'audio_base64' => $data['audio'] ?? null,
					'provider'    => 'mubert',
				),
			);

		} catch ( \Throwable $e ) {
			return $this->errors->create( 'request_failed', "Music generation failed: {$e->getMessage()}" );
		}
	}
}
