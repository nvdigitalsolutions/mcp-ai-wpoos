<?php
/** Edit Gemini Image. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class EditGeminiImageTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'edit_gemini_image'; }
	public function getName(): string { return 'Edit Gemini Image'; }
	public function getDescription(): string { return 'Edits an existing image using Google Gemini Imagen editing.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array( 'image_url'=>array('type'=>'string','description'=>'URL of the image to edit.'), 'prompt'=>array('type'=>'string','description'=>'Edit instructions.') ),'required'=>array('image_url','prompt'),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'upload_files'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$imageUrl = $this->stringParam( $arguments, 'image_url' );
		$prompt   = $this->stringParam( $arguments, 'prompt' );
		if ( '' === $imageUrl || '' === $prompt ) return $this->errors->validationFailed( 'image_url and prompt are required.', array('image_url'=>array('Required.'),'prompt'=>array('Required.')) );

		$key = $this->s->getApiKey( 'gemini' );
		if ( null === $key || '' === $key ) return $this->errors->create( 'missing_key', 'No Gemini API key configured.' );

		try {
			$r = $this->h->send( 'POST', "https://generativelanguage.googleapis.com/v1beta/models/imagen-4.0-edit-001:predict?key={$key}", array('Content-Type'=>'application/json'), \json_encode(array('instances'=>array(array('image'=>$imageUrl,'prompt'=>$prompt)))) );
			$d = \json_decode( $r->body, true );
			if ( $r->statusCode >= 400 ) { $err = $d['error']['message'] ?? 'Imagen edit error.'; return $this->errors->create( 'imagen_error', $err ); }

			$images = $d['predictions'] ?? array();
			return $this->success( 'Image edited successfully.', array( 'prompt'=>$prompt, 'images'=>$images, 'count'=>\count($images) ) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'request_failed', $e->getMessage() ); }
	}
}
