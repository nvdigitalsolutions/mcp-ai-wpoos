<?php
/** Generate Gemini Image — Imagen API wrapper. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class GenerateGeminiImageTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'generate_gemini_image'; }
	public function getName(): string { return 'Generate Gemini Image'; }
	public function getDescription(): string { return 'Generates images from text using Google Gemini Imagen.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array(
			'prompt'=>array('type'=>'string','description'=>'Image description.'),
			'model'=>array('type'=>'string','description'=>'Model.','enum'=>array('imagen-4.0-generate-001','imagen-3.0-generate-001'),'default'=>'imagen-4.0-generate-001'),
			'aspect_ratio'=>array('type'=>'string','enum'=>array('1:1','3:4','4:3','9:16','16:9'),'default'=>'1:1'),
			'n'=>array('type'=>'integer','description'=>'Number of images (1-4).','minimum'=>1,'maximum'=>4,'default'=>1),
			'negative_prompt'=>array('type'=>'string','description'=>'What to avoid.'),
		),'required'=>array('prompt'),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'edit_posts'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$prompt = $this->stringParam( $arguments, 'prompt' );
		if ( '' === $prompt ) return $this->errors->validationFailed( 'A prompt is required.', array('prompt'=>array('Required.')) );

		$key = $this->s->getApiKey( 'gemini' );
		if ( null === $key || '' === $key ) return $this->errors->create( 'missing_key', 'No Gemini API key configured.' );

		$model   = $this->stringParam( $arguments, 'model', 'imagen-4.0-generate-001' );
		$ratio   = $this->stringParam( $arguments, 'aspect_ratio', '1:1' );
		$n       = $this->intParam( $arguments, 'n', 1 );
		$neg     = $this->stringParam( $arguments, 'negative_prompt' );

		$inst = array( 'prompt'=>$prompt, 'aspectRatio'=>$ratio, 'sampleCount'=>$n );
		if ( '' !== $neg ) $inst['negativePrompt'] = $neg;

		try {
			$r = $this->h->send( 'POST', "https://generativelanguage.googleapis.com/v1beta/models/{$model}:predict?key={$key}", array('Content-Type'=>'application/json'), \json_encode(array('instances'=>array($inst))) );
			$d = \json_decode( $r->body, true );
			if ( $r->statusCode >= 400 ) { $err = $d['error']['message'] ?? 'Imagen API error.'; return $this->errors->create( 'imagen_error', $err ); }

			$images = $d['predictions'] ?? array();
			return $this->success( 'Image(s) generated successfully.', array( 'prompt'=>$prompt, 'model'=>$model, 'images'=>$images, 'count'=>\count($images) ) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'request_failed', $e->getMessage() ); }
	}
}
