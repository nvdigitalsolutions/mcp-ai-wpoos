<?php
/**
 * Generate Higgsfield Video — Higgsfield Cinema Studio video generation
 * (simplified HTTP wrapper).
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Generate_Higgsfield_Video.
 * The heavy Media Library saving and status polling live in the WordPress
 * adapter layer; this tool submits the generation request and returns the
 * request handle (request_id + status_url) for the caller to poll.
 *
 * Higgsfield API (https://docs.higgsfield.ai):
 *  - Auth:     `Authorization: Key {KEY_ID}:{KEY_SECRET}`
 *  - Submit:   POST /higgsfield/cinema-studio/4.0
 *  - Status:   GET  /requests/{request_id}/status
 *  - Lifecycle: async; terminal states: completed, failed, nsfw, canceled.
 *
 * The SettingsStore contract returns the combined credential for the
 * 'higgsfield' provider as `{KEY_ID}:{KEY_SECRET}`; this tool splits it
 * on the first colon.
 *
 * @package Nvoos\Core @since 2.0.0 @license MIT
 */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class GenerateHiggsfieldVideoTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'generate_higgsfield_video'; }
	public function getName(): string { return 'Generate Higgsfield Video'; }
	public function getDescription(): string { return 'Generates cinematic video from text using Higgsfield Cinema Studio. Optionally accepts public image reference URLs for image-to-video generation.'; }
	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'prompt'        => array( 'type' => 'string', 'description' => 'Video description.' ),
				'duration'      => array( 'type' => 'integer', 'description' => 'Duration in seconds (4-30).', 'minimum' => 4, 'maximum' => 30, 'default' => 5 ),
				'resolution'    => array( 'type' => 'string', 'enum' => array( '480p', '720p' ), 'default' => '720p' ),
				'aspect_ratio'  => array( 'type' => 'string', 'enum' => array( '16:9', '4:3', '1:1', '3:4', '9:16', '21:9' ), 'default' => '16:9' ),
				'generate_audio' => array( 'type' => 'boolean', 'description' => 'Generate audio with the video.', 'default' => true ),
				'image_urls'    => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'format' => 'uri' ), 'description' => 'Public image reference URLs (max 30).' ),
			),
			'required'   => array( 'prompt' ),
			'additionalProperties' => false,
		);
	}
	public function getRequiredCapability(): string { return 'edit_posts'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$prompt = $this->stringParam( $arguments, 'prompt' );
		if ( '' === $prompt ) return $this->errors->validationFailed( 'A prompt is required.', array( 'prompt' => array( 'Required.' ) ) );

		$credential = $this->s->getApiKey( 'higgsfield' );
		if ( null === $credential || '' === $credential ) return $this->errors->create( 'missing_key', 'No Higgsfield API credentials configured. Provide the combined "KEY_ID:KEY_SECRET" credential.' );

		$parts = \explode( ':', $credential, 2 );
		if ( 2 !== \count( $parts ) || '' === \trim( $parts[0] ) || '' === \trim( $parts[1] ) ) {
			return $this->errors->create( 'invalid_key', 'Invalid Higgsfield credentials. Expected "KEY_ID:KEY_SECRET".' );
		}

		$duration      = $this->intParam( $arguments, 'duration', 5 );
		$duration      = \max( 4, \min( 30, $duration ) );
		$resolution    = $this->stringParam( $arguments, 'resolution', '720p' );
		$aspectRatio   = $this->stringParam( $arguments, 'aspect_ratio', '16:9' );
		$generateAudio = $this->boolParam( $arguments, 'generate_audio', true );
		$imageUrls     = $this->arrayParam( $arguments, 'image_urls' );

		$body = array(
			'prompt'         => $prompt,
			'duration'       => $duration,
			'resolution'     => $resolution,
			'aspect_ratio'   => $aspectRatio,
			'generate_audio' => $generateAudio,
		);

		if ( ! empty( $imageUrls ) ) {
			$body['image_urls'] = \array_values( \array_filter( \array_map( 'strval', $imageUrls ) ) );
		}

		$headers = array(
			'Authorization' => 'Key ' . \trim( $parts[0] ) . ':' . \trim( $parts[1] ),
			'Content-Type'  => 'application/json',
		);

		try {
			$r = $this->h->send( 'POST', 'https://api.higgsfield.ai/higgsfield/cinema-studio/4.0', $headers, \json_encode( $body ) );
			$d = \json_decode( $r->body, true );
			if ( $r->statusCode >= 400 ) {
				$err = $d['detail'] ?? $d['error']['message'] ?? 'Higgsfield API error.';
				return $this->errors->create( 'higgsfield_error', (string) $err );
			}

			return $this->success(
				'Video generation submitted.',
				array(
					'prompt'       => $prompt,
					'duration'     => $duration,
					'resolution'   => $resolution,
					'aspect_ratio' => $aspectRatio,
					'request_id'   => $d['request_id'] ?? null,
					'status_url'   => $d['status_url'] ?? null,
					'status'       => $d['status'] ?? 'queued',
				)
			);
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'request_failed', $e->getMessage() );
		}
	}
}
