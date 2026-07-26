<?php
/** Edit OpenAI Image — DALL-E Edit API wrapper. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class EditOpenAIImageTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'edit_openai_image'; }
	public function getName(): string { return 'Edit OpenAI Image'; }
	public function getDescription(): string { return 'Edits an existing image using OpenAI DALL-E image editing.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array(
			'image_url'=>array('type'=>'string','description'=>'URL of the image to edit.'),
			'prompt'=>array('type'=>'string','description'=>'Edit description.'),
			'model'=>array('type'=>'string','enum'=>array('dall-e-2'),'default'=>'dall-e-2'),
			'n'=>array('type'=>'integer','description'=>'Number of edited images (1-10).','minimum'=>1,'maximum'=>10,'default'=>1),
			'size'=>array('type'=>'string','enum'=>array('256x256','512x512','1024x1024'),'default'=>'1024x1024'),
		),'required'=>array('image_url','prompt'),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'upload_files'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$imageUrl = $this->stringParam( $arguments, 'image_url' );
		$prompt   = $this->stringParam( $arguments, 'prompt' );
		if ( '' === $imageUrl || '' === $prompt ) return $this->errors->validationFailed( 'image_url and prompt are required.', array('image_url'=>array('Required.'),'prompt'=>array('Required.')) );

		$key = $this->s->getApiKey( 'openai' );
		if ( null === $key || '' === $key ) return $this->errors->create( 'missing_key', 'No OpenAI API key configured.' );

		$n    = $this->intParam( $arguments, 'n', 1 );
		$size = $this->stringParam( $arguments, 'size', '1024x1024' );

		try {
			$r = $this->h->send( 'POST', 'https://api.openai.com/v1/images/edits', array( 'Authorization'=>"Bearer {$key}", 'Content-Type'=>'application/json' ), \json_encode( array( 'image'=>$imageUrl, 'prompt'=>$prompt, 'n'=>$n, 'size'=>$size, 'response_format'=>'b64_json' ) ) );
			$d = \json_decode( $r->body, true );
			if ( $r->statusCode >= 400 ) { $err = $d['error']['message'] ?? 'DALL-E edit error.'; return $this->errors->create( 'dalle_error', $err ); }

			$images = array();
			foreach ( $d['data'] ?? array() as $img ) { $images[] = array( 'b64_json'=>$img['b64_json']??null ); }
			return $this->success( 'Image(s) edited successfully.', array( 'prompt'=>$prompt, 'images'=>$images, 'count'=>\count($images) ) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'request_failed', $e->getMessage() ); }
	}
}
