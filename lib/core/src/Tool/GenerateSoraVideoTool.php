<?php
/**
 * Generate Sora Video — OpenAI Sora video generation (simplified HTTP wrapper).
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Generate_Sora_Video.
 * The heavy Media Library saving lives in the WordPress adapter layer.
 *
 * @package Nvoos\Core @since 2.0.0 @license MIT
 */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class GenerateSoraVideoTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'generate_sora_video'; }
	public function getName(): string { return 'Generate Sora Video'; }
	public function getDescription(): string { return 'Generates video from text using OpenAI Sora.'; }
	public function getParametersSchema(): array { return array( 'type'=>'object','properties'=>array( 'prompt'=>array('type'=>'string','description'=>'Video description.'), 'duration'=>array('type'=>'integer','description'=>'Duration in seconds (4-20).','minimum'=>4,'maximum'=>20,'default'=>5), 'resolution'=>array('type'=>'string','enum'=>array('480p','720p','1080p'),'default'=>'720p') ),'required'=>array('prompt'),'additionalProperties'=>false ); }
	public function getRequiredCapability(): string { return 'edit_posts'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$prompt = $this->stringParam( $arguments, 'prompt' );
		if ( '' === $prompt ) return $this->errors->validationFailed( 'A prompt is required.', array('prompt'=>array('Required.')) );

		$key = $this->s->getApiKey( 'openai' );
		if ( null === $key || '' === $key ) return $this->errors->create( 'missing_key', 'No OpenAI API key configured.' );

		$duration   = $this->intParam( $arguments, 'duration', 5 );
		$resolution = $this->stringParam( $arguments, 'resolution', '720p' );

		try {
			$r = $this->h->send( 'POST', 'https://api.openai.com/v1/video/generations', array( 'Authorization'=>"Bearer {$key}",'Content-Type'=>'application/json' ), \json_encode( array( 'model'=>'sora-2','prompt'=>$prompt,'duration'=>$duration,'resolution'=>$resolution ) ) );
			$d = \json_decode( $r->body, true );
			if ( $r->statusCode >= 400 ) { $err = $d['error']['message'] ?? 'Sora API error.'; return $this->errors->create( 'sora_error', $err ); }

			return $this->success( 'Video generation initiated.', array( 'prompt'=>$prompt, 'duration'=>$duration, 'resolution'=>$resolution, 'job_id'=>$d['id']??null, 'status'=>$d['status']??'queued' ) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'request_failed', $e->getMessage() ); }
	}
}
