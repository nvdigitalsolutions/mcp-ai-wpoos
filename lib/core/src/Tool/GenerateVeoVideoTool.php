<?php
/**
 * Generate Veo Video — Google Veo video generation (simplified HTTP wrapper).
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Generate_Veo_Video.
 *
 * @package Nvoos\Core @since 2.0.0 @license MIT
 */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class GenerateVeoVideoTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'generate_veo_video'; }
	public function getName(): string { return 'Generate Veo Video'; }
	public function getDescription(): string { return 'Generates video from text using Google Veo via Gemini API.'; }
	public function getParametersSchema(): array { return array( 'type'=>'object','properties'=>array( 'prompt'=>array('type'=>'string','description'=>'Video description.'), 'duration'=>array('type'=>'integer','description'=>'Duration (4-8).','minimum'=>4,'maximum'=>8,'default'=>5), 'aspect_ratio'=>array('type'=>'string','enum'=>array('16:9','9:16','1:1'),'default'=>'16:9') ),'required'=>array('prompt'),'additionalProperties'=>false ); }
	public function getRequiredCapability(): string { return 'edit_posts'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$prompt = $this->stringParam( $arguments, 'prompt' );
		if ( '' === $prompt ) return $this->errors->validationFailed( 'A prompt is required.', array('prompt'=>array('Required.')) );

		$key = $this->s->getApiKey( 'gemini' );
		if ( null === $key || '' === $key ) return $this->errors->create( 'missing_key', 'No Gemini API key configured.' );

		$duration    = $this->intParam( $arguments, 'duration', 5 );
		$aspectRatio = $this->stringParam( $arguments, 'aspect_ratio', '16:9' );

		try {
			$r = $this->h->send( 'POST', "https://generativelanguage.googleapis.com/v1beta/models/veo-2.0-generate-preview:predict?key={$key}", array( 'Content-Type'=>'application/json' ), \json_encode( array( 'instances'=>array( array( 'prompt'=>$prompt,'duration'=>$duration,'aspect_ratio'=>$aspectRatio ) ) ) ) );
			$d = \json_decode( $r->body, true );
			if ( $r->statusCode >= 400 ) { $err = $d['error']['message'] ?? 'Veo API error.'; return $this->errors->create( 'veo_error', $err ); }

			return $this->success( 'Video generation initiated.', array( 'prompt'=>$prompt, 'duration'=>$duration, 'aspect_ratio'=>$aspectRatio, 'job_id'=>$d['name']??null, 'status'=>'queued' ) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'request_failed', $e->getMessage() ); }
	}
}
