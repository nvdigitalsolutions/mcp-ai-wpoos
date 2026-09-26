<?php
/**
 * Generate Higgsfield Image — Higgsfield SOUL image workflows
 * (simplified HTTP wrapper).
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Generate_Higgsfield_Image.
 * Submits to the verified SOUL endpoints and returns the request handle;
 * polling and Media Library saving live in the WordPress adapter layer.
 *
 * @package Nvoos\Core @since 2.0.0 @license MIT
 */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class GenerateHiggsfieldImageTool extends AbstractTool {
	private const MODEL_ENDPOINTS = array(
		'soul-2'      => '/higgsfield-ai/soul/v2/standard',
		'soul-cinema' => '/higgsfield-ai/soul/cinema',
	);

	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'generate_higgsfield_image'; }
	public function getName(): string { return 'Generate Higgsfield Image'; }
	public function getDescription(): string { return 'Generates styled images using the Higgsfield SOUL workflows (soul-2, soul-cinema).'; }
	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'prompt'         => array( 'type' => 'string', 'description' => 'Image description.' ),
				'model'          => array( 'type' => 'string', 'enum' => array( 'soul-2', 'soul-cinema' ), 'default' => 'soul-2' ),
				'aspect_ratio'   => array( 'type' => 'string', 'enum' => array( '9:16', '16:9', '4:3', '3:4', '1:1', '2:3', '3:2' ), 'default' => '1:1' ),
				'resolution'     => array( 'type' => 'string', 'enum' => array( '720p', '1080p' ), 'default' => '720p' ),
				'enhance_prompt' => array( 'type' => 'boolean', 'default' => false ),
				'batch_size'     => array( 'type' => 'integer', 'enum' => array( 1, 4 ), 'default' => 1 ),
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

		$model        = $this->stringParam( $arguments, 'model', 'soul-2' );
		$endpoint     = self::MODEL_ENDPOINTS[ $model ] ?? self::MODEL_ENDPOINTS['soul-2'];
		$aspectRatio  = $this->stringParam( $arguments, 'aspect_ratio', '1:1' );
		$resolution   = $this->stringParam( $arguments, 'resolution', '720p' );
		$enhance      = $this->boolParam( $arguments, 'enhance_prompt', false );
		$batchSize    = $this->intParam( $arguments, 'batch_size', 1 );

		$body = array(
			'prompt'         => $prompt,
			'aspect_ratio'   => $aspectRatio,
			'resolution'     => $resolution,
			'enhance_prompt' => $enhance,
			'batch_size'     => $batchSize,
		);

		$headers = array(
			'Authorization' => 'Key ' . \trim( $parts[0] ) . ':' . \trim( $parts[1] ),
			'Content-Type'  => 'application/json',
		);

		try {
			$r = $this->h->send( 'POST', 'https://api.higgsfield.ai' . $endpoint, $headers, \json_encode( $body ) );
			$d = \json_decode( $r->body, true );
			if ( $r->statusCode >= 400 ) {
				$err = $d['detail'] ?? 'Higgsfield API error.';
				return $this->errors->create( 'higgsfield_error', (string) $err );
			}

			return $this->success(
				'Image generation submitted.',
				array(
					'model'      => $model,
					'request_id' => $d['request_id'] ?? null,
					'status_url' => $d['status_url'] ?? null,
					'status'     => $d['status'] ?? 'queued',
				)
			);
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'request_failed', $e->getMessage() );
		}
	}
}
