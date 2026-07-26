<?php
/**
 * Generate Omni Video — Gemini Omni Flash video generation (simplified HTTP wrapper).
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Generate_Omni_Video.
 *
 * @package Nvoos\Core @since 2.0.0 @license MIT
 */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class GenerateOmniVideoTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'generate_omni_video'; }
	public function getName(): string { return 'Generate Omni Video'; }
	public function getDescription(): string { return 'Generates videos from text using Google Gemini Omni Flash with optional reference images/video/audio.'; }
	public function getParametersSchema(): array {
		return array( 'type'=>'object','properties'=>array(
			'prompt'=>array('type'=>'string','description'=>'Detailed video description.'),
			'duration'=>array('type'=>'integer','description'=>'Duration in seconds (4-10).','minimum'=>4,'maximum'=>10,'default'=>5),
			'aspect_ratio'=>array('type'=>'string','enum'=>array('1:1','2:3','3:2','16:9','9:16'),'default'=>'16:9'),
			'resolution'=>array('type'=>'string','enum'=>array('720p','1080p'),'default'=>'720p'),
			'negative_prompt'=>array('type'=>'string','description'=>'What to avoid.'),
			'style'=>array('type'=>'string','enum'=>array('cinematic','realistic','anime','documentary','artistic','none'),'default'=>'none'),
			'async'=>array('type'=>'boolean','description'=>'Run asynchronously.','default'=>false),
		),'required'=>array('prompt'),'additionalProperties'=>false );
	}
	public function getRequiredCapability(): string { return 'edit_posts'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$prompt = $this->stringParam( $arguments, 'prompt' );
		if ( '' === $prompt ) return $this->errors->validationFailed( 'A prompt is required.', array('prompt'=>array('Required.')) );

		$key = $this->s->getApiKey( 'gemini' );
		if ( null === $key || '' === $key ) return $this->errors->create( 'missing_key', 'No Gemini API key configured.' );

		$duration    = $this->intParam( $arguments, 'duration', 5 );
		$aspectRatio = $this->stringParam( $arguments, 'aspect_ratio', '16:9' );
		$resolution  = $this->stringParam( $arguments, 'resolution', '720p' );
		$style       = $this->stringParam( $arguments, 'style', 'none' );
		$negPrompt   = $this->stringParam( $arguments, 'negative_prompt' );
		$async       = ! empty( $arguments['async'] );

		$body = array( 'prompt'=>$prompt, 'duration'=>$duration, 'aspect_ratio'=>$aspectRatio, 'resolution'=>$resolution );
		if ( 'none' !== $style ) $body['style'] = $style;
		if ( '' !== $negPrompt ) $body['negative_prompt'] = $negPrompt;
		if ( $async ) $body['async'] = true;

		try {
			$r = $this->h->send( 'POST', "https://generativelanguage.googleapis.com/v1beta/models/gemini-omni-flash:predict?key={$key}", array( 'Content-Type'=>'application/json' ), \json_encode( array( 'instances'=>array($body) ) ) );
			$d = \json_decode( $r->body, true );
			if ( $r->statusCode >= 400 ) { $err = $d['error']['message'] ?? 'Omni API error.'; return $this->errors->create( 'omni_error', $err ); }

			return $this->success( 'Video generation initiated.', array( 'prompt'=>$prompt, 'duration'=>$duration, 'aspect_ratio'=>$aspectRatio, 'job_id'=>$d['name']??null, 'status'=>$async?'queued':'processing' ) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'request_failed', $e->getMessage() ); }
	}
}
