<?php
/** Generate Cloudflare AI Image — Cloudflare Workers AI image generation. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class GenerateCloudflareAIImageTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'generate_cloudflareai_image'; }
	public function getName(): string { return 'Generate Cloudflare AI Image'; }
	public function getDescription(): string { return 'Generates images from text using Cloudflare Workers AI models.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array(
			'prompt'=>array('type'=>'string','description'=>'Image description.'),
			'model'=>array('type'=>'string','description'=>'Model.','default'=>'@cf/stabilityai/stable-diffusion-xl-base-1.0'),
			'width'=>array('type'=>'integer','description'=>'Width (256-2048).','minimum'=>256,'maximum'=>2048,'default'=>1024),
			'height'=>array('type'=>'integer','description'=>'Height (256-2048).','minimum'=>256,'maximum'=>2048,'default'=>1024),
			'num_steps'=>array('type'=>'integer','description'=>'Inference steps (1-20).','minimum'=>1,'maximum'=>20,'default'=>20),
			'guidance'=>array('type'=>'number','description'=>'Guidance scale (0-20).','minimum'=>0,'maximum'=>20,'default'=>7.5),
			'seed'=>array('type'=>'integer','description'=>'Optional seed for reproducibility.'),
			'negative_prompt'=>array('type'=>'string','description'=>'What to avoid.'),
		),'required'=>array('prompt'),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'read'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$prompt = $this->stringParam( $arguments, 'prompt' );
		if ( '' === $prompt ) return $this->errors->validationFailed( 'A prompt is required.', array('prompt'=>array('Required.')) );

		$accountId = $this->s->get( 'cloudflare_account_id', '' );
		$apiToken  = $this->s->getApiKey( 'cloudflare' );
		if ( '' === (string)$accountId || null === $apiToken ) return $this->errors->create( 'missing_config', 'Cloudflare account ID or API token not configured.' );

		$model   = $this->stringParam( $arguments, 'model', '@cf/stabilityai/stable-diffusion-xl-base-1.0' );
		$width   = \max(256,\min(2048,$this->intParam($arguments,'width',1024)));
		$height  = \max(256,\min(2048,$this->intParam($arguments,'height',1024)));
		$steps   = \max(1,\min(20,$this->intParam($arguments,'num_steps',20)));
		$guidance = (float)($arguments['guidance']??7.5);
		$neg     = $this->stringParam( $arguments, 'negative_prompt' );
		$seed    = $arguments['seed'] ?? null;

		$body = array( 'prompt'=>$prompt, 'width'=>$width, 'height'=>$height, 'num_steps'=>$steps, 'guidance'=>$guidance );
		if ( '' !== $neg ) $body['negative_prompt'] = $neg;
		if ( null !== $seed ) $body['seed'] = (int)$seed;

		try {
			$r = $this->h->send( 'POST', "https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/{$model}", array('Authorization'=>"Bearer {$apiToken}",'Content-Type'=>'application/json'), \json_encode($body) );
			$d = \json_decode( $r->body, true );
			if ( $r->statusCode >= 400 ) { $err = $d['errors'][0]['message'] ?? 'Cloudflare AI error.'; return $this->errors->create( 'cf_error', $err ); }

			$image = $d['result']['image'] ?? null;
			return $this->success( 'Image generated successfully.', array( 'prompt'=>$prompt, 'model'=>$model, 'image_base64'=>$image, 'width'=>$width, 'height'=>$height ) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'request_failed', $e->getMessage() ); }
	}
}
